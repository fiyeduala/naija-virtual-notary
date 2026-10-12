<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessments, scores, interviews, offers, onboarding and the mail outbox.
 *
 * The two ideas this migration exists to support:
 *
 *   A snapshot beats a reference. An assignment carries the template's tasks,
 *   rubric and brief as they were when it was created. An administrator
 *   improving a template next week must not change the exercise somebody is
 *   halfway through, and must not change what a reviewer was marking against
 *   after the fact.
 *
 *   Overdue and accepted are substates, not stages. `assessment_assignments
 *   .status` holds overdue; `offers.status` holds accepted. Neither is a
 *   stage on the application, because a candidate who submits an hour late
 *   has still submitted, and accepting an offer is not the same as being
 *   hired with checks resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            $table->foreignId('assessment_template_id')->nullable()
                ->constrained('assessment_templates')->nullOnDelete();

            $table->unsignedInteger('template_version')->default(1);

            // The whole exercise as assigned: tasks, rubric, brief, scale.
            // This is what the candidate sees and what the reviewer marks.
            $table->json('template_snapshot')->nullable();

            // invited / submitted / overdue / reopened / cancelled
            $table->string('status', 20)->default('invited')->index();

            $table->timestamp('invited_at')->nullable();

            // Three working days after dispatch by default, at a fixed hour in
            // the business timezone, stored UTC.
            $table->timestamp('due_at')->nullable()->index();

            // Kept so an extension has a history rather than just a new date.
            $table->timestamp('original_due_at')->nullable();
            $table->unsignedTinyInteger('extension_count')->default(0);
            $table->timestamp('extended_at')->nullable();
            $table->foreignId('extended_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();

            // A finalised submission is read-only unless a reviewer reopens
            // it, and reopening is recorded with who did it and why.
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            // Optional, off unless the vacancy asks for it. A link, not an
            // upload, and never embedded on an admin screen.
            $table->string('intro_video_url', 2048)->nullable();

            // The equivalent for anybody who would rather not be on camera.
            // Required to exist wherever the video is offered.
            $table->longText('intro_alternative')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_application_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('assessment_responses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assessment_assignment_id')->constrained()->cascadeOnDelete();

            $table->string('task_key', 60);
            $table->string('task_label', 255);
            $table->string('task_type', 20)->default('long_text');

            $table->longText('answer')->nullable();

            // Drafts are revisions; the final submission is the revision with
            // is_final set. Earlier revisions are kept, so a reopened task
            // does not destroy what was originally submitted.
            $table->boolean('is_final')->default(false);
            $table->unsignedInteger('revision')->default(1);

            $table->timestamps();

            $table->index(['assessment_assignment_id', 'task_key', 'revision']);
            $table->index(['assessment_assignment_id', 'is_final']);
        });

        Schema::create('reviewer_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_assignment_id')->nullable()
                ->constrained('assessment_assignments')->cascadeOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('criterion_key', 60);
            $table->string('criterion_label', 255);

            // NULLABLE on purpose. A criterion nobody has scored is a visible
            // gap on the screen, not a silent zero, because "scored 0" and
            // "not yet looked at" are completely different facts.
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('scale_max')->default(5);

            $table->text('comment')->nullable();

            $table->timestamps();

            // One score per criterion per reviewer per application.
            $table->unique(['job_application_id', 'user_id', 'criterion_key'], 'reviewer_scores_unique');
        });

        Schema::create('reviewer_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // advance / hold / decline. A recommendation from a person, which
            // somebody else still has to act on. Nothing in this module reads
            // a score or a recommendation and moves a stage by itself.
            $table->string('recommendation', 20)->nullable();

            $table->text('summary')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->unique(['job_application_id', 'user_id']);
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            // A second interview exists when somebody schedules one, and not
            // because the process says every role has two.
            $table->unsignedTinyInteger('round')->default(1);

            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);

            // The zone the time was agreed in, kept so the invitation can
            // show both the office's time and the candidate's.
            $table->string('timezone', 64)->nullable();

            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();

            // Typed in by hand. Provisioning a Meet or Zoom room is out of
            // scope and pretending otherwise would mean an invitation with a
            // link that goes nowhere.
            $table->string('meeting_url', 2048)->nullable();

            $table->text('candidate_instructions')->nullable();

            // scheduled / rescheduled / cancelled / completed / no_show
            $table->string('status', 20)->default('scheduled')->index();

            // Bumped on every change and written into the calendar file. A
            // calendar client ignores an update whose SEQUENCE is not higher
            // than the one it holds, so a reschedule that forgets this is
            // silently discarded and the candidate arrives at the old time.
            $table->unsignedInteger('ics_sequence')->default(0);

            $table->timestamp('candidate_acknowledged_at')->nullable();
            $table->timestamp('reschedule_requested_at')->nullable();
            $table->text('reschedule_request_note')->nullable();

            // Internal. No candidate-facing route reads these three columns.
            $table->longText('feedback')->nullable();
            $table->foreignId('feedback_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('feedback_at')->nullable();

            $table->string('outcome', 20)->nullable();
            $table->text('salary_discussion_note')->nullable();
            $table->text('decision_note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_application_id', 'scheduled_at']);
            $table->index(['scheduled_at', 'status']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            // draft / approved / sent / accepted / declined / withdrawn.
            // `accepted` lives here and not on the application's stage: checks
            // may still be outstanding and the two must not be confused.
            $table->string('status', 20)->default('draft')->index();

            // Either an approved template was filled in, or a document was
            // uploaded. Nothing composes employment terms on its own.
            $table->string('template_key', 60)->nullable();
            $table->longText('body')->nullable();

            $table->foreignId('document_id')->nullable()
                ->constrained('application_documents')->nullOnDelete();

            $table->bigInteger('salary_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('pay_period', 20)->nullable();
            $table->string('compensation_type', 20)->nullable();
            $table->text('compensation_note')->nullable();

            $table->date('start_date')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // A candidate clicking "I accept" is a recorded indication of
            // intent. It is not a certified electronic signature and the
            // wording shown to them says so.
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable();
            $table->text('decline_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('onboarding_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            $table->string('item_key', 60);
            $table->string('label', 255);

            // What the candidate is being asked for, and WHY. The why is
            // not decoration: a person asked for a guarantor's details is
            // entitled to be told the purpose before they hand over
            // somebody else's name and number.
            $table->text('instructions')->nullable();

            // document / reference / confirmation
            $table->string('kind', 20)->default('document');

            // Identity paperwork. Visible only to a user holding
            // sensitive.view, and left out of ordinary exports entirely.
            $table->boolean('is_sensitive')->default(false)->index();

            $table->boolean('is_required')->default(true);

            // pending / submitted / verified / not_verified / waived.
            // A submitted document is not a verified one: somebody has to
            // look at it and say so.
            $table->string('status', 20)->default('pending')->index();

            $table->foreignId('document_id')->nullable()
                ->constrained('application_documents')->nullOnDelete();

            $table->text('note')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_application_id', 'status']);
        });

        Schema::create('reference_contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            // reference / guarantor. Two different things: a referee speaks to
            // your work, a guarantor accepts a liability. Collapsing them into
            // one word is how a guarantor ends up demanded of everybody.
            $table->string('contact_type', 20)->default('reference')->index();

            $table->string('name', 160);
            $table->string('relationship', 120)->nullable();
            $table->string('organisation', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();

            // What the candidate was told this person would be contacted for.
            $table->text('purpose_shown')->nullable();

            // The candidate confirms the person agreed to be approached. We
            // still do not write to them until a member of staff takes a
            // separate, deliberate action.
            $table->boolean('consent_confirmed')->default(false);
            $table->timestamp('consent_confirmed_at')->nullable();

            $table->timestamp('contacted_at')->nullable();
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('outcome', 20)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recruitment_messages', function (Blueprint $table) {
            $table->id();

            // The transactional outbox. Nothing in this module calls Mail
            // directly: a row is written inside the same transaction as the
            // change that justifies it, and a worker sends it afterwards. A
            // transaction that rolls back takes its email with it, and a
            // committed change cannot lose its email to a crash.
            //
            // The key is the idempotency guarantee: one row per
            // (type, application, version). A retry, a double-click or a
            // concurrent reviewer cannot produce a second acknowledgment.
            $table->string('event_key', 190)->unique();

            $table->foreignId('job_application_id')->nullable()
                ->constrained('job_applications')->cascadeOnDelete();
            $table->foreignId('candidate_id')->nullable()
                ->constrained('candidates')->cascadeOnDelete();

            $table->string('mailable', 190);
            $table->string('template_key', 60)->nullable();

            // Copied at write time, so a purge or a corrected address cannot
            // silently redirect a queued message.
            $table->string('recipient_email', 190);
            $table->string('recipient_name', 160)->nullable();

            // Ids, labels and deadlines. Never a login token, never a
            // document's contents, never a full sensitive answer.
            $table->json('payload')->nullable();

            // pending / sent / failed / cancelled / superseded
            $table->string('status', 20)->default('pending')->index();

            // The invitation delay lives here: zero, one, two or three working
            // days, chosen by an administrator. Deliberate, never randomised.
            $table->timestamp('available_at')->nullable()->index();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 500)->nullable();

            // Accepted by the provider is NOT delivered to the person. This
            // column only ever says `delivered` if a provider event said so,
            // and this installation has no such feed, so it stays null and
            // the screen says "sent" rather than claiming delivery.
            $table->string('delivery_status', 20)->nullable();
            $table->timestamp('delivery_confirmed_at')->nullable();

            // Why a pending message was called off: the candidate withdrew,
            // the stage moved on, the interview was rescheduled.
            $table->string('cancelled_reason', 190)->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // The worker's read: what is due to go out now.
            $table->index(['status', 'available_at']);
            $table->index(['job_application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_messages');
        Schema::dropIfExists('reference_contacts');
        Schema::dropIfExists('onboarding_items');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('reviewer_reviews');
        Schema::dropIfExists('reviewer_scores');
        Schema::dropIfExists('assessment_responses');
        Schema::dropIfExists('assessment_assignments');
    }
};
