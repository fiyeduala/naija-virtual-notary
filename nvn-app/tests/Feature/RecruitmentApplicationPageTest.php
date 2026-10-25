<?php

namespace Tests\Feature;

use App\Enums\ApplicationStage;
use App\Models\Offer;
use App\Services\Recruitment\ApplicationStageService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecruitmentFixtures;
use Tests\TestCase;

/**
 * The application screen in the panel, rendered rather than reasoned about.
 *
 * Every other recruitment test drives the services, which is exactly how a
 * TypeError sat in this page's history panel while the suite stayed green: the
 * stage columns are cast to ApplicationStage on the model, the view called
 * tryFrom() on the result — which only accepts a string or an int — and
 * nothing in the suite ever rendered the view. It hid twice over, because an
 * application that has never moved has no history to draw, so the page only
 * broke once somebody started reviewing.
 *
 * These two tests assert almost nothing about how the page looks. They assert
 * that it comes back, with and without a history behind it, which is the part
 * that was not true.
 */
class RecruitmentApplicationPageTest extends TestCase
{
    use RefreshDatabase, RecruitmentFixtures;

    private ApplicationStageService $stages;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recruitment.enabled' => true]);
        Settings::flush();
        Mail::fake();

        $this->stages = app(ApplicationStageService::class);
    }

    protected function tearDown(): void
    {
        Settings::flush();

        parent::tearDown();
    }

    private function url(\App\Models\JobApplication $application): string
    {
        return route('filament.admin.resources.job-applications.view', [
            'record' => $application,
        ]);
    }

    public function test_an_application_with_history_renders(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'renders']));

        $this->stages->beginReview($application, $reviewer);

        $response = $this->actingAs($reviewer)->get($this->url($application));

        $response->assertOk();

        // Both sides of the arrow: the stage moved from, which is nullable
        // because the first move comes from nowhere, and the stage moved to,
        // which never is.
        $response->assertSee(ApplicationStage::Applied->label());
        $response->assertSee(ApplicationStage::InitialReview->label());
    }

    public function test_an_application_nobody_has_touched_renders(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'untouched']));

        $this->actingAs($reviewer)
            ->get($this->url($application))
            ->assertOk();
    }

    public function test_the_offer_buttons_read_the_offer_and_not_its_relation(): void
    {
        $reviewer    = $this->makeRecruitmentAdmin();
        $application = $this->makeApplication($this->makePublishedVacancy(['slug' => 'offered']));

        Offer::create([
            'job_application_id' => $application->id,
            'status'             => Offer::DRAFT,
            'body'               => 'The terms, as discussed.',
        ]);

        // offer() is a HasOne. Three visibility closures read the relation
        // object rather than the record behind it, and two actions handed that
        // same relation to OfferService, which type-hints Offer — so the panel
        // could neither draw these buttons nor press them.
        $this->assertInstanceOf(Offer::class, $application->fresh()->offer);

        $this->actingAs($reviewer)
            ->get($this->url($application))
            ->assertOk()
            ->assertSee('Approve the draft offer');
    }
}
