<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The listing a proposing seller is waiting on.
 *
 * Fixes a gap in M6 that only became visible at M7. The confirmation flow records what
 * a seller wants to change but never recorded what they wanted to **sell**: the
 * variants they carry and the price they set were validated, used to decide the
 * outcome, and then discarded on the proposal path.
 *
 * That works right up until a proposal is approved. Approval is supposed to release
 * the attachment that was withheld, and without these columns there is nothing to
 * release: the seller would be unblocked and still not listed, with no way to find out
 * why except by going through confirmation again.
 *
 * The withheld attachment is the block. Recording what it would be is what makes the
 * block reversible.
 *
 * Nullable because proposals created before this migration have no intended listing.
 * The resolution service reports that rather than silently creating nothing, since a
 * proposal that approves into no listing is a bug worth seeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            /*
             * The variant ids as submitted, not a relation. A join table would imply
             * these rows mean something on their own; they do not. They are one field
             * of a submission that may never be applied, and they are read exactly once.
             */
            $table->jsonb('intended_variant_ids')->nullable()->after('ai_answers');

            // Integer in the smallest currency unit, like every other price.
            $table->bigInteger('intended_price_minor')->nullable()->after('intended_variant_ids');

            $table->char('intended_currency', 3)->nullable()->after('intended_price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropColumn(['intended_variant_ids', 'intended_price_minor', 'intended_currency']);
        });
    }
};
