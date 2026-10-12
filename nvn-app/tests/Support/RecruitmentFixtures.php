<?php

namespace Tests\Support;

use App\Enums\ApplicationStage;
use App\Enums\VacancyState;
use App\Models\AssessmentTemplate;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Recruitment\ApplicationIntakeService;
use App\Support\RecruitmentPermission;
use Illuminate\Http\UploadedFile;

/**
 * The fictional recruitment world.
 *
 * A sibling of Fixtures rather than part of it, so a test about payouts does
 * not load four job adverts it will never look at. Both traits can be used on
 * the same class; neither depends on the other.
 *
 * Everything here is invented. No real person, employer, salary or document
 * appears in any of it, and the one published vacancy is created with facts
 * that are deliberately complete — publication is refused otherwise, which is
 * itself one of the things these tests check.
 */
trait RecruitmentFixtures
{
    private int $recruitSeq = 0;

    /**
     * A vacancy that would actually pass publicationBlockers().
     *
     * Every field here is present because leaving it out blocks publication. A
     * fixture that quietly omitted one would make every other test in the file
     * fail for the wrong reason, which is how a suite stops being read.
     */
    protected function makeVacancy(array $overrides = [], ?AssessmentTemplate $template = null): Vacancy
    {
        $seq = ++$this->recruitSeq;

        return Vacancy::create(array_merge([
            'role_key'             => 'customer_service',
            'title'                => 'Test Role ' . $seq,
            'slug'                 => 'test-role-' . $seq,
            'department'           => 'Customer Operations',
            'state'                => VacancyState::Draft->value,
            'employment_type'      => 'Full time',
            'location_eligibility' => 'Nigeria only',
            'working_hours'        => '09:00 to 17:00',
            'hours_timezone'       => 'Africa/Lagos',
            'summary'              => 'A fictional role, created by the test suite.',
            'responsibilities'     => 'Answer fictional clients.',
            'requirements'         => 'Be fictional.',
            'salary_disclosure'    => Vacancy::SALARY_UNDISCLOSED,
            'closes_at'            => now()->addDays(30),
            'assessment_template_id'      => ($template ?? $this->makeTemplate())->id,
            'assessment_due_working_days' => 3,
        ], $overrides));
    }

    /** The same vacancy, already open to the public. */
    protected function makePublishedVacancy(array $overrides = [], ?AssessmentTemplate $template = null): Vacancy
    {
        $vacancy = $this->makeVacancy($overrides, $template);

        $vacancy->forceFill([
            'state'        => VacancyState::Published->value,
            'published_at' => now(),
        ])->save();

        return $vacancy->refresh();
    }

    /** A two-task exercise with a one-criterion rubric. Enough to score. */
    protected function makeTemplate(array $overrides = []): AssessmentTemplate
    {
        $seq = ++$this->recruitSeq;

        return AssessmentTemplate::create(array_merge([
            'role_key'           => 'customer_service',
            'name'               => 'Test exercise ' . $seq,
            'brief'              => 'Invented facts for an invented company.',
            'effort_minutes_min' => 30,
            'effort_minutes_max' => 45,
            'score_scale_max'    => 5,
            'is_active'          => true,
            'tasks' => [
                ['key' => 'one', 'label' => 'The first question.', 'type' => 'long_text', 'required' => true],
                ['key' => 'two', 'label' => 'An optional second.', 'type' => 'short_text', 'required' => false],
            ],
            'rubric' => [
                ['key' => 'clarity', 'label' => 'Clarity', 'anchors' => ['1' => 'Unclear', '5' => 'Clear']],
            ],
        ], $overrides));
    }

    /**
     * An application, made the way a real one is made.
     *
     * Through ApplicationIntakeService rather than JobApplication::create(),
     * because the reference, the candidate record, the privacy stamp, the
     * screening snapshot and the confirmation email are all its work. A test
     * that inserted the row directly would be asserting against a shape the
     * application never actually produces.
     */
    protected function makeApplication(Vacancy $vacancy, array $overrides = []): JobApplication
    {
        $seq = ++$this->recruitSeq;

        $result = app(ApplicationIntakeService::class)->submit(
            $vacancy,
            array_merge($this->applicationPayload($seq), $overrides),
            UploadedFile::fake()->create('cv' . $seq . '.pdf', 24, 'application/pdf'),
        );

        return $result['application'];
    }

    /**
     * Valid form input for one fictional applicant.
     *
     * The address domain is example.test, which by RFC cannot resolve and
     * therefore cannot reach anybody: a suite that posted to a live domain
     * would be one typo away from emailing a stranger.
     *
     * @return array<string, mixed>
     */
    protected function applicationPayload(?int $seq = null): array
    {
        $seq ??= ++$this->recruitSeq;

        return [
            'full_name' => 'Candidate ' . $seq,
            'email'     => 'candidate' . $seq . '@example.test',
            'phone'     => '+234800000' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'country'   => 'Nigeria',
            'city'      => 'Lagos',
            'timezone'  => 'Africa/Lagos',

            'motivation'          => 'I would like this fictional job because the exercise asked me to say so.',
            'relevant_experience' => 'Several invented years of invented experience, described at sufficient length.',

            'available_during_hours' => true,
            'has_suitable_computer'  => true,
            'internet_reliability'   => 'reliable',
            'backup_arrangements'    => 'inverter',

            'privacy_accepted'             => true,
            'future_opportunities_consent' => false,

            'answers' => [],
        ];
    }

    /** A member of staff with one recruitment role and nothing else. */
    protected function makeRecruiter(string $role = RecruitmentPermission::LEAD, array $overrides = []): User
    {
        $seq = ++$this->recruitSeq;

        $user = User::create(array_merge([
            'full_name'         => 'Recruiter ' . $seq,
            'email'             => 'recruiter' . $seq . '@example.test',
            'password'          => 'password',
            'role'              => 'client',
            'status'            => 'active',
            'email_verified_at' => now(),
        ], $overrides));

        // Set directly, exactly as the panel does: recruitment_role is not
        // mass-assignable, and a fixture that widened $fillable to make itself
        // convenient would be testing a different application.
        $user->recruitment_role = $role;
        $user->save();

        return $user->refresh();
    }

    /** An administrator, for the surfaces that require one. */
    protected function makeRecruitmentAdmin(array $overrides = []): User
    {
        $seq = ++$this->recruitSeq;

        return User::create(array_merge([
            'full_name'         => 'Admin ' . $seq,
            'email'             => 'recruitadmin' . $seq . '@example.test',
            'password'          => 'password',
            'role'              => 'admin',
            'status'            => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    /**
     * Put an application at a stage without pretending a person did it.
     *
     * forceFill, deliberately: this writes no stage event and no actor,
     * because nobody moved it. Any test that is about the transition itself
     * goes through ApplicationStageService instead.
     */
    protected function forceStage(JobApplication $application, ApplicationStage $stage): JobApplication
    {
        $application->forceFill([
            'stage'            => $stage->value,
            'stage_changed_at' => now(),
        ])->save();

        return $application->refresh();
    }

    /**
     * The same input, as a browser would post it: with the CV attached.
     *
     * Separate from applicationPayload() because the service takes the file as
     * its own argument while the form sends it in the body, and a test that
     * posted without it would be asserting against a validation failure while
     * believing it had asserted something about the vacancy.
     *
     * @return array<string, mixed>
     */
    protected function applicationFormPayload(array $overrides = []): array
    {
        return array_merge(
            $this->applicationPayload(),
            ['cv' => UploadedFile::fake()->create('cv.pdf', 24, 'application/pdf')],
            $overrides,
        );
    }
}
