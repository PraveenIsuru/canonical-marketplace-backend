<?php

declare(strict_types=1);

use App\Contracts\AiProvider;
use App\Jobs\GenerateWizardQuestions;
use App\Jobs\IndexProduct;
use App\Jobs\MatchProduct;
use App\Models\AiJob;
use App\Models\Attachment;
use App\Models\AttachSession;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\ProductVersion;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Ai\FakeAiProvider;
use App\Services\Attach\ProductWizardService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * M5 The wizard path. EP-20, EP-23, EP-24, EP-48, EP-50.
 *
 * The milestone's centre of gravity is EP-24, which writes six tables in one
 * transaction and creates records the platform has no way to delete. Much of this file
 * exists to pin down what that transaction does, and to prove that a failure part way
 * through leaves nothing behind.
 *
 * The other thing worth stating plainly: an empty match result is a success, not an
 * error. It is the answer that sends a seller to the wizard.
 *
 * No test here touches a network. The AI provider is the fake adapter, the search
 * engine is a null engine, and image uploads go to a faked disk.
 */

/** Forces the AI provider into its failing mode for the current test. */
function withFailingProvider(): void
{
    app()->instance(AiProvider::class, new FakeAiProvider(shouldFail: true));
}

/** A seller: a user who holds a store. */
function seller(): User
{
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    return $user;
}

/** Opens a wizard session the way EP-23 would, without going through the endpoint. */
function wizardSession(Store $store, array $overrides = []): AttachSession
{
    return AttachSession::create(array_merge([
        'store_id' => $store->id,
        'type' => AttachSession::TYPE_WIZARD,
        'product_id' => null,
        'questions' => [
            ['id' => 'q1', 'attribute' => 'brand', 'text' => 'Who makes it?'],
            ['id' => 'q2', 'attribute' => 'model', 'text' => 'Which model is it?'],
        ],
        'draft' => ['name' => 'Aurora Field Recorder', 'description' => null, 'category' => 'Audio'],
        'expires_at' => now()->addHours(AttachSession::LIFETIME_HOURS),
    ], $overrides));
}

/** A complete, valid EP-24 payload. Overrides replace whole keys. */
function wizardPayload(AttachSession $session, array $overrides = []): array
{
    return array_merge([
        'session_id' => $session->id,
        'answers' => ['q1' => 'Aurora', 'q2' => 'FR-2'],
        'name' => 'Aurora Field Recorder FR-2',
        'description' => 'A two channel portable recorder.',
        'category' => 'Audio',
        'attributes' => [
            ['name' => 'Colour', 'options' => ['Black', 'Grey']],
        ],
        'carried_variants' => [
            ['attribute_values' => ['Colour' => 'Black'], 'price_minor' => 4599900, 'currency' => 'LKR'],
        ],
    ], $overrides);
}

beforeEach(function (): void {
    // Product images are written to a faked disk, so no test leaves files behind and
    // none of them depends on the real storage directory existing.
    Storage::fake('product_images');
});

/*
|--------------------------------------------------------------------------
| EP-20 Match. Duplicate detection, the gate the platform depends on.
|--------------------------------------------------------------------------
*/

it('returns candidates when the catalogue already holds the product', function (): void {
    $existing = Product::factory()->create(['name' => 'Focusrite Scarlett Solo 4th Gen']);

    $this->actingAs(seller(), 'sanctum')
        ->postJson('/api/attach/match', ['name' => 'Focusrite Scarlett Solo 4th Gen'])
        ->assertOk()
        ->assertJsonPath('data.candidates.0.product_id', $existing->id)
        ->assertJsonPath('data.candidates.0.slug', $existing->slug)
        ->assertJsonStructure(['data' => ['candidates' => [['product_id', 'slug', 'name', 'primary_image_url', 'match_score']]]]);
});

