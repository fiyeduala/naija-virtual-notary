<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacancies, their reviewers, and the assessment templates they draw from.
 *
 * Three decisions are visible in the column list and worth stating outright:
 *
 *   - Money is minor units plus an explicit currency plus an explicit pay
 *     period. "50000" on its own is unusable: nobody can tell naira from
 *     cents, nor a month from a year.
 *   - `salary_disclosure` is NULLABLE, and null is not a default — it means
 *     nobody has decided yet, and publication is refused until somebody does.
 *     A vacancy that silently defaults to "not disclosed" is a policy choice
 *     made by a migration, which is not where that choice belongs.
 *   - Every status-ish column is a string(20) with its values on a model
 *     constant. A MySQL enum truncates an unexpected value with warning 1265
 *     and saves it happily on SQLite, which is a divergence between the two
 *     engines this project tests against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_templates', function (Blueprint $table) {
            $table->id();

            // Which of the four categories this template is the assessment for.
            // Values live on App\Enums\RecruitmentRole.
            $table->string('role_key', 40)->index();

            $table->string('name', 160);

            // Bumped on every meaningful edit. An assignment snapshots the
            // version it was created from, so editing a template never
            // changes a task somebody is halfway through.
            $table->unsignedInteger('version')->default(1);

            $table->boolean('is_active')->default(true)->index();

            // The approved facts a candidate is allowed to rely on while
            // answering. Without this, a customer-service exercise tests
            // whether they can guess our refund policy.
            $table->longText('brief')->nullable();

            $table->unsignedSmallInteger('effort_minutes_min')->nullable();
            $table->unsignedSmallInteger('effort_minutes_max')->nullable();

            // The tasks, as an ordered list of simple field definitions:
            // short text, long text, select, yes/no, url. Validated against a
            // server-side schema on save — not a drag-and-drop designer.
            $table->json('tasks')->nullable();

            // The reviewer's rubric: criteria, each with its anchors.
            $table->json('rubric')->nullable();

            $table->unsignedTinyInteger('score_scale_max')->default(5);

            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['role_key', 'is_active']);
        });

        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();

            $table->string('role_key', 40)->index();
            $table->string('title', 160);
            $table->string('slug', 190)->unique();
            $table->string('department', 80)->nullable();

            // draft / published / paused / closed / archived — the values are
            // on App\Enums\VacancyState, and only `published` accepts a
            // submission. That is checked on the server in every path that
            // creates an application, not by hiding the Apply button.
            $table->string('state', 20)->default('draft')->index();

            // Nullable because this application does not know what NVN's
            // employment terms are, and inventing "Full-time" here would put
            // a term on a public page that nobody agreed to.
            $table->string('employment_type', 60)->nullable();

            // Remote does not mean worldwide, and it does not mean whenever
            // you like. Both are stated explicitly or the vacancy cannot be
            // published.
            $table->text('location_eligibility')->nullable();
            $table->text('working_hours')->nullable();
            $table->string('hours_timezone', 60)->nullable();

            $table->text('summary')->nullable();
            $table->longText('responsibilities')->nullable();
            $table->longText('requirements')->nullable();
            $table->text('remote_arrangements')->nullable();
            $table->text('apply_instructions')->nullable();

            $table->unsignedSmallInteger('headcount')->nullable();

            // NULL means the administrator has not yet chosen whether to
            // disclose pay. It is not a default of "no" — publication is
            // refused until the choice is made, because that choice is the
            // owner's and not this schema's.
            $table->string('salary_disclosure', 20)->nullable();

            $table->bigInteger('salary_min_minor')->nullable();
            $table->bigInteger('salary_max_minor')->nullable();
            $table->char('salary_currency', 3)->nullable();
            $table->string('pay_period', 20)->nullable();

            // fixed / commission / mixed. A commission rate is a number only
            // if somebody supplies it; nothing here fabricates one.
            $table->string('compensation_type', 20)->nullable();
            $table->text('compensation_note')->nullable();

            // A small set of role-specific questions on the application form.
            $table->json('screening_questions')->nullable();

            $table->foreignId('assessment_template_id')->nullable()
                ->constrained('assessment_templates')->nullOnDelete();

            // Null falls back to config('recruitment.assessment.due_working_days').
            $table->unsignedTinyInteger('assessment_due_working_days')->nullable();

            $table->boolean('intro_video_enabled')->default(false);
            $table->boolean('intro_video_required')->default(false);
            $table->string('intro_video_mode', 20)->nullable();

            // Off unless somebody turns it on for this role AND says why.
            // A guarantor is not a professional reference and the two are kept
            // apart deliberately.
            $table->boolean('guarantors_required')->default(false);
            $table->text('guarantor_reason')->nullable();
            $table->boolean('identity_documents_required')->default(false);

            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable()->index();

            $table->foreignId('hiring_lead_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            // What happens to candidates still in the pipeline when the
            // vacancy closes. Recorded because closing a vacancy must never
            // silently reject people — somebody has to say so.
            $table->string('on_close_action', 30)->nullable();

            // Notes the hiring team keeps for itself. Never rendered on the
            // advert, never in an email, never on a candidate screen.
            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The public listing: published vacancies, newest first.
            $table->index(['state', 'published_at']);
        });

        Schema::create('vacancy_reviewers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            // One assignment per person per vacancy.
            $table->unique(['vacancy_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_reviewers');
        Schema::dropIfExists('vacancies');
        Schema::dropIfExists('assessment_templates');
    }
};
