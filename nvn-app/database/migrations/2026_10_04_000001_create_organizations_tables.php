<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner bodies that send work: embassies, government agencies, law firms.
 *
 * An organization is three things at once, and the table carries all three
 * because they are the same body and splitting them would mean copying data
 * between tables at approval time:
 *
 *   - An APPLICATION, while status is 'pending'. Everything from
 *     registration_number down to review_note belongs to this phase, and a
 *     pending row is inert: no slug, no credentials, no landing page.
 *   - A PRICE LIST, once an admin has negotiated one. default_price_* is what
 *     this body's referrals are charged, as a figure and not a discount — a
 *     government rate of ₦250,000 against a public ₦25,000 is not expressible
 *     as a percentage of anything.
 *   - A PAYEE, but only under the 'commission' arrangement. 'price_only' is a
 *     body that is simply charged its own rate and earns nothing, and that is
 *     stored rather than represented as a 0% rate — see
 *     OrganizationPayoutService for why a zero rate would be dangerous.
 *
 * status and arrangement are plain strings rather than enums on purpose. An
 * enum column needs raw per-driver SQL to gain a value later, and this is
 * exactly the kind of column that gains one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // The link and the code. Both nullable: an application has neither
            // until it is approved, which is also what makes a pending body
            // unreachable without a second status check.
            $table->string('slug')->nullable()->unique();
            $table->string('code')->nullable()->unique();

            $table->string('status')->default('pending')->index();
            $table->string('arrangement')->nullable();

            // What this body's referrals pay. Minor units, like every other
            // amount in the schema.
            $table->unsignedBigInteger('default_price_ngn')->nullable();
            $table->unsignedBigInteger('default_price_usd')->nullable();

            // Their cut, under 'commission' only. Forced to 0 otherwise by the
            // model, so a body switched from commission to price_only cannot
            // leave a live rate behind it.
            $table->unsignedTinyInteger('commission_rate')->default(0);

            // How long after a visit a referral still counts. 0 means lifetime.
            $table->unsignedInteger('attribution_days')->nullable();

            // The person to telephone. Separate from the body because this is
            // who you reach when the submission has a mistake in it.
            $table->string('contact_name')->nullable();
            $table->string('contact_role')->nullable();
            $table->string('contact_email')->nullable()->index();
            $table->string('phone')->nullable();

            // What they told us about themselves.
            $table->string('registration_number')->nullable();
            $table->string('sector')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->text('about')->nullable();
            $table->string('expected_volume')->nullable();
            $table->string('logo_url')->nullable();

            // Where their commission is sent. Only asked for under
            // 'commission'; account_number is cast encrypted on the model,
            // matching NotaryBankDetail.
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->text('account_number')->nullable();

            $table->text('notes')->nullable();

            // Review trail.
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();

            // The portal login. One per organization; per-staff accounts would
            // need a table of their own and change nothing here.
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Whatever they sent in support of the application, and whatever turns
        // up after the phone call. A table rather than two columns, mirroring
        // notary_credentials, because a body's paperwork is not a fixed shape.
        Schema::create('organization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('file_url');
            $table->string('original_filename')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Optional per-category prices on top of the default. Keyed on the
        // service row, which is safe here precisely because an organization's
        // work is always notarized by the platform's own profile — there is
        // only ever one notary's service list to key against.
        Schema::create('organization_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notary_service_id')->constrained('notary_services')->cascadeOnDelete();
            $table->unsignedBigInteger('price_ngn')->nullable();
            $table->unsignedBigInteger('price_usd')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'notary_service_id'], 'org_service_price_unique');
        });

        // The commission ledger's other half. Shaped like payouts so the two
        // read the same way, minus paystack_transfer_code: organization
        // commission is settled by hand in v1.
        Schema::create('organization_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // The organization's share. gross_amount is the fees it was
            // computed from, so a row shows the whole picture and the
            // platform's own half is a subtraction rather than a second query.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('gross_amount')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('pending')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('settlement_method')->nullable();
            $table->string('settlement_reference')->nullable();
            $table->text('settlement_note')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The portal's own password broker, so an organization resetting its
        // password cannot collide with a user of the same email address.
        Schema::create('organization_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_password_reset_tokens');
        Schema::dropIfExists('organization_payouts');
        Schema::dropIfExists('organization_prices');
        Schema::dropIfExists('organization_documents');
        Schema::dropIfExists('organizations');
    }
};