it('returns an empty candidate list when nothing in the catalogue is close', function (): void {
    Product::factory()->create(['name' => 'Focusrite Scarlett Solo 4th Gen']);

    /*
     * The most important assertion in this file after the transaction ones. An empty
     * array is a successful answer that routes the seller to the wizard. If this ever
     * became an error, every genuinely new product would be unlistable.
     */
    $this->actingAs(seller(), 'sanctum')
        ->postJson('/api/attach/match', ['name' => 'Kandyan Brass Oil Lamp'])
        ->assertOk()
        ->assertJsonPath('data.candidates', []);
});

it('queues the work and answers 503 when the provider fails', function (): void {
    Queue::fake();
    withFailingProvider();

    Product::factory()->create(['name' => 'Focusrite Scarlett Solo 4th Gen']);

    $response = $this->actingAs(seller(), 'sanctum')
        ->postJson('/api/attach/match', ['name' => 'Focusrite Scarlett Solo 4th Gen'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable');

    // The job id sits at the top level of the body, outside data, which is where the
    // client looks for it before persisting it and polling.
    $jobId = $response->json('queued_job_id');

    expect($jobId)->toBeString()
        ->and(AiJob::find($jobId)->type)->toBe(AiJob::TYPE_MATCH_CANDIDATES);

    Queue::assertPushed(MatchProduct::class);
});

it('never falls back to a keyword answer, unlike buyer search', function (): void {
    // Faked because the test queue runs jobs inline, which would re-enter the failing
    // provider and replace the 503 under test with the job's own exception.
    Queue::fake();
    withFailingProvider();

    Product::factory()->create(['name' => 'Focusrite Scarlett Solo 4th Gen']);

    /*
     * Matching is not an exception to the AI unavailability rule and must never become
     * one. A degraded match could let a seller past duplicate detection and create a
     * second canonical record, which is the outcome the whole platform prevents.
     */
    $this->actingAs(seller(), 'sanctum')
        ->postJson('/api/attach/match', ['name' => 'Focusrite Scarlett Solo 4th Gen'])
        ->assertStatus(503);
});

it('refuses matching for a user with no store', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/attach/match', ['name' => 'Anything'])
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

it('refuses a match image over five megabytes', function (): void {
    $this->actingAs(seller(), 'sanctum')
        ->post('/api/attach/match', [
            'name' => 'Aurora Field Recorder',
            'image' => UploadedFile::fake()->image('shelf.jpg')->size(6000),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        // Its own code, not validation_failed. The client shows a different message.
        ->assertJsonPath('code', 'file_too_large');
});

it('refuses a match image that is not jpeg, png, or webp', function (): void {
    $this->actingAs(seller(), 'sanctum')
        ->post('/api/attach/match', [
            'name' => 'Aurora Field Recorder',
            'image' => UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unsupported_media_type');
});

it('never keeps a match image as a product image', function (): void {
    Product::factory()->create(['name' => 'Aurora Field Recorder']);

    $this->actingAs(seller(), 'sanctum')
        ->post('/api/attach/match', [
            'name' => 'Aurora Field Recorder',
            'image' => UploadedFile::fake()->image('shelf.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    // The image answers one question and is discarded. Keeping it would put an
    // unreviewed photograph of a seller's shelf on a canonical record.
    expect(ProductImage::count())->toBe(0)
        ->and(Storage::disk('product_images')->allFiles())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| EP-23 Wizard start. Reachable only when matching found nothing.
|--------------------------------------------------------------------------
*/

it('opens a wizard session when the catalogue holds nothing like it', function (): void {
    $user = seller();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/start', ['name' => 'Kandyan Brass Oil Lamp', 'category' => 'Home'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['session_id', 'questions' => [['id', 'attribute', 'text']], 'expires_at']]);

    $session = AttachSession::find($response->json('data.session_id'));

    expect($session)->not->toBeNull()
        ->and($session->type)->toBe(AttachSession::TYPE_WIZARD)
        ->and($session->store_id)->toBe($user->store->id)
        // Null on purpose. A wizard session describes a product that does not exist yet.
        ->and($session->product_id)->toBeNull();
});

it('refuses the wizard while a match candidate is outstanding', function (): void {
    Product::factory()->create(['name' => 'Focusrite Scarlett Solo 4th Gen']);

    /*
     * Checked server side by re-running matching, not by trusting the client to report
     * that it found nothing. A seller may not overrule the matching result to declare
     * their product new, and a check the client performs on itself is not a check.
     */
    $this->actingAs(seller(), 'sanctum')
        ->postJson('/api/attach/wizard/start', ['name' => 'Focusrite Scarlett Solo 4th Gen'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'match_required');

    expect(AttachSession::count())->toBe(0);
});

it('queues wizard question generation and answers 503 when the provider fails', function (): void {
    Queue::fake();
    withFailingProvider();

    $response = $this->actingAs(seller(), 'sanctum')
        ->postJson('/api/attach/wizard/start', ['name' => 'Kandyan Brass Oil Lamp'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable');

    expect(AiJob::find($response->json('queued_job_id'))->type)->toBe(AiJob::TYPE_WIZARD_QUESTIONS);

    Queue::assertPushed(GenerateWizardQuestions::class);
});

it('opens the session from the queued job, so a seller who left can resume', function (): void {
    $user = seller();

    $job = AiJob::create([
        'user_id' => $user->id,
        'type' => AiJob::TYPE_WIZARD_QUESTIONS,
        'status' => AiJob::STATUS_QUEUED,
        'payload' => ['name' => 'Kandyan Brass Oil Lamp', 'store_id' => $user->store->id],
    ]);

    // The provider has recovered by the time the job runs, which is the situation the
    // whole recovery path exists for.
    (new GenerateWizardQuestions($job->id))->handle(app(ProductWizardService::class));

    $job->refresh();

    expect($job->status)->toBe(AiJob::STATUS_COMPLETED)
        // The session is opened by the job itself. A session that only came into being
        // if someone was watching would lose the flow exactly when this should save it.
        ->and(AttachSession::count())->toBe(1)
        ->and($job->result['session_id'])->toBe(AttachSession::sole()->id);
});

/*
|--------------------------------------------------------------------------
| EP-24 Wizard submit. One transaction, six tables, nothing reversible.
|--------------------------------------------------------------------------
*/

it('creates the product, its attributes, every combination, version 1, and the attachment', function (): void {
    Queue::fake();

    $user = seller();
    $session = wizardSession($user->store);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session))
        ->assertCreated()
        ->assertJsonPath('data.product.current_version_number', 1)
        ->assertJsonPath('data.variants_generated', 2)
        ->assertJsonPath('data.attachments_created', 1)
        ->assertJsonPath('data.store_is_live', true);

    $product = Product::find($response->json('data.product.id'));

    expect($product->slug)->toBe('aurora-field-recorder-fr-2')
        ->and($product->productAttributes()->count())->toBe(1)
        ->and($product->variants()->count())->toBe(2)
        // The answers are filed under the fact each question established, not under the
        // question id, which means nothing once the session is gone.
        ->and($product->specifications)->toBe(['brand' => 'Aurora', 'model' => 'FR-2']);

    Queue::assertPushed(IndexProduct::class);
});

it('sets the current version pointer to version 1', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session))
        ->assertCreated();

    $product = Product::sole();
    $version = ProductVersion::sole();

    /*
     * A version written without moving the pointer is invisible to every read path,
     * and nothing else would catch it: the version row itself would look correct.
     */
    expect($product->current_version_id)->toBe($version->id)
        ->and($version->version_number)->toBe(1)
        ->and($version->caused_by_store_id)->toBe($user->store->id)
        // Version 1 comes from the wizard, not from a proposal and not from an admin.
        ->and($version->proposal_id)->toBeNull()
        ->and($version->is_admin_originated)->toBeFalse();
});

it('records the whole record state in the version snapshot, not a diff', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session))
        ->assertCreated();

    $snapshot = ProductVersion::sole()->snapshot;

    // Written after the attributes and variants exist, so it describes the finished
    // record rather than a bare product row.
    expect($snapshot['name'])->toBe('Aurora Field Recorder FR-2')
        ->and($snapshot['attributes'])->toHaveCount(1)
        ->and($snapshot['variants'])->toHaveCount(2);
});

it('generates one default variant where no attributes were defined', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'attributes' => [],
            'carried_variants' => [['attribute_values' => [], 'price_minor' => 250000]],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.variants_generated', 1);

    $variant = Variant::sole();

    // Every canonical product has at least one variant. A product with no meaningful
    // variation carries a single default one rather than none.
    expect($variant->is_default)->toBeTrue()
        ->and($variant->attribute_values)->toBe([]);
});

it('generates the cross product for one attribute', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'attributes' => [['name' => 'Colour', 'options' => ['Black', 'Grey', 'Sand']]],
            'carried_variants' => [['attribute_values' => ['Colour' => 'Black'], 'price_minor' => 100]],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.variants_generated', 3);

    expect(Variant::where('is_default', true)->count())->toBe(0);
});

it('generates the cross product for two attributes', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'attributes' => [
                ['name' => 'Colour', 'options' => ['Black', 'Grey']],
                ['name' => 'Capacity', 'options' => ['64GB', '128GB', '256GB']],
            ],
            'carried_variants' => [
                ['attribute_values' => ['Colour' => 'Black', 'Capacity' => '128GB'], 'price_minor' => 100],
            ],
        ]))
        ->assertCreated()
        // Two colours by three capacities. Every one of the six is created and is
        // permanent, whether or not anyone carries it.
        ->assertJsonPath('data.variants_generated', 6)
        ->assertJsonPath('data.attachments_created', 1);

    expect(Variant::count())->toBe(6);
});

it('creates attachments only for the combinations the seller carries', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'attributes' => [['name' => 'Colour', 'options' => ['Black', 'Grey', 'Sand']]],
            'carried_variants' => [
                ['attribute_values' => ['Colour' => 'Black'], 'price_minor' => 100],
                ['attribute_values' => ['Colour' => 'Sand'], 'price_minor' => 120],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.variants_generated', 3)
        ->assertJsonPath('data.attachments_created', 2);

    /*
     * Generated combinations exceed attachments, and that is expected rather than an
     * inconsistency. The uncarried combination stays in the catalogue showing no
     * sellers, and no path anywhere removes it.
     */
    $uncarried = Variant::whereJsonContains('attribute_values->Colour', 'Grey')->sole();

    expect(Attachment::count())->toBe(2)
        ->and($uncarried->attachments()->count())->toBe(0);
});

it('refuses a carried combination the defined attributes cannot produce', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    /*
     * Refused rather than skipped. Skipping it would report a lower attachment count
     * than the seller listed, with no way for them to find out which entry vanished.
     */
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'attributes' => [['name' => 'Colour', 'options' => ['Black', 'Grey']]],
            'carried_variants' => [['attribute_values' => ['Colour' => 'Turquoise'], 'price_minor' => 100]],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect(Product::count())->toBe(0);
});

