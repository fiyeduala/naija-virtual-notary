<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recurring weekly availability
        Schema::create('notary_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notary_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sunday .. 6=Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone')->default('Africa/Lagos');
            $table->timestamps();
        });

        // One-off overrides (holidays, specific-date blocks or openings)
        Schema::create('notary_availability_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notary_profile_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('available')->default(false); // false = blocked
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notary_availability_overrides');
        Schema::dropIfExists('notary_availability');
    }
};
