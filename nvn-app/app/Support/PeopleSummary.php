<?php

namespace App\Support;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\NotarizationRequest;
use App\Models\NotaryProfile;
use App\Models\User;
use App\Services\PayoutService;
use Illuminate\Support\Collection;

/**
 * Who is on the platform, counted once.
 *
 * The panel already has a resource per table — Users lists users, Notaries
 * lists notary profiles — and both are built for finding one record. Neither
 * answers "how many notaries can actually seal a document", which is the
 * question an owner asks before promising a turnaround, and which cannot be
 * answered by sorting a table because canSeal() is not a column.
 *
 * Two rules this class keeps to:
 *
 *  - No figure is invented or approximated. Every number below is a count or a
 *    sum of rows. Where a question has no column behind it (sealing assets,
 *    membership) the model's own method answers it, so this page and the gate
 *    that stops a notary working can never disagree.
 *
 *  - Money is never recomputed here. PayoutService::owed() is the one place
 *    that knows which fees are payable and what the notary's share of them is;
 *    a second implementation on a summary screen would eventually drift from
 *    the one that actually pays people.
 *
 * Soft-deleted rows are excluded from every count except the archived one,
 * which names them explicitly.
 */
class PeopleSummary
{
    /**
     * Account totals, by role and by state.
     *
     * @return array<string, int>
     */
    public static function users(): array
    {
        $week  = now()->subDays(7);
        $month = now()->subDays(30);

        return [
            'total'             => User::count(),
            'clients'           => User::where('role', UserRole::Client->value)->count(),
            'notaries'          => User::where('role', UserRole::Notary->value)->count(),
            'admins'            => User::where('role', UserRole::Admin->value)->count(),

            'active'            => User::where('status', 'active')->count(),
            'suspended'         => User::where('status', 'suspended')->count(),
            'pending'           => User::where('status', 'pending')->count(),

            'verified'          => User::whereNotNull('email_verified_at')->count(),
            'unverified'        => User::whereNull('email_verified_at')->count(),

            'signed_in'         => User::whereNotNull('last_login_at')->count(),
            'never_signed_in'   => User::whereNull('last_login_at')->count(),

            'new_week'          => User::where('created_at', '>=', $week)->count(),
            'new_month'         => User::where('created_at', '>=', $month)->count(),
            'active_month'      => User::where('last_login_at', '>=', $month)->count(),

            // The WordPress import. An imported account that has never signed
            // in is a row, not a user — worth seeing apart from a real signup.
            'imported'          => User::whereNotNull('legacy_source')->count(),
            'imported_live'     => User::whereNotNull('legacy_source')
                                       ->whereNotNull('last_login_at')->count(),

            'from_organization' => User::whereNotNull('referred_by_organization_id')->count(),
            'email_opted_out'   => User::where('bulk_email_opt_out', true)->count(),

            'archived'          => User::onlyTrashed()->count(),
        ];
    }

    /**
     * Notary totals.
     *
     * can_seal and the membership figures come from the models rather than from
     * SQL, so the profiles are loaded with their assets first. The set is
     * bounded by how many notaries the platform has signed, which is the right
     * order of magnitude to hold in memory; if that ever stops being true this
     * is the method to change, not the page.
     *
     * @return array<string, int>
     */
    public static function notaries(): array
    {
        $profiles = NotaryProfile::with('assets')->get();

        return [
            'total'             => $profiles->count(),

            'approved'          => $profiles->where('verification_status', 'approved')->count(),
            'pending'           => $profiles->where('verification_status', 'pending')->count(),
            'rejected'          => $profiles->where('verification_status', 'rejected')->count(),
            'suspended'         => $profiles->where('verification_status', 'suspended')->count(),

            // Paid and waiting on a human. The unpaid ones are applications,
            // not a queue — nothing is owed to anybody until the fee clears.
            'awaiting_review'   => $profiles->where('verification_status', 'pending')
                                            ->filter(fn (NotaryProfile $p) => $p->onboarding_fee_paid_at !== null)
                                            ->count(),
            'fee_unpaid'        => $profiles->filter(fn (NotaryProfile $p) => $p->onboarding_fee_paid_at === null)->count(),

            'listed'            => NotaryProfile::listed()->count(),
            'listing_queue'     => NotaryProfile::awaitingListingReview()->count(),

            'can_seal'          => $profiles->filter(fn (NotaryProfile $p) => $p->canSeal())->count(),
            'cannot_seal'       => $profiles->reject(fn (NotaryProfile $p) => $p->canSeal())->count(),

            'membership_live'   => $profiles->filter(fn (NotaryProfile $p) => $p->membershipActive())->count(),
            'membership_soon'   => $profiles->filter(fn (NotaryProfile $p) => $p->membershipEndingSoon())->count(),
            'membership_lapsed' => $profiles->filter(fn (NotaryProfile $p) => $p->membershipLapsed())->count(),

            'system_native'     => $profiles->where('is_system_native', true)->count(),
            'individual'        => $profiles->where('entity_type', 'individual')->count(),
            'firm'              => $profiles->reject(fn (NotaryProfile $p) => $p->entity_type === 'individual')->count(),

            'archived'          => NotaryProfile::onlyTrashed()->count(),
        ];
    }

    /**
     * One row per notary, for the table on the page.
     *
     * Completed jobs are counted in a single grouped query rather than per row.
     * owed() delegates to PayoutService so the figure here is the same figure
     * the payout run would act on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function notaryRows(): Collection
    {
        $payouts = app(PayoutService::class);

        $completed = NotarizationRequest::query()
            ->where('status', RequestStatus::Completed->value)
            ->whereNotNull('notary_id')
            ->selectRaw('notary_id, COUNT(*) as jobs')
            ->groupBy('notary_id')
            ->pluck('jobs', 'notary_id');

        return NotaryProfile::with(['user', 'assets'])
            ->get()
            ->map(fn (NotaryProfile $p) => [
                'id'           => $p->id,
                'name'         => $p->user?->full_name ?? '—',
                'email'        => $p->user?->email ?? '—',
                'status'       => (string) $p->verification_status,
                'entity'       => (string) $p->entity_type,
                'system'       => (bool) $p->is_system_native,
                'listed'       => (bool) $p->public_listing_enabled,
                'can_seal'     => $p->canSeal(),
                'missing'      => $p->missingSealingAssets(),
                'member_until' => $p->membership_expires_at,
                'member_days'  => $p->membershipDaysLeft(),
                'jobs'         => (int) ($completed[$p->id] ?? 0),
                'owed'         => $payouts->owed($p),
                'joined'       => $p->onboarding_fee_paid_at ?? $p->created_at,
                'last_login'   => $p->user?->last_login_at,
            ])
            // Busiest first, then by name so the order is stable when two
            // notaries have done the same number of jobs. A job count alone is
            // not an order, any more than created_at is.
            ->sortBy([['jobs', 'desc'], ['name', 'asc']])
            ->values();
    }

    /**
     * The newest accounts, so an owner can see who just arrived.
     *
     * @return Collection<int, User>
     */
    public static function recentUsers(int $limit = 10): Collection
    {
        return User::query()
            ->latest('created_at')
            // Same-second signups (an import, a seeder) would otherwise come
            // back in whatever order the engine felt like.
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'full_name', 'email', 'role', 'status', 'created_at', 'last_login_at', 'legacy_source']);
    }
}