it('refuses a submission with an unanswered question', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'answers' => ['q1' => 'Aurora', 'q2' => '   '],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    /*
     * Checked against the stored questions, never against what the client sent. A
     * client supplying both the questions and the answers could always report itself
     * complete, which would make the whole check theatre.
     *
     * The error is keyed by the question it names, so the interface can mark the field
     * rather than showing one message for the whole form.
     */
    expect(array_keys($response->json('errors')))->toContain('answers.q2')
        ->and(Product::count())->toBe(0);
});

it('refuses a wizard session belonging to another store', function (): void {
    $session = wizardSession(seller()->store);

    $this->actingAs(seller(), 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session))
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect(Product::count())->toBe(0);
});

it('sends an expired session back through matching', function (): void {
    $user = seller();
    $session = wizardSession($user->store, ['expires_at' => now()->subMinute()]);

    /*
     * match_required rather than a plain 422. The catalogue may have gained this very
     * product while the session sat open, so matching has to run again before the
     * wizard can be opened a second time.
     */
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session))
        ->assertStatus(422)
        ->assertJsonPath('code', 'match_required');
});

it('rejects a price of zero or below', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'carried_variants' => [['attribute_values' => ['Colour' => 'Black'], 'price_minor' => 0]],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('rejects a decimal price outright rather than rounding it', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    // Prices cross the boundary as integers in the smallest currency unit. The API
    // never accepts a decimal, so this is a refusal and not a value to round.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session, [
            'carried_variants' => [['attribute_values' => ['Colour' => 'Black'], 'price_minor' => 4599.9]],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('rolls the whole submission back when something fails part way through', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    /*
     * The product row is written first, then attributes, then variants, then version 1.
     * Failing at the attribute step means the product already exists in the transaction
     * when the failure happens, which is precisely the state that must not survive.
     *
     * There is no product deletion path anywhere in the platform, so a product left
     * holding attributes but no variants would sit in the catalogue permanently,
     * unusable and unremovable.
     */
    Event::listen('eloquent.created: '.ProductAttribute::class, function (): void {
        throw new RuntimeException('mid sequence failure');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session)))
        ->toThrow(RuntimeException::class);

    expect(Product::count())->toBe(0)
        ->and(ProductAttribute::count())->toBe(0)
        ->and(Variant::count())->toBe(0)
        ->and(ProductVersion::count())->toBe(0)
        ->and(Attachment::count())->toBe(0)
        ->and($user->store->refresh()->is_live)->toBeFalse()
        // The session comes back with the rollback, so the seller retries against the
        // same questions instead of being sent through the wizard again.
        ->and(AttachSession::count())->toBe(1);
});

