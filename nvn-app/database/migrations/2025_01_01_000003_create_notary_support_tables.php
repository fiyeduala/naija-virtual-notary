<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uploaded verification documents (ID, oath of office, etc.)
        Schema::create('notary_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notary_profile_id')->constrained()->cascadeOnDelete();
            $table->string('document_type'); // 'valid_id', 'oath_of_office', etc.
            $table->string('file_url');
            $table->string('original_filename')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Notarial assets: e-signature, stamp, seal images + typed initials
        Schema::create('notary_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notary_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['signature', 'stamp', 'seal', 'initials']);
            $table->string('file_url')->nullable();   // for image assets
            $table->string('text_value')->nullable(); // for typed initials
            $table->timestamps();
        });

        // Bank details for payouts
        Schema::create('notary_bank_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notary_profile_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->text('account_number');           // encrypted via cast
            $table->string('account_name');
            $table->string('paystack_recipient_code')->nullable();
            $table->timestamps();
        });

        // Services offered, priced in BOTH currencies (entered independently)
        Schema::create('notary_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notary_profile_id')->constrained()->cascadeOnDelete();
            $table->string('service_type'); // matches specialty list
            $table->unsignedBigInteger('price_ngn'); // stored in kobo
            $table->unsignedBigInteger('price_usd'); // stored in cents
            $table->unsignedSmallInteger('estimated_duration_minutes')->default(30);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notary_services');
        Schema::dropIfExists('notary_bank_details');
        Schema::dropIfExists('notary_assets');
        Schema::dropIfExists('notary_credentials');
    }
};
