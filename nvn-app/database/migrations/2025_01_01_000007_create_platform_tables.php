<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->nullable()->constrained('notarization_requests')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['request_fee', 'onboarding_fee', 'payout']);
            $table->unsignedBigInteger('amount'); // minor units (kobo / cents)
            $table->enum('currency', ['NGN', 'USD'])->default('NGN');
            $table->string('paystack_reference')->nullable()->index();
            $table->enum('status', ['pending', 'successful', 'failed', 'refunded'])->default('pending')->index();
            $table->json('meta')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notary_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->enum('currency', ['NGN', 'USD'])->default('NGN');
            $table->string('paystack_transfer_code')->nullable();
            $table->enum('status', ['pending', 'processing', 'paid', 'failed'])->default('pending')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Per-request two-way message thread, with admin oversight via sender_role
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('notarization_requests')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('sender_role', ['client', 'notary', 'admin']);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('channel', ['email', 'in_app']);
            $table->string('template');
            $table->json('payload')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Append-only, tamper-evident action log
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('content_hash', 64)->nullable();   // hash of this row's content
            $table->string('previous_hash', 64)->nullable();  // chained to prior row
            $table->timestamp('created_at')->nullable();
            $table->index(['entity_type', 'entity_id']);
        });

        // Enterprise contact / quote requests (replaces the old invoice-count form)
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('company');
            $table->string('contact_name');
            $table->string('work_email');
            $table->string('phone')->nullable();
            $table->string('org_type')->nullable();
            $table->string('monthly_volume')->nullable();
            $table->json('document_types')->nullable();
            $table->string('preferred_turnaround')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['new', 'in_progress', 'closed'])->default('new')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payments');
    }
};