it('consumes the session on a successful submission', function (): void {
    $user = seller();
    $session = wizardSession($user->store);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload($session))
        ->assertCreated();

    expect(AttachSession::count())->toBe(0);
});

it('takes the store live, which is the outcome of the whole flow', function (): void {
    $user = seller();

    expect($user->store->is_live)->toBeFalse();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload(wizardSession($user->store)))
        ->assertCreated();

    // A store is visible to buyers if and only if it holds at least one attachment.
    // This is the first milestone in which that can become true.
    expect($user->store->refresh()->is_live)->toBeTrue();
});

it('records the creating store without exposing it', function (): void {
    $user = seller();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload(wizardSession($user->store)))
        ->assertCreated();

    $product = Product::sole();

    // Historical attribution only. It conveys no ownership, and serialising it would
    // imply a seller owns the canonical record.
    expect($product->created_by_store_id)->toBe($user->store->id)
        ->and($response->json())->not->toHaveKey('data.product.created_by_store_id');

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonMissingPath('data.created_by_store_id');
});

it('gives a second product with the same name a different slug', function (): void {
    $user = seller();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload(wizardSession($user->store)))
        ->assertCreated();

    $other = seller();

    $this->actingAs($other, 'sanctum')
        ->postJson('/api/attach/wizard/submit', wizardPayload(wizardSession($other->store)))
        ->assertCreated();

    // Ordered explicitly. A bare pluck returns rows in whatever order PostgreSQL finds
    // them, which is stable only until something else changes the table's churn, and
    // the assertion here is about the slugs rather than about their physical order.
    expect(Product::orderBy('id')->pluck('slug')->all())
        ->toBe(['aurora-field-recorder-fr-2', 'aurora-field-recorder-fr-2-2']);
});

