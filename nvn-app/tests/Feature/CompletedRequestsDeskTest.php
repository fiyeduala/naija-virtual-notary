<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Finished work has to be reachable from the desk.
 *
 * The dashboard counted completed jobs and stopped there, so the question that
 * comes afterwards — "send me that sealed copy again" — had nowhere to go. What
 * must not come with that screen is a widening of who sees whose work: the
 * desk is personal, and the platform-wide view lives in the admin panel.
 */
class CompletedRequestsDeskTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    /** Leave no memoised settings behind for whatever test runs next. */
    protected function tearDown(): void
    {
        \App\Support\Settings::flush();

        parent::tearDown();
    }

    /** A notary who can actually open the desk. */
    private function notaryAtKeyboard(): \App\Models\NotaryProfile
    {
        $notary = $this->makeNotary();
        $notary->user->forceFill(['email_verified_at' => now()])->save();

        return $notary;
    }

    /**
     * The request behind a completed job, so a test can add to it.
     *
     * Carries a service because a real one always does by the time it is paid
     * for — and the review screen reads the category without guarding for null.
     */
    private function completedRequest(\App\Models\NotaryProfile $notary, array $overrides = []): NotarizationRequest
    {
        $service = \App\Models\NotaryService::create([
            'notary_profile_id' => $notary->id,
            'service_type'      => 'Affidavit',
            'price_ngn'         => 4500000,
            'price_usd'         => 5000,
            'active'            => true,
        ]);

        $payment = $this->makeCompletedJob($notary, 4500000, array_merge(
            ['service_id' => $service->id],
            $overrides,
        ));

        return NotarizationRequest::findOrFail($payment->request_id);
    }

    private function sealDocument(NotarizationRequest $request, User $by): RequestDocument
    {
        return RequestDocument::create([
            'request_id'         => $request->id,
            'uploaded_by'        => $by->id,
            'file_url'           => 'request-documents/sealed-' . $request->id . '.pdf',
            'original_filename'  => 'deed-notarized.pdf',
            'file_type'          => 'final_notarized',
            'is_final_notarized' => true,
        ]);
    }

    public function test_the_desk_links_to_the_completed_screen_and_names_the_work(): void
    {
        $notary  = $this->notaryAtKeyboard();
        $request = $this->completedRequest($notary);

        $this->actingAs($notary->user)
            ->get(route('notary.dashboard'))
            ->assertOk()
            ->assertSee('/notary/requests/completed')
            ->assertSee('Recently completed')
            ->assertSee($request->reference);
    }

    /**
     * The sealed file is offered on the list itself.
     *
     * Reaching it through the request would work, but this is the screen someone
     * opens *because* they want the file, and a request can have several.
     */
    public function test_the_completed_screen_offers_the_sealed_document(): void
    {
        $notary  = $this->notaryAtKeyboard();
        $request = $this->completedRequest($notary);
        $sealed  = $this->sealDocument($request, $notary->user);

        $this->actingAs($notary->user)
            ->get(route('notary.requests.completed'))
            ->assertOk()
            ->assertSee($request->reference)
            ->assertSee(route('notary.requests.document', [$request, $sealed]), false);
    }

    /** A request still being worked on is not finished work. */
    public function test_an_unfinished_request_is_not_listed(): void
    {
        $notary = $this->notaryAtKeyboard();
        $open   = $this->completedRequest($notary, [
            'status'       => RequestStatus::Notarizing->value,
            'completed_at' => null,
        ]);

        $this->actingAs($notary->user)
            ->get(route('notary.requests.completed'))
            ->assertOk()
            ->assertDontSee($open->reference);
    }

    public function test_another_notarys_completed_work_is_not_listed(): void
    {
        $mine   = $this->notaryAtKeyboard();
        $theirs = $this->completedRequest($this->makeNotary());

        $this->actingAs($mine->user)
            ->get(route('notary.requests.completed'))
            ->assertOk()
            ->assertDontSee($theirs->reference);
    }

    /** Offsite work is the notary's own outside job and has its own screen. */
    public function test_an_offsite_job_is_not_listed(): void
    {
        $notary  = $this->notaryAtKeyboard();
        $offsite = $this->completedRequest($notary, ['is_offsite' => true]);

        $this->actingAs($notary->user)
            ->get(route('notary.requests.completed'))
            ->assertOk()
            ->assertDontSee($offsite->reference);
    }

    /**
     * An admin's completed list is what they did, not what everyone did.
     *
     * A job they took over on a partner's behalf is theirs — handled_by carries
     * it — while a job the partner finished alone is not, even though the admin
     * could have taken it. That wider view is the admin panel's job.
     */
    public function test_the_admin_sees_what_they_took_over_and_not_a_partners_own_work(): void
    {
        $admin = $this->makeClient([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);

        $partner = $this->makeNotary();

        $tookOver   = $this->completedRequest($partner, ['handled_by' => $admin->id]);
        $partnersOwn = $this->completedRequest($partner);

        $this->actingAs($admin)
            ->get(route('notary.requests.completed'))
            ->assertOk()
            ->assertSee($tookOver->reference)
            ->assertDontSee($partnersOwn->reference);
    }

    /**
     * The support widget stays off the desk.
     *
     * Asserted together with a page that *does* carry it, so this cannot pass
     * because the widget is switched off or broken everywhere.
     */
    public function test_the_live_chat_widget_is_off_the_desk_but_on_the_public_site(): void
    {
        config([
            'nvn.tawk.property_id' => 'test-property',
            'nvn.tawk.widget_id'   => 'test-widget',
        ]);

        // Settings memoises per request, and the static survives between tests
        // in one process — so an earlier test that rendered a page with no
        // property ID would otherwise have cached the empty answer.
        \App\Support\Settings::flush();

        $notary  = $this->notaryAtKeyboard();
        $request = $this->completedRequest($notary);

        $this->get(route('home'))->assertOk()->assertSee('embed.tawk.to');

        $this->actingAs($notary->user)
            ->get(route('notary.dashboard'))
            ->assertOk()
            ->assertDontSee('embed.tawk.to');

        $this->actingAs($notary->user)
            ->get(route('notary.requests.completed'))
            ->assertOk()
            ->assertDontSee('embed.tawk.to');

        $this->actingAs($notary->user)
            ->get(route('notary.requests.show', $request))
            ->assertOk()
            ->assertDontSee('embed.tawk.to');

        $this->actingAs($notary->user)
            ->get(route('notary.requests.incoming'))
            ->assertOk()
            ->assertDontSee('embed.tawk.to');
    }
}
