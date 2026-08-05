<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The verification call session (video, NOT recorded)
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('notarization_requests')->cascadeOnDelete();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->string('video_room_id')->nullable();
            $table->enum('verification_method', ['live_visual', 'uploaded_id'])->nullable();
            $table->boolean('identity_verified')->default(false);
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled', 'failed'])
                  ->default('scheduled')->index();
            $table->timestamps();
        });

        Schema::create('session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['client', 'notary', 'admin', 'observer']);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
        });

        // Verification evidence — replaces a recording. The defensible audit record.
        Schema::create('verification_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notary_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('id_document_id')->nullable()->constrained('request_documents')->nullOnDelete();
            $table->enum('method', ['live_visual', 'uploaded_id']);
            $table->timestamp('verified_at');
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        // Where the notary placed each asset / text element on the document
        Schema::create('document_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('request_documents')->cascadeOnDelete();
            $table->enum('type', ['asset', 'text']);
            $table->foreignId('asset_id')->nullable()->constrained('notary_assets')->nullOnDelete();
            $table->text('text_value')->nullable();
            $table->unsignedInteger('page');
            $table->float('x');
            $table->float('y');
            $table->float('width')->nullable();
            $table->float('height')->nullable();
            $table->foreignId('placed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_placements');
        Schema::dropIfExists('verification_records');
        Schema::dropIfExists('session_participants');
        Schema::dropIfExists('sessions');
    }
};