/*
|--------------------------------------------------------------------------
| EP-48 Product images
|--------------------------------------------------------------------------
*/

it('uploads an image to a product record', function (): void {
    $product = Product::factory()->create();

    $this->actingAs(seller(), 'sanctum')
        ->post("/api/products/{$product->slug}/images", [
            'image' => UploadedFile::fake()->image('front.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'url', 'mime_type', 'position', 'uploaded_by_user_id']])
        // The storage path is internal. Clients receive a URL, so the disk can move
        // without changing what was promised.
        ->assertJsonMissingPath('data.storage_path');

    expect(ProductImage::count())->toBe(1)
        ->and(Storage::disk('product_images')->allFiles())->toHaveCount(1);
});

it('refuses a ninth image', function (): void {
    $product = Product::factory()->create();
    ProductImage::factory()->count(8)->for($product)->create();

    $this->actingAs(seller(), 'sanctum')
        ->post("/api/products/{$product->slug}/images", [
            'image' => UploadedFile::fake()->image('ninth.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'image_limit_reached');

    // Checked before the file is written, so a refused upload leaves nothing on disk.
    expect(Storage::disk('product_images')->allFiles())->toBe([]);
});

it('refuses a product image over five megabytes', function (): void {
    $product = Product::factory()->create();

    $this->actingAs(seller(), 'sanctum')
        ->post("/api/products/{$product->slug}/images", [
            'image' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'file_too_large');

    expect(ProductImage::count())->toBe(0);
});

it('refuses a product image that is not jpeg, png, or webp', function (): void {
    $product = Product::factory()->create();

    $this->actingAs(seller(), 'sanctum')
        ->post("/api/products/{$product->slug}/images", [
            'image' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unsupported_media_type');
});

it('refuses an image upload from a user with no store', function (): void {
    $product = Product::factory()->create();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->post("/api/products/{$product->slug}/images", [
            'image' => UploadedFile::fake()->image('front.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

/*
|--------------------------------------------------------------------------
| EP-50 Job polling. The recovery path for every blocked AI flow.
|--------------------------------------------------------------------------
*/

it('returns a job to the user who created it', function (): void {
    $user = User::factory()->create();

    $job = AiJob::create([
        'user_id' => $user->id,
        'type' => AiJob::TYPE_MATCH_CANDIDATES,
        'status' => AiJob::STATUS_COMPLETED,
        'payload' => ['name' => 'Aurora'],
        'result' => ['candidates' => []],
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/jobs/{$job->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.result_type', 'match_candidates')
        ->assertJsonPath('data.result.candidates', []);
});

it('reports no result type while the work is still outstanding', function (): void {
    $user = User::factory()->create();

    $job = AiJob::create([
        'user_id' => $user->id,
        'type' => AiJob::TYPE_WIZARD_QUESTIONS,
        'status' => AiJob::STATUS_QUEUED,
        'payload' => [],
    ]);

    // Naming the type before there is a result would let a client start resuming a flow
    // it has no answer for yet.
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/jobs/{$job->id}")
        ->assertOk()
        ->assertJsonPath('data.result_type', null)
        ->assertJsonPath('data.result', null);
});

it('hides a job belonging to another user', function (): void {
    $job = AiJob::create([
        'user_id' => User::factory()->create()->id,
        'type' => AiJob::TYPE_MATCH_CANDIDATES,
        'status' => AiJob::STATUS_COMPLETED,
        'payload' => [],
        'result' => ['candidates' => [['product_id' => 1]]],
    ]);

    /*
     * 404, not 403. Match candidates say what a competitor is about to start selling,
     * and distinguishing "not yours" from "does not exist" would confirm that an id is
     * real, which is the one fact an enumeration attempt is trying to establish.
     */
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/jobs/{$job->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('refuses job polling without a token', function (): void {
    $this->getJson('/api/jobs/9f2c1a80-0000-4000-8000-000000000000')
        ->assertUnauthorized();
});
