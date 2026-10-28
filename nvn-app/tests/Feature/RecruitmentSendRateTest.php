<?php

namespace Tests\Feature;

use App\Jobs\InviteSelectionToAssessment;
use App\Models\Candidate;
use App\Models\RecruitmentMessage;
use App\Services\Recruitment\CandidateAccessService;
use App\Services\Recruitment\RecruitmentOutbox;
use App\Support\RecruitmentTemplates;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * How fast candidate email is allowed to leave, and what overtakes it.
 *
 * The queue already guaranteed that nothing is blasted: one row per recipient,
 * sent one at a time. What it did not guarantee was a rate. Four thousand
 * invitations would have gone out as fast as the cron could drain them, which
 * is within every technical limit and is still the thing that gets a young
 * sending domain filtered — and is over the hourly relay cap most shared hosts
 * enforce, which turns it into bounces from our own server.
 *
 * The risk in adding a ceiling is the one these tests are mostly about. Candidate
 * sign-in links go through this same outbox and expire in thirty minutes. A
 * naive cap would park them behind a batch of four thousand and every candidate
 * trying to check their status that day would get a link that was dead on
 * arrival — the portal would simply look broken, and nothing would say why. So
 * the cap applies to one class of mail and the other class overtakes it.
 */
class RecruitmentSendRateTest extends TestCase
{
    use RefreshDatabase;
    use RecruitmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        Settings::flush();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Settings::flush();

