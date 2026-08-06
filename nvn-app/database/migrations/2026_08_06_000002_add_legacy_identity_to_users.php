<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for accounts carried over from the old WordPress site.
 *
 * The WordPress password hash must NOT be written to users.password: that
 * column is cast 'hashed' on the model, so assigning a hash to it hashes the
 * hash and the account is locked out for good. It goes in legacy_password,
 * which nothing casts, and LegacyUserProvider checks it at sign-in and
 * upgrades the account to a Laravel hash on the first successful login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The wp_users.user_pass value, exactly as WordPress stored it —
            // '$P$…' (phpass), '$wp$2y$…' (WordPress 6.8+ bcrypt) or an ancient
            // bare MD5. Cleared the moment it has been converted.
            $table->string('legacy_password')->nullable()->after('password');

            // Where the account came from, and its id there. Keeps a re-run of
            // the import from creating a second copy of anybody, and leaves a
            // way back to the old row when something needs checking.
            $table->string('legacy_source', 40)->nullable()->after('legacy_password');
            $table->unsignedBigInteger('legacy_id')->nullable()->after('legacy_source');
            $table->index(['legacy_source', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['legacy_source', 'legacy_id']);
            $table->dropColumn(['legacy_password', 'legacy_source', 'legacy_id']);
        });
    }
};
