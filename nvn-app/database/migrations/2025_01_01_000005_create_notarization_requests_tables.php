<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notarization_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // human-friendly e.g. NVN-2025-000123

            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            // Assigned notary — nullable until selected; may be reassigned to admin on fallback
            $table->foreignId('notary_id')->nullable()->constrained('notary_profiles')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('notary_services')->nullOnDelete();

            $table->enum('status', [
                'draft',
                'submitted',      // intake complete, awaiting payment
                'paid',           // payment cleared, notary notified
                'accepted',       // notary accepted
                'scheduled',      // session booked
                'in_verification',// verification call happening
                'notarizing',     // notary working on the document
                'completed',      // notarized doc delivered
                'cancelled',
                'refunded',
            ])->default('draft')->index();

            // Document use / reason for notarization
            $table->text('document_use')->nullable();

            // Full intake form payload (flexible)
            $table->json('intake_data')->nullable();

            // Currency chosen by the client for this request
            $table->enum('currency', ['NGN', 'USD'])->default('NGN');

            // Hard copy delivery
            $table->boolean('hard_copy_requested')->default(false);
            $table->json('delivery_address')->nullable();

            // Fallback handling
            $table->boolean('was_fallback')->default(false);
            $table->timestamp('notary_notified_at')->nullable();
            $table->timestamp('fallback_due_at')->nullable(); // notified_at + 30 min
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();

            // Lifecycle timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Documents tied to a request: uploads + the final notarized output
        Schema::create('request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('notarization_requests')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_url');
            $table->string('original_filename')->nullable();
            $table->string('file_hash_sha256', 64)->nullable();
            $table->string('file_type')->nullable(); // 'document', 'additional', 'identification', 'final_notarized'
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_final_notarized')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_documents');
        Schema::dropIfExists('notarization_requests');
    }
};
