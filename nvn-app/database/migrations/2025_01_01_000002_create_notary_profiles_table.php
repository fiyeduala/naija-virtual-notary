<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Application details
            $table->enum('entity_type', ['individual', 'agency'])->default('individual');
            $table->string('organization_name')->nullable();
            $table->string('license_ref')->nullable();          // Notary License / Appointment Letter Ref. number
            $table->year('year_of_oath')->nullable();
            $table->text('experience')->nullable();             // years + nature of documents notarised
            $table->json('specialties')->nullable();            // affidavits, property, contracts, etc.
            $table->text('motivation')->nullable();             // why partner with NVN

            // Verification lifecycle
            $table->enum('verification_status', ['pending', 'approved', 'rejected', 'suspended'])
                  ->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();

            // Onboarding fee
            $table->timestamp('onboarding_fee_paid_at')->nullable();

            // Commission (percentage the PLATFORM retains). Default 50%.
            $table->unsignedTinyInteger('commission_rate')->default(50);

            // System-native notary flag (the admin account that handles fallbacks)
            $table->boolean('is_system_native')->default(false)->index();

            // Public listing
            $table->boolean('public_listing_enabled')->default(false)->index();

            // Profile presentation
            $table->string('profile_photo_url')->nullable();
            $table->text('bio')->nullable();
            $table->json('languages')->nullable();
            $table->unsignedSmallInteger('max_requests_per_day')->nullable();

            // Delegated-notarization consent (the 30-minute fallback authorization)
            $table->boolean('delegation_consent')->default(false);
            $table->timestamp('delegation_consent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notary_profiles');
    }
};
