<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\JobApplication;
use App\Models\RecruitmentMessage;
use App\Support\EmailNormalizer;
use App\Support\RecruitmentPrivacyNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * The application form: what it keeps, what it refuses to keep, and what it
 * does when the post office is shut.
 *
 * The central claim being tested is that an application is saved whether or
 * not mail works. Everything a candidate is told goes into an outbox row
 * inside the same transaction, and the sending happens later, from cron. A
 * form that saved nothing because SMTP was down would lose applications
 * silently, and nobody would report it — the candidate simply never hears
 * back and assumes the answer was no.
 */
class RecruitmentApplicationTest extends TestCase
{
    use RefreshDatabase, RecruitmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        \App\Support\Settings::flush();
        Storage::fake('private');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        \App\Support\Settings::flush();

        parent::tearDown();
    }

    public function test_an_application_is_saved_with_a_readable_reference(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'intake-basic']);

        $this->post(route('careers.apply.store', $vacancy), $this->applicationFormPayload())
            ->assertRedirectContains('/applied/');

        $application = JobApplication::firstOrFail();

        $this->assertSame('applied', $application->stage->value);
        $this->assertNotEmpty($application->reference);
        $this->assertNotNull($application->applied_at);
        $this->assertSame($vacancy->id, $application->vacancy_id);
    }

    public function test_the_application_survives_mail_being_broken(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'mail-down']);

        // Nothing here mocks a failure in the mailer, because the point is
        // that the mailer is never reached during the request: what is written
        // is an outbox row, and the send is somebody else's later problem.
        $application = $this->makeApplication($vacancy);

        $this->assertTrue($application->exists);
        Mail::assertNothingSent();

        $this->assertSame(
            1,
            RecruitmentMessage::where('job_application_id', $application->id)
                ->where('status', RecruitmentMessage::PENDING)
                ->count(),
        );
    }

    public function test_a_second_application_to_the_same_vacancy_is_not_a_duplicate(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'no-duplicates']);

        $first  = $this->makeApplication($vacancy, ['email' => 'repeat@example.test']);
        $second = $this->makeApplication($vacancy, ['email' => 'repeat@example.test']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JobApplication::where('vacancy_id', $vacancy->id)->count());
        $this->assertSame(1, Candidate::count());
    }

    public function test_the_same_person_may_apply_to_a_different_vacancy(): void
    {
        $one = $this->makePublishedVacancy(['slug' => 'role-one']);
        $two = $this->makePublishedVacancy(['slug' => 'role-two']);

        $this->makeApplication($one, ['email' => 'both@example.test']);
        $this->makeApplication($two, ['email' => 'both@example.test']);

        $this->assertSame(2, JobApplication::count());
        $this->assertSame(1, Candidate::count());
    }

    public function test_a_repeat_submission_does_not_disclose_that_it_is_a_repeat(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'quiet-repeat']);

        $payload = $this->applicationFormPayload(['email' => 'twice@example.test']);
        $again   = $this->applicationFormPayload(['email' => 'twice@example.test']);

        $first  = $this->post(route('careers.apply.store', $vacancy), $payload);
        $second = $this->post(route('careers.apply.store', $vacancy), $again);

        // Same shape of answer both times. A different one would let anybody
        // test whether a given address had applied for a job.
        $first->assertRedirectContains('/applied/');
        $second->assertRedirectContains('/applied/');

        $this->assertSame(1, JobApplication::count());
    }

    public function test_dots_and_plus_aliases_are_not_stripped_indiscriminately(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'aliases']);

        // first.last@ and firstlast@ are the same mailbox at some providers and
        // two different people at others. Treating them as one would merge two
        // strangers' applications, which is far worse than allowing a repeat.
        $this->makeApplication($vacancy, ['email' => 'first.last@example.test']);
        $this->makeApplication($vacancy, ['email' => 'firstlast@example.test']);

        $this->assertSame(2, JobApplication::count());
        $this->assertSame(2, Candidate::count());
    }

    public function test_the_address_is_matched_case_insensitively(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'case-fold']);

        $this->makeApplication($vacancy, ['email' => 'Shouty@Example.Test']);
        $this->makeApplication($vacancy, ['email' => 'shouty@example.test']);

        $this->assertSame(1, JobApplication::count());
        $this->assertSame(
            EmailNormalizer::canonical('shouty@example.test'),
            Candidate::firstOrFail()->email_canonical,
        );
    }

    public function test_the_privacy_acknowledgment_records_its_version_and_time(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'consent-stamp']);

        $application = $this->makeApplication($vacancy);

        // A consent record that does not say which text was agreed to is not a
        // consent record; it is a tick.
        $this->assertSame(RecruitmentPrivacyNotice::version(), $application->privacy_version);
        $this->assertNotNull($application->privacy_accepted_at);
        $this->assertSame(RecruitmentPrivacyNotice::version(), $application->candidate->privacy_version);
    }

    public function test_future_opportunity_consent_is_separate_and_off_by_default(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'separate-consent']);

        $plain = $this->makeApplication($vacancy, ['email' => 'plain@example.test']);
        $this->assertFalse((bool) $plain->candidate->future_opportunities_consent);
        $this->assertNull($plain->candidate->future_opportunities_consent_at);

        $opted = $this->makeApplication($vacancy, [
            'email'                        => 'opted@example.test',
            'future_opportunities_consent' => true,
        ]);

        $this->assertTrue((bool) $opted->candidate->future_opportunities_consent);
        $this->assertNotNull($opted->candidate->future_opportunities_consent_at);
    }

    public function test_nothing_sensitive_is_collected_at_this_stage(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'no-sensitive-fields']);

        $page = $this->get(route('careers.apply', $vacancy));
        $page->assertOk();

        // The form is checked for the input names themselves rather than for
        // labels, because a label can be reworded and a name is what posts.
        foreach ([
            'name="date_of_birth"', 'name="marital_status"', 'name="religion"',
            'name="bvn"', 'name="nin"', 'name="bank_account"', 'name="account_number"',
            'name="guarantor', 'name="photograph"', 'name="passport_photo"',
            'name="id_number"', 'name="gender"',
        ] as $forbidden) {
            $page->assertDontSee($forbidden, false);
        }

        // And nowhere to put them even if a form were crafted by hand.
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('job_applications');

        foreach (['date_of_birth', 'marital_status', 'religion', 'bvn', 'nin', 'gender', 'photograph'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }
}
