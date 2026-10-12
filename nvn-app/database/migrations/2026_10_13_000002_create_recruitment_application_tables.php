<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidates, their passwordless access, and the applications themselves.
 *
 * A candidate is NOT a user. They have their own table, their own guard and
 * their own session, and nothing in this migration touches `users`. An
 * applicant becoming a client, a notary or a member of staff would be a
 * decision somebody makes deliberately, not a side effect of applying for a
 * job.
 *
 * What is deliberately absent from these columns: date of birth, marital
 * status, religion, a photograph, a government ID number, bank details,
 * guarantor details. The initial application does not ask for any of it.
 * Identity documents appear later, as rows in `application_documents` flagged
 * sensitive, and only when a vacancy switches them on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();

            $table->string('full_name', 160);

            // Two columns on purpose. `email` is what they typed and what we
            // write to; `email_canonical` is the trimmed, lowercased form the
            // unique index is built on. Dots and plus-aliases are NOT stripped
            // — that is Gmail-specific, and treating
            // first.last@company.com as the same person as
            // firstlast@company.com would reject the second one for good.
            $table->string('email', 190);
            $table->string('email_canonical', 190)->unique();

            $table->string('phone', 40)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('timezone', 64)->nullable();

            // Which version of the recruitment privacy notice they agreed to,
            // and when. The version string is bumped whenever the text changes.
            $table->string('privacy_version', 40)->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();

            // A separate, unchecked-by-default choice. Being considered for
            // future openings is not part of applying for this one.
            $table->boolean('future_opportunities_consent')->default(false);
            $table->timestamp('future_opportunities_consent_at')->nullable();

            // No password column. Access is a short-lived emailed token
            // exchanged for a session; there is no credential here to steal.
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('last_login_at')->nullable();

            // Set when a retention purge has stripped the personal fields but
            // the counts have to stay. Not a soft delete — the row is still
            // live, it just no longer identifies anybody.
            $table->timestamp('anonymised_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('candidate_login_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();

            // The HASH of the token, never the token. char(64) and not text,
            // because MySQL refuses a unique index on a TEXT column (1170).
            $table->char('token_hash', 64)->unique();

            $table->timestamp('expires_at')->index();

            // One use. Set the moment it is exchanged for a session, and a
            // second attempt with the same link fails.
            $table->timestamp('consumed_at')->nullable();

            // A salted hash, so a flood of requests can be spotted without
            // keeping a log of where candidates live.
            $table->char('requester_hash', 64)->nullable();

            // Where to send them after sign-in. A path on this site only,
            // validated before use — never an absolute URL from the request.
            $table->string('intended_path', 190)->nullable();

            $table->timestamps();

            $table->index(['candidate_id', 'consumed_at']);
        });

        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();

            // What the candidate quotes in an email. Not a credential:
            // knowing a reference grants no access to anything.
            $table->string('reference', 32)->unique();

            // Restrict, not cascade. Deleting a vacancy must not take a pile
            // of applications with it; vacancies are archived instead.
            $table->foreignId('vacancy_id')->constrained()->restrictOnDelete();

            // Cascade, because deleting a candidate IS the retention purge,
            // and it has to reach everything that describes them.
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();

            $table->string('stage', 30)->default('applied')->index();
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->longText('motivation')->nullable();
            $table->longText('relevant_experience')->nullable();

            // Optional, and optional means optional: nothing here requires a
            // social account, and a missing link never counts against anyone.
            $table->string('linkedin_url', 2048)->nullable();
            $table->string('portfolio_url', 2048)->nullable();
            $table->string('work_sample_url', 2048)->nullable();

            // Minor units, explicit currency, explicit period. A range is
            // allowed because that is how people answer the question.
            $table->bigInteger('expected_salary_min_minor')->nullable();
            $table->bigInteger('expected_salary_max_minor')->nullable();
            $table->char('expected_salary_currency', 3)->nullable();
            $table->string('expected_pay_period', 20)->nullable();

            $table->date('availability_start')->nullable();
            $table->string('notice_period', 80)->nullable();

            // Remote working facts. Asked because the job genuinely depends on
            // them, not as a proxy for anything else.
            $table->boolean('available_during_hours')->nullable();
            $table->boolean('has_suitable_computer')->nullable();
            $table->string('internet_reliability', 40)->nullable();
            $table->string('backup_arrangements', 40)->nullable();
            $table->text('remote_experience')->nullable();

            $table->string('heard_about', 60)->nullable();

            // Allowlisted and length-limited before they get here. These end
            // up on an admin screen and in a CSV, so unbounded query-string
            // text is a stored-XSS and formula-injection vector at once.
            $table->string('source_channel', 40)->nullable()->index();
            $table->string('source_campaign', 40)->nullable();
            $table->string('source_medium', 40)->nullable();

            $table->string('privacy_version', 40)->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();

            // The screening questions AS PUBLISHED when they applied. An
            // administrator editing the vacancy tomorrow must not change what
            // this person was asked, or their answers stop making sense.
            $table->json('screening_snapshot')->nullable();

            $table->timestamp('rejected_at')->nullable();

            // Internal. Never shown to a candidate and never emailed.
            $table->text('rejection_reason')->nullable();

            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();

            $table->timestamp('hired_at')->nullable();
            $table->foreignId('hired_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // One application per person per vacancy, enforced here rather
            // than by a check-then-insert that two concurrent submissions
            // would both pass. Applying for a DIFFERENT role is fine.
            $table->unique(['vacancy_id', 'candidate_id']);

            // The admin pipeline read.
            $table->index(['stage', 'created_at']);
            $table->index(['vacancy_id', 'stage']);
        });

        Schema::create('application_answers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            $table->string('question_key', 60);

            // The label is copied, not looked up. The question may be edited
            // or removed from the vacancy later, and the answer still has to
            // say what it was answering.
            $table->string('question_label', 255);
            $table->string('question_type', 20)->default('text');

            $table->longText('answer')->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->index(['job_application_id', 'question_key']);
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            // Denormalised on purpose: a retention purge works from the
            // candidate, and it must be able to find every file of theirs
            // without walking every application first.
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();

            // cv / assessment_attachment / identity / guarantor / other
            $table->string('kind', 30)->index();

            // Always `private`. Named so a future disk change is visible in
            // the data rather than implied by code.
            $table->string('disk', 20)->default('private');

            // A random name generated on upload. The original never becomes a
            // path segment, so it cannot traverse anywhere.
            $table->string('path', 255);
            $table->string('original_name', 160);
            $table->string('mime', 160)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // unscanned / clean / infected. `unscanned` is the honest default
            // when no scanner is configured, and it is never rendered as
            // "clean". An infected file is refused before a row gets here.
            $table->string('scan_status', 20)->default('unscanned');
            $table->timestamp('scanned_at')->nullable();

            // Identity and guarantor paperwork. Reachable only by a user
            // holding sensitive.view, excluded from ordinary exports, and
            // never attached to an email.
            $table->boolean('is_sensitive')->default(false)->index();

            $table->boolean('uploaded_by_candidate')->default(true);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // A later CV does not overwrite an earlier one. The evidence a
            // reviewer scored has to stay exactly as it was.
            $table->unsignedInteger('version')->default(1);

            // pending / verified / not_verified. Uploading a document is not
            // proof of anything — somebody has to say they checked it, and
            // this records who and when.
            $table->string('verification_status', 20)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->text('verification_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_application_id', 'kind', 'version']);
        });

        Schema::create('application_stage_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);

            // staff / candidate / system. A withdrawal is the candidate's own
            // act and is recorded as such; a reminder expiring is the system's.
            $table->string('actor_type', 20)->default('staff');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Required by the service for a skip, a reopening or a rejection.
            $table->text('reason')->nullable();

            // Whether a stage was skipped, what was invalidated, and so on.
            // Never a document's contents and never a token.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['job_application_id', 'created_at']);
        });

        Schema::create('application_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Internal. There is no candidate-facing route that reads this
            // table, which is the only reliable way to keep it internal.
            $table->text('body');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_notes');
        Schema::dropIfExists('application_stage_events');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('application_answers');
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('candidate_login_tokens');
        Schema::dropIfExists('candidates');
    }
};
