<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One nullable column, and the reason it exists rather than a fourth role.
 *
 * `users.role` is a MySQL enum of client, notary and admin. Adding a value to
 * it needs raw per-driver ALTER TABLE that the SQLite side of this project's
 * test matrix would reject, and — worse — every `isClient()` / `isNotary()` /
 * `role:admin` check on the platform would suddenly have a fourth kind of
 * account to think about. The organization guard exists for exactly this
 * reason, and the same reasoning applies here.
 *
 * So recruitment staff are ordinary users who additionally carry a
 * `recruitment_role`. The two systems do not overlap:
 *
 *   - NULL, which is every existing account, means no recruitment access of
 *     any kind. That is the default and nothing in this migration changes a
 *     single existing row.
 *   - A recruitment role grants recruitment abilities and nothing else. Every
 *     non-recruitment Filament resource refuses a user who is not a platform
 *     admin, and every non-Filament admin route is already behind
 *     `role:admin`, which a reviewer does not have. An HR reviewer cannot
 *     reach a payment, a payout or a client's notarized document.
 *   - A client or notary with no recruitment role is untouched, which is the
 *     whole point: applying for a job never makes anybody staff, and being
 *     staff never makes anybody a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // reviewer / coordinator / hr_admin / lead. A string and not an
            // enum: a value outside a MySQL enum is truncated with warning
            // 1265 and saves cleanly on SQLite, so an enum here would be a
            // divergence between the two engines waiting to happen. The
            // allowed values are on App\Support\RecruitmentPermission.
            $table->string('recruitment_role', 20)->nullable()->after('role')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['recruitment_role']);
            $table->dropColumn('recruitment_role');
        });
    }
};
