<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The custom 'notifications' table collides with Laravel's built-in database
 * notification channel. Rename it to 'notification_logs' and create a proper
 * Laravel notifications table so $user->notify() with 'database' channel works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('notifications', 'notification_logs');

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::rename('notification_logs', 'notifications');
    }
};