        parent::tearDown();
    }

    private function outbox(): RecruitmentOutbox
    {
        return app(RecruitmentOutbox::class);
    }

    /**
     * Queue $count paceable messages directly.
     *
     * Written at this level rather than by inviting candidates because the
     * subject here is the drain, not what fills it, and a hundred real
     * invitations would be a hundred assignments and template snapshots
     * standing in the way of reading the test.
     */
    private function queuePaceable(int $count, string $template = RecruitmentTemplates::ACKNOWLEDGEMENT): void
    {
        $vacancy = $this->makePublishedVacancy();

        for ($i = 0; $i < $count; $i++) {
            RecruitmentMessage::create([
                'event_key'       => $template . ':seeded:' . $i,
                'mailable'        => 'App\Mail\CandidateMail',
                'template_key'    => $template,
                'recipient_email' => 'paceable' . $i . '@example.test',
                'status'          => RecruitmentMessage::PENDING,
                'available_at'    => now(),
                'attempts'        => 0,
            ]);
        }
    }

    private function sentCount(?string $template = null): int
    {
        $query = RecruitmentMessage::query()->where('status', RecruitmentMessage::SENT);

        if ($template !== null) {
            $query->where('template_key', $template);
        }

        return $query->count();
    }

    public function test_a_batch_larger_than_the_hourly_cap_sends_only_its_allowance(): void
    {
        config(['recruitment.outbox.per_hour' => 60]);

        $this->queuePaceable(200);

        // The run limit is deliberately far above the cap, so that what stops
        // the run is the rate and nothing else.
        $this->outbox()->dispatchDue(200);

        // Sixty an hour is five in a five-minute window, and the window is
        // what makes the sending even instead of one burst at the top of the
        // hour. Either way the hour's ceiling is never the thing exceeded.
        $this->assertSame(5, $this->sentCount());
        $this->assertSame(195, RecruitmentMessage::query()->where('status', RecruitmentMessage::PENDING)->count());
    }

    public function test_no_rolling_hour_ever_exceeds_the_cap(): void
    {
        config(['recruitment.outbox.per_hour' => 24]);

        $this->queuePaceable(300);

        // The invariant worth holding is not a total — it is that you cannot
        // find an hour anywhere in the run that carried more than the cap.
        // Stepped in threes rather than fives so the runs land out of phase
        // with the window and the check is not simply reading back its own
        // arithmetic.
        for ($tick = 0; $tick < 40; $tick++) {
            $this->outbox()->dispatchDue(300);

            $inLastHour = RecruitmentMessage::query()
                ->where('status', RecruitmentMessage::SENT)
                ->where('sent_at', '>=', now()->subHour())
                ->count();

            $this->assertLessThanOrEqual(24, $inLastHour, 'An hour carried more than the cap allows.');

            $this->travel(3)->minutes();
        }

        // And it was genuinely sending throughout, so the assertion above was
        // not passing merely because nothing ever moved.
        $this->assertGreaterThan(24, $this->sentCount());
    }

    public function test_the_allowance_refills_once_the_hour_has_passed(): void
    {
        config(['recruitment.outbox.per_hour' => 12]);

        $this->queuePaceable(40);

        $this->outbox()->dispatchDue(40);
        $sentFirst = $this->sentCount();

        $this->assertGreaterThan(0, $sentFirst);

        $this->travel(61)->minutes();
        $this->outbox()->dispatchDue(40);

        $this->assertGreaterThan($sentFirst, $this->sentCount());
    }

    /**
     * The one that matters most. A candidate asking to check their status while
     * a batch of four thousand is draining must still be able to sign in.
     */
    public function test_a_sign_in_link_is_sent_while_the_cap_is_fully_spent(): void
    {
        config(['recruitment.outbox.per_hour' => 5]);

        $this->queuePaceable(100);

        // Spend the whole hour's allowance. Five an hour is one per window, so
        // this takes five windows rather than one run — which is the pacing
        // doing exactly what it is for.
        for ($window = 0; $window < 5; $window++) {
            $this->outbox()->dispatchDue(100);
            $this->travel(5)->minutes();
        }

        $this->assertSame(5, $this->sentCount());
        $this->assertLessThanOrEqual(0, $this->outbox()->allowance());

        // Now somebody asks for a link, behind ninety-five queued invitations.
        $application = $this->makeApplication($this->makePublishedVacancy());
        $candidate   = $application->candidate;

        app(CandidateAccessService::class)->requestLink($candidate->email);

        $this->outbox()->dispatchDue(100);

        $link = RecruitmentMessage::query()
            ->where('template_key', RecruitmentTemplates::SIGN_IN)
            ->sole();

        $this->assertSame(RecruitmentMessage::SENT, $link->status);

        // And it did not eat the invitations' allowance on the way past.
        $this->assertSame(5, $this->sentCount(RecruitmentTemplates::ACKNOWLEDGEMENT));
    }

    public function test_priority_mail_is_not_counted_against_the_cap(): void
    {
        config(['recruitment.outbox.per_hour' => 10]);

        $before = $this->outbox()->allowance();

        $this->queuePaceable(5, RecruitmentTemplates::OFFER_SENT);
        $this->outbox()->dispatchDue(50);

        $this->assertSame(5, $this->sentCount(RecruitmentTemplates::OFFER_SENT));

        // Five offers went out and the paceable allowance is untouched, which
        // is the whole point of the split.
        $this->assertSame($before, $this->outbox()->allowance());
    }

    public function test_a_message_that_never_left_does_not_spend_the_allowance(): void
    {
        config(['recruitment.outbox.per_hour' => 60]);

        // A cancelled message is the module declining to write to somebody.
        // Nothing reached a mail server, so nothing should be charged for it.
        $vacancy     = $this->makePublishedVacancy();
        $application = $this->makeApplication($vacancy);

        RecruitmentMessage::create([
            'event_key'       => 'cancelled:one',
            'mailable'        => 'App\Mail\CandidateMail',
            'template_key'    => RecruitmentTemplates::ACKNOWLEDGEMENT,
            'recipient_email' => 'cancelled@example.test',
            'status'          => RecruitmentMessage::CANCELLED,
            'cancelled_at'    => now(),
            'attempts'        => 1,
        ]);

        $this->assertSame(5, $this->outbox()->allowance());
    }

    public function test_a_cap_of_zero_means_no_ceiling(): void
    {
        config(['recruitment.outbox.per_hour' => 0]);

        $this->queuePaceable(40);

        $this->outbox()->dispatchDue(40);

        $this->assertSame(40, $this->sentCount());
    }

    public function test_a_tiny_cap_still_sends_rather_than_stalling(): void
    {
        // One an hour divided across twelve windows rounds to zero if the floor
        // is not taken, and the outbox would sit still forever.
        config(['recruitment.outbox.per_hour' => 1]);

        $this->queuePaceable(10);

        $this->outbox()->dispatchDue(10);

        $this->assertSame(1, $this->sentCount());
    }

    public function test_a_small_selection_is_still_invited_on_the_spot(): void
    {
        Queue::fake();

        $vacancy      = $this->makePublishedVacancy([], $this->makeTemplate());
        $applications = collect(range(1, 3))->map(fn () => $this->makeApplication($vacancy));

        app(\App\Services\Recruitment\AssessmentService::class)->invite(
            $applications->first(),
            $this->makeRecruiter(),
            [],
        );

        // Nothing was handed to the queue: below the threshold the bulk action
        // does the work in the request, and its on-screen report is better than
        // an alert arriving later.
        Queue::assertNotPushed(InviteSelectionToAssessment::class);
    }

    public function test_a_large_selection_is_worked_through_in_chunks_and_reported_once(): void
    {
        $vacancy = $this->makePublishedVacancy([], $this->makeTemplate());
        $actor   = $this->makeRecruiter();

        $ids = collect(range(1, InviteSelectionToAssessment::CHUNK + 10))
            ->map(fn () => $this->makeApplication($vacancy)->getKey())
            ->all();

        Queue::fake();

        (new InviteSelectionToAssessment($ids, [], $actor->getKey()))
            ->handle(app(\App\Services\Recruitment\AssessmentService::class));

        // One chunk done, the rest queued carrying the tally forward, so a
        // worker stopped at any point loses nothing.
        Queue::assertPushed(InviteSelectionToAssessment::class, function ($job) {
            return count($job->applicationIds) === 10
                && $job->invited === InviteSelectionToAssessment::CHUNK;
        });
    }

    public function test_someone_past_that_point_is_named_rather_than_invited_by_the_job(): void
    {
        $vacancy = $this->makePublishedVacancy([], $this->makeTemplate());
        $actor   = $this->makeRecruiter();

        $past = $this->forceStage(
            $this->makeApplication($vacancy),
            \App\Enums\ApplicationStage::Interview,
        );

        $job = new InviteSelectionToAssessment([$past->getKey()], [], $actor->getKey());
        $job->handle(app(\App\Services\Recruitment\AssessmentService::class));

        $this->assertSame(0, $job->invited);
        $this->assertSame(
            [$past->reference],
            $job->skipped['They are past the point where an exercise is sent.'] ?? [],
        );
    }

    public function test_the_job_cannot_outlive_the_authority_that_started_it(): void
    {
        $vacancy = $this->makePublishedVacancy([], $this->makeTemplate());

        // A reviewer has no MOVE_STAGE, and the check is inside invite()
        // against the stored actor rather than the session — so a queued job
        // started by someone who could not do this still cannot do it.
        $reviewer    = $this->makeRecruiter(\App\Support\RecruitmentPermission::REVIEWER);
        $application = $this->makeApplication($vacancy);

        $job = new InviteSelectionToAssessment([$application->getKey()], [], $reviewer->getKey());
        $job->handle(app(\App\Services\Recruitment\AssessmentService::class));

        $this->assertSame(0, $job->invited);
        $this->assertSame(
            \App\Enums\ApplicationStage::Applied,
            $application->fresh()->stage,
        );
    }

    public function test_the_outbox_drain_runs_with_a_configured_limit(): void
    {
        $this->artisan('schedule:list');

        $drain = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->first(fn (string $command) => str_contains($command, 'nvn:recruitment-dispatch'));

        $this->assertNotNull($drain);

        // On a host whose cron will not fire more often than every five
        // minutes, the per-run bound is the hour's real ceiling: the rate
        // limit cannot hand out more than twelve runs can carry. Left at the
        // command's own default it is invisible, and a backlog that has to
        // clear by a named date silently takes four times as long as the
        // configured rate promises.
        $this->assertStringContainsString(
            '--limit=' . config('recruitment.outbox.per_run'),
            $drain,
        );
    }
}
