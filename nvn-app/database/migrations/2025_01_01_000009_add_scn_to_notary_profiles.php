<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            $table->string('scn')->nullable()->after('license_ref');
        });
    }

    public function down(): void
    {
        Schema::table('notary_profiles', function (Blueprint $table) {
            $table->dropColumn('scn');
        });
    }
};
