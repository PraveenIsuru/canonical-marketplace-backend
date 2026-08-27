<?php

declare(strict_types=1);

namespace App\Services\Attach;

use App\Contracts\AiProvider;
use App\Exceptions\ApiException;
use App\Jobs\IndexProduct;
use App\Models\Attachment;
use App\Models\AttachSession;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use App\Models\Variant;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ProductDraft;
use App\Services\Catalogue\ProductVersionService;
use App\Services\Catalogue\VariantGenerationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The listing wizard: creating a canonical product that does not exist yet.
 *
 * Reached only when matching returned nothing. Where the catalogue already holds the
 * product, the seller goes through confirmation and peer review instead, and the two
 * paths must never be confused: this one writes a product directly, with no review,
 * because there are no attached sellers to review it.
 *
 * The submit is one transaction over six tables. It is written as a service rather
 * than as controller code because a partial result here is unrecoverable. There is no
 * product deletion path anywhere in the platform, so a product left holding attributes
 * but no variants would sit in the catalogue permanently, unusable and unremovable.
 */
final class ProductWizardService
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly VariantGenerationService $variants,
        private readonly ProductVersionService $versions,
    ) {}

    /**
     * Opens a wizard session and returns the questions to put to the seller.
     *
     * The questions are stored rather than handed over and forgotten. A client that
     * supplied both the questions and the answers could claim it had answered all of
     * them, and the session also has to survive a browser restart, because a queued job
     * may finish while the seller is away.
     *
     * @throws AiUnavailable when the provider cannot answer
     */
    public function startSession(Store $store, ProductDraft $draft): AttachSession
    {
        $questions = $this->ai->generateWizardQuestions($draft);

        return AttachSession::create([
            'store_id' => $store->id,
            'type' => AttachSession::TYPE_WIZARD,
            // Null on purpose. A wizard session describes a product that does not exist
            // yet, which is the whole difference from a confirmation session.
            'product_id' => null,
            'questions' => array_map(
                static fn ($question): array => $question->toArray(),
                $questions,
            ),
            'draft' => $draft->toArray(),
            'expires_at' => now()->addHours(AttachSession::LIFETIME_HOURS),
        ]);
    }

    /**
     * Creates the product, its attributes, every combination, version 1, and the
     * seller's attachments, as one unit.
     *
     * The order inside the transaction is forced by the schema. The product is written
     * with a null version pointer, because products and product_versions reference each
     * other and one of them has to exist first. The pointer is set once the version
     * exists, which is what resolves the cycle.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submit(Store $store, AttachSession $session, array $payload): WizardSubmitResult
    {
        /** @var array<int, array{name: string, options: array<int, string>}> $attributes */
        $attributes = $payload['attributes'] ?? [];

        /** @var array<int, array{attribute_values: array<string, string>, price_minor: int, currency?: string}> $carried */
        $carried = $payload['carried_variants'] ?? [];

        $combinations = $this->variants->combinations($attributes);

        // Checked before the transaction opens, so a request that cannot succeed does
        // not take out row locks on its way to being refused.
        $this->assertCarriedCombinationsExist($carried, $combinations);

        $result = DB::transaction(function () use ($store, $session, $payload, $attributes, $carried, $combinations): WizardSubmitResult {
            $product = Product::create([
                'name' => $payload['name'],
                'slug' => $this->uniqueSlug((string) $payload['name']),
                'description' => $payload['description'] ?? null,
                'category' => $payload['category'],
                // The wizard answers, filed against the fact each question established.
                'specifications' => $this->specificationsFrom($session, $payload['answers'] ?? []),
                /*
                 * Historical attribution only. It grants the store no ownership and no
                 * editing rights, and it is never serialised to any client. Every later
                 * change to this record goes through a proposal like anyone else's.
                 */
                'created_by_store_id' => $store->id,
            ]);

            foreach ($attributes as $position => $attribute) {
                ProductAttribute::create([
                    'product_id' => $product->id,
                    'name' => $attribute['name'],
                    'options' => array_values($attribute['options']),
                    'position' => $position,
                ]);
            }

            $variants = $this->variants->generateFor($product, $combinations);

            // Version 1 is written after the attributes and variants exist, so its
            // snapshot describes the finished record rather than a bare product row.
            $version = $this->versions->record($product, causedByStore: $store, causedByUser: $store->user);

            $attachments = $this->attach($store, $product, $variants, $carried);

            $store->recomputeLiveFlag();

            // The session has done its job. Deleting it inside the transaction means a
            // rollback puts it back, so a failed submit can be retried against the same
            // questions rather than sending the seller through the wizard again.
            $session->delete();

            return new WizardSubmitResult(
                product: $product,
                versionNumber: $version->version_number,
                variantsGenerated: $variants->count(),
                attachmentsCreated: $attachments,
                storeIsLive: $store->is_live,
            );
        });

        /*
         * Indexed after the commit, never inside it.
         *
         * A queued job that ran while the transaction was still open could read a
         * product that then rolled back, leaving the search index advertising something
         * the database does not have. Dispatching here means the row is certainly
         * committed before anything goes looking for it.
         */
        IndexProduct::dispatch($result->product->id);

        return $result;
    }

    /**
     * Creates attachments for the combinations the seller actually carries.
     *
     * Only some of them, usually. Every combination was generated and is permanent, but
     * an attachment is a claim to have the thing in stock at a price, and a seller
     * makes that claim for the ones they hold.
     *
     * @param  Collection<int, Variant>  $variants
     * @param  array<int, array{attribute_values: array<string, string>, price_minor: int, currency?: string}>  $carried
     */
    private function attach(Store $store, Product $product, Collection $variants, array $carried): int
    {
        $byHash = $variants->keyBy('combination_hash');
        $created = 0;

        foreach ($carried as $entry) {
            $variant = $byHash->get(Variant::hashCombination($entry['attribute_values']));

            if ($variant === null) {
                continue;
            }

            Attachment::create([
                'store_id' => $store->id,
                'variant_id' => $variant->id,
                // Denormalised deliberately, so the seller list query on the busiest
                // page in the system does not join variants on every request.
                'product_id' => $product->id,
                'price_minor' => $entry['price_minor'],
                'currency' => $entry['currency'] ?? 'LKR',
                'is_available' => true,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Refuses a carried combination that is not one of the generated ones.
     *
     * Silently skipping it would be worse than refusing. The seller would be told the
     * product was created, see a lower attachment count than they listed, and have no
     * way to find out which entry was dropped or why.
     *
     * @param  array<int, array{attribute_values: array<string, string>, price_minor: int, currency?: string}>  $carried
     * @param  array<int, array<string, string>>  $combinations
     */
    private function assertCarriedCombinationsExist(array $carried, array $combinations): void
    {
        $generated = array_map(
            static fn (array $combination): string => Variant::hashCombination($combination),
            $combinations,
        );

        $errors = [];

        foreach ($carried as $index => $entry) {
            if (! in_array(Variant::hashCombination($entry['attribute_values']), $generated, true)) {
                $errors["carried_variants.{$index}.attribute_values"] = [
                    'This combination is not one the attributes you defined can produce.',
                ];
            }
        }

        if ($errors !== []) {
            throw new ApiException(422, 'validation_failed', 'The given data was invalid.', $errors);
        }
    }

    /**
     * The wizard answers, keyed by the fact each question was establishing.
     *
     * Filed under the question's attribute rather than its id, because `q3` means
     * nothing once the session is gone, while `in_the_box` still describes the product
     * years later when someone reads the version history.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function specificationsFrom(AttachSession $session, array $answers): array
    {
        $specifications = [];

        foreach ($session->questions as $question) {
            $answer = $answers[$question['id']] ?? null;

            if (is_string($answer) && trim($answer) !== '') {
                $specifications[$question['attribute']] = trim($answer);
            }
        }

        return $specifications;
    }

    /**
     * A slug nothing else is using.
     *
     * The suffix is found by looking, but the unique index on the column is what
     * actually guarantees it. Two wizards submitting the same product name at the same
     * instant would both find the same free slug, and one of them will hit the
     * constraint and roll back cleanly, which is the correct outcome.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        // A name of nothing but punctuation slugs to an empty string, which would then
        // collide with every other such name.
        if ($base === '') {
            $base = 'product';
        }

        $slug = $base;
        $suffix = 2;

        while (Product::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
