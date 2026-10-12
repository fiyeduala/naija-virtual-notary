<?php

namespace Tests\Feature;

use App\Enums\VacancyState;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * The public careers pages, and the gate in front of publishing one.
 *
 * What is being defended here is a stranger's trust. A careers page is read by
 * somebody deciding whether to spend an evening on an exercise, and every
 * figure on it reads as a commitment. So:
 *
 *   - a draft is invisible, and a closed vacancy refuses an application on the
 *     server rather than merely hiding its button;
 *   - a vacancy cannot be published while any fact a candidate would rely on
 *     is missing, including an explicit choice about whether pay is disclosed;
 *   - nothing invents a salary.
 */
class RecruitmentCareersTest extends TestCase
{
    use RefreshDatabase, RecruitmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        \App\Support\Settings::flush();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        \App\Support\Settings::flush();

        parent::tearDown();
    }

    public function test_the_listing_shows_published_vacancies_only(): void
    {
        $live   = $this->makePublishedVacancy(['title' => 'Live Opening', 'slug' => 'live-opening']);
        $draft  = $this->makeVacancy(['title' => 'Secret Draft', 'slug' => 'secret-draft']);
        $closed = $this->makeVacancy([
            'title' => 'Finished Opening',
            'slug'  => 'finished-opening',
            'state' => VacancyState::Closed->value,
        ]);

        $response = $this->get(route('careers.index'));

        $response->assertOk();
        $response->assertSee('Live Opening');
        $response->assertDontSee('Secret Draft');
        $response->assertDontSee('Finished Opening');

        // The company's own name, every time. There is one correct spelling of
        // it and a careers page is the worst place to get it wrong.
        $response->assertSee('Naija Virtual Notary', false);
        $response->assertDontSee('Nigeria Virtual Lottery');

        $this->assertNotNull($live->published_at);
        $this->assertNull($draft->published_at);
        $this->assertSame(VacancyState::Closed, $closed->fresh()->state);
    }

    public function test_an_empty_listing_says_so_rather_than_breaking(): void
    {
        $this->get(route('careers.index'))
            ->assertOk()
            ->assertSee('Naija Virtual Notary', false);
    }

    public function test_a_draft_vacancy_is_not_reachable_by_url(): void
    {
        $draft = $this->makeVacancy(['slug' => 'not-yet']);

        $this->get(route('careers.show', $draft))->assertNotFound();
        $this->get(route('careers.apply', $draft))->assertNotFound();
    }

    public function test_a_closed_vacancy_refuses_an_application_on_the_server(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'closing-today']);

        // Open when the form was drawn, closed by the time it was posted —
        // exactly the case a hidden button does not cover.
        $vacancy->forceFill([
            'state'     => VacancyState::Closed->value,
            'closed_at' => now(),
        ])->save();

        // Refused, and refused kindly: the candidate is sent back to the
        // vacancy with the reason rather than onto an error page. What
        // matters for this test is that nothing was written.
        $this->post(route('careers.apply.store', $vacancy), $this->applicationFormPayload())
            ->assertRedirect(route('careers.show', $vacancy->slug))
            ->assertSessionHas('status');

        $this->assertSame(0, $vacancy->applications()->count());
        $this->assertSame(0, \App\Models\Candidate::count());
    }

    public function test_a_vacancy_past_its_closing_date_stops_accepting(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'deadline-passed']);

        $vacancy->forceFill(['closes_at' => now()->subDay()])->save();

        $this->assertFalse($vacancy->fresh()->acceptsApplications());
        $this->assertNotNull($vacancy->fresh()->closureReason());
    }

    public function test_publication_is_refused_while_any_fact_is_missing(): void
    {
        // Each of these is a promise to a stranger, and none can be guessed
        // from a codebase.
        foreach (['employment_type', 'location_eligibility', 'working_hours', 'closes_at', 'salary_disclosure'] as $field) {
            $vacancy = $this->makeVacancy([$field => null]);

            $this->assertFalse(
                $vacancy->canBePublished(),
                "A vacancy with no {$field} should not be publishable.",
            );
            $this->assertNotEmpty($vacancy->publicationBlockers());
        }
    }

    public function test_a_complete_vacancy_can_be_published(): void
    {
        $vacancy = $this->makeVacancy();

        $this->assertSame([], $vacancy->publicationBlockers());
        $this->assertTrue($vacancy->canBePublished());
    }

    public function test_disclosing_pay_requires_the_actual_figures(): void
    {
        $vacancy = $this->makeVacancy(['salary_disclosure' => Vacancy::SALARY_DISCLOSED]);

        // Having chosen to disclose, an empty figure is worse than no choice
        // at all: the advert would claim to show pay and show nothing.
        $this->assertFalse($vacancy->canBePublished());

        $vacancy->forceFill([
            'salary_min_minor' => 25000000,
            'salary_max_minor' => 35000000,
            'salary_currency'  => 'NGN',
            'pay_period'       => 'month',
        ])->save();

        $this->assertSame([], $vacancy->fresh()->publicationBlockers());
    }

    public function test_an_undisclosed_salary_is_never_rendered_as_a_figure(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'quiet-pay']);

        $this->get(route('careers.show', $vacancy))
            ->assertOk()
            ->assertDontSee('NGN 0');

        $this->assertNull($vacancy->salaryLine());
    }

    public function test_a_guarantor_requirement_needs_a_written_reason(): void
    {
        $vacancy = $this->makeVacancy(['guarantors_required' => true]);

        $this->assertFalse($vacancy->canBePublished());

        $vacancy->forceFill([
            'guarantor_reason' => 'This role handles client funds, which is why we ask.',
        ])->save();

        $this->assertSame([], $vacancy->fresh()->publicationBlockers());
    }

    public function test_the_whole_module_can_be_switched_off(): void
    {
        $vacancy = $this->makePublishedVacancy(['slug' => 'switched-off']);

        config(['recruitment.enabled' => false]);

        // Settings memoises per request and moduleEnabled() reads through
        // it, so without this flush the value answered earlier in this
        // test is still the one in hand.
        \App\Support\Settings::flush();

        $this->get(route('careers.index'))->assertNotFound();
        $this->get(route('careers.show', $vacancy))->assertNotFound();
        $this->post(route('careers.apply.store', $vacancy), $this->applicationFormPayload())
            ->assertNotFound();
    }
}
