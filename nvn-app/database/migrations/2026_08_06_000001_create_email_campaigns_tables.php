<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An email the admin composed and sent — to everyone, to one role, or
        // to named people. Kept after sending: what went out to whom is part of
        // the record, and a resend needs something to copy from.
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->longText('body');
            $table->enum('audience', ['all', 'clients', 'notaries', 'individual'])->index();
            $table->enum('status', ['draft', 'queued', 'sending', 'sent', 'cancelled'])
                  ->default('draft')->index();

            // Counters, so the list screen never has to aggregate the recipient
            // table on every row.
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One row per person per campaign. This is the ledger that makes a send
        // resumable: a host that cuts the connection at its hourly limit leaves
        // rows 'pending', and nothing already 'sent' is ever sent twice.
        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot: the address it actually went to, even if the user later
            // changes their email or the account is deleted.
            $table->string('email');
            $table->string('name')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending')->index();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // The same person cannot be queued twice in one campaign.
            $table->unique(['email_campaign_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Honoured for audience sends (everyone / clients / notaries).
            // An admin writing to one person about their own request is
            // corresponding, not broadcasting, and is not blocked by this —
            // the compose screen shows the flag so it stays a human decision.
            $table->boolean('bulk_email_opt_out')->default(false)->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bulk_email_opt_out');
        });

        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
    }
};
