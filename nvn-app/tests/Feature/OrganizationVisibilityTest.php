<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Knowing a job came from a partner body before opening it.
 *
 * It matters at the desk because the fee on the request is a negotiated figure
 * rather than the public price, and because the office may have promised that
 * body a turnaround. So the marker has to be on every screen that lists a
 * request, and — just as importantly — on none of the ordinary ones: a marker
 * that appeared on everything would say nothing.
 *
 * The counts are tested against the requests actually referred rather than
 * against a stored total, because the roll is what gets looked at when
 * somebody asks what a body is worth.
 */
class OrganizationVisibilityTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    public function test_the_desk_queue_marks_a_referral_and_leaves_ordinary_work_bare(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization(['name' => 'Federal Lands Bureau']);

        $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id'   => $service->id,
            'status'       => RequestStatus::Paid->value,
            'completed_at' => null,
            'paid_at'      => now(),
        ]);

        $this->makeCompletedJob($notary, 2500000, [
            'service_id'   => $service->id,
            'status'       => RequestStatus::Paid->value,
            'completed_at' => null,
            'paid_at'      => now(),
        ]);

        $response = $this->actingAs($notary->user)
            ->get(route('notary.requests.incoming'))
            ->assertOk()
            ->assertSee('Federal Lands Bureau');

        // One marker, not two. Both jobs are on this screen and only one of
        // them came from a body. Counted on the marker’s own tooltip rather
        // than on the name, because the component prints the name twice —
        // once as the pill and once as its title.
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'Referred by Federal Lands Bureau'),
        );
    }

    public function test_the_completed_screen_and_the_dashboard_both_mark_a_referral(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization(['name' => 'Netherlands Embassy']);

        $this->makeOrganizationJob($organization, $notary, 25000000, ['service_id' => $service->id]);

        $this->actingAs($notary->user)
            ->get(route('notary.requests.completed'))
            ->assertOk()
            ->assertSee('Netherlands Embassy');

        $this->actingAs($notary->user)
            ->get(route('notary.dashboard'))
            ->assertOk()
            ->assertSee('Netherlands Embassy');
    }

    public function test_the_screen_the_job_is_worked_from_names_the_body(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization(['name' => 'Ministry of Justice']);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id' => $service->id,
            'status'     => RequestStatus::Paid->value,
        ]);
        $request = $payment->request;

        $this->actingAs($notary->user)
            ->get(route('notary.requests.show', $request))
            ->assertOk()
            ->assertSee('Referred by')
            ->assertSee('Ministry of Justice')
            // The body's cut is between the platform and the body. A notary
            // at the desk has no reason to see it.
            ->assertDontSee($organization->commission_rate . '%');
    }

    public function test_ordinary_work_carries_no_marker_anywhere(): void
    {
        $notary = $this->makeNotary();
        $this->giveSealingAssets($notary);
        $service = $this->makeService($notary);
        $this->makeOrganization(['name' => 'Federal Lands Bureau']);

        $payment = $this->makeCompletedJob($notary, 2500000, [
            'service_id' => $service->id,
            'status'     => RequestStatus::Paid->value,
        ]);

        foreach ([
            route('notary.dashboard'),
            route('notary.requests.incoming'),
            route('notary.requests.show', $payment->request),
        ] as $url) {
            $this->actingAs($notary->user)->get($url)
                ->assertOk()
                ->assertDontSee('Federal Lands Bureau')
                ->assertDontSee('Referred by');
        }
    }

    public function test_the_client_sees_why_their_price_was_what_it_was(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization(['name' => 'Federal Lands Bureau']);

        $payment = $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id' => $service->id,
        ]);

        // Quiet, but there. Their price came from that arrangement and they
        // should be able to see where it came from.
        $this->actingAs($payment->request->client)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('via Federal Lands Bureau');
    }

    public function test_the_admin_requests_table_shows_and_can_isolate_organization_work(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization(['name' => 'Federal Lands Bureau']);

        $referred = $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id' => $service->id,
        ])->request;

        $ordinary = $this->makeCompletedJob($notary, 2500000, ['service_id' => $service->id])->request;

        $admin = $this->makeClient(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('filament.admin.resources.notarization-requests.index'))
            ->assertOk()
            ->assertSee('Federal Lands Bureau');

        // The filter's own query, which is what decides which rows survive it.
        $isolated = NotarizationRequest::fromOrganizations()->pluck('id');
        $this->assertTrue($isolated->contains($referred->id));
        $this->assertFalse($isolated->contains($ordinary->id));
    }

    public function test_the_roll_counts_what_was_actually_referred(): void
    {
        $notary = $this->makeSystemNotary();
        $service = $this->makeService($notary);
        $organization = $this->makeOrganization();
        $other = $this->makeOrganization();

        $this->makeOrganizationJob($organization, $notary, 25000000, ['service_id' => $service->id]);
        $this->makeOrganizationJob($organization, $notary, 25000000, [
            'service_id'   => $service->id,
            'status'       => RequestStatus::Paid->value,
            'completed_at' => null,
        ]);
        $this->makeOrganizationJob($other, $notary, 25000000, ['service_id' => $service->id]);
        $this->makeCompletedJob($notary, 2500000, ['service_id' => $service->id]);

        // The same two numbers the roll puts side by side: everything sent,
        // and the part of it that is finished. Ordinary work and another
        // body's work are in neither.
        $roll = Organization::withCount([
            'requests as referred_count',
            'requests as completed_count' => fn ($query) => $query->where('status', 'completed'),
        ])->findOrFail($organization->id);

        $this->assertSame(2, (int) $roll->referred_count);
        $this->assertSame(1, (int) $roll->completed_count);

        $this->assertSame(1, (int) Organization::withCount('requests')
            ->findOrFail($other->id)->requests_count);
    }
}
