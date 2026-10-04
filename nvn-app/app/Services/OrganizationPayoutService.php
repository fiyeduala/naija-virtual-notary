<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\SettlementMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What each partner body is owed, and the record of paying it.
 *
 * A deliberate mirror of PayoutService, down to the method names, so the two
 * read the same way. The ledger is payments.organization_payout_id: a fee
 * counts towards what a body is owed for exactly as long as it is unattached,
 * and attaching it is what makes it paid. Nothing is inferred from dates, so
 * two runs cannot both pay the same fee however their periods overlap.
 *
 * The rate comes from the request, not from the organization. A rate
 * renegotiated tomorrow must not change what a body earned on a job that
 * completed last month.
 *
 * One rule here is load-bearing and worth stating plainly: a body on the
 * price_only arrangement is SKIPPED, not computed as zero. PayoutService
 * bails on having no payments rather than on a zero amount, and copying that
 * shape without the skip would create a ₦0 payout that attaches every one of
 * the body's fees to itself — leaving the money claimed by a row that will
 * never pay it, and unreachable by any later run. The arrangement is stored
 * precisely so this cannot happen by accident.
 */
class OrganizationPayoutService
{
    /**
     * Cleared fees for completed referred jobs that no payout has settled yet.
     *
     * @return Collection<int, Payment>
     */
    public function unpaidPayments(Organization $organization): Collection
    {
        return Payment::query()
            ->organizationPayable()
            ->whereHas('request', fn ($q) => $q
                ->where('organization_id', $organization->id)
                ->where('status', RequestStatus::Completed->value)
                // An offsite job is a notary paying the platform to seal their
                // own outside work. It has no referring body, and if one were
                // somehow attached, paying a commission on it would be the
                // platform handing away a fee it had just collected.
                ->where('is_offsite', false))
            ->with('request')
            ->get();
    }

    /**
     * What the body would receive if a payout were generated right now.
     *
     * Zero for a price_only body, which is the honest answer: it is charged a
     * rate and earns nothing, so there is nothing to show it.
     */
    public function owed(Organization $organization): int
    {
        if (! $organization->earnsCommission()) {
            return 0;
        }

        return (int) $this->unpaidPayments($organization)->sum(
            fn (Payment $payment) => $payment->request?->organizationShareOf($payment->amount) ?? 0,
        );
    }

    /** Gross fees referred by this body that no payout has claimed yet. */
    public function unsettledGross(Organization $organization): int
    {
        return (int) $this->unpaidPayments($organization)->sum('amount');
    }

    /**
     * Generate a payout per body with something owed.
     *
     * @return SupportCollection<int, OrganizationPayout>
     */
    public function generateAll(?int $initiatedBy = null): SupportCollection
    {
        return Organization::query()
            ->active()
            ->where('arrangement', Organization::COMMISSION)
            ->get()
            ->map(fn (Organization $organization) => $this->generateFor($organization, $initiatedBy))
            ->filter()
            ->values();
    }

    /** One body's payout, or null when there is nothing to pay. */
    public function generateFor(Organization $organization, ?int $initiatedBy = null): ?OrganizationPayout
    {
        // The skip, and the whole reason `arrangement` is a stored field.
        // Without it a body with a zero rate would get an empty payout that
        // claimed its fees — see the class comment.
        if (! $organization->earnsCommission()) {
            return null;
        }

        return DB::transaction(function () use ($organization, $initiatedBy) {
            $candidates = $this->unpaidPayments($organization)->pluck('id');

            if ($candidates->isEmpty()) {
                return null;
            }

            // Lock the rows before claiming them, so two concurrent runs
            // cannot both attach the same fee.
            $locked = Payment::whereIn('id', $candidates)
                ->whereNull('organization_payout_id')
                ->lockForUpdate()
                ->with('request')
                ->get();

            if ($locked->isEmpty()) {
                return null;
            }

            $gross = (int) $locked->sum('amount');
            $share = (int) $locked->sum(
                fn (Payment $p) => $p->request?->organizationShareOf($p->amount) ?? 0,
            );

            // A body that earns a commission but whose referred jobs were all
            // frozen at a zero rate. Nothing is owed, and claiming the fees
            // would strand them exactly as the price_only case would.
            if ($share <= 0) {
                return null;
            }

            $completed = $locked->pluck('completed_at')->filter();

            $payout = OrganizationPayout::create([
                'reference'       => 'OPO-' . Str::upper(Str::random(10)),
                'organization_id' => $organization->id,
                'amount'          => $share,
                'gross_amount'    => $gross,
                'currency'        => 'NGN',
                'status'          => 'pending',
                'period_start'    => $completed->min()?->toDateString(),
                'period_end'      => $completed->max()?->toDateString(),
                'initiated_by'    => $initiatedBy,
            ]);

            Payment::whereIn('id', $locked->pluck('id'))
                ->update(['organization_payout_id' => $payout->id]);

            AuditLogger::record('organization_payout.generated', 'organization_payout', $payout->id, [
                'organization_id' => $organization->id,
                'amount'          => $share,
                'gross'           => $gross,
                'payments'        => $locked->count(),
            ], $initiatedBy);

            return $payout;
        });
    }

    /**
     * Settle a payout the platform paid by hand.
     *
     * Every organization settlement is this one. There is no Paystack transfer
     * path for a body in v1, so the method, their reference and the admin who
     * recorded it are the only evidence the money moved — which is why all
     * three are stored rather than just a status.
     */
    public function settleOffline(OrganizationPayout $payout, array $details, ?int $actorId = null): array
    {
        if (! $payout->isSettleable()) {
            return [false, $payout->isPaid()
                ? 'This payout has already been paid.'
                : 'There is nothing to pay.'];
        }

        $method = (string) ($details['method'] ?? 'bank_transfer');

        $payout->update([
            'status'               => 'paid',
            'processed_at'         => $details['paid_at'] ?? now(),
            'failure_reason'       => null,
            'initiated_by'         => $actorId,
            'settlement_method'    => SettlementMethod::exists($method) ? $method : 'other',
            'settlement_reference' => $details['reference'] ?? null,
            'settlement_note'      => $details['note'] ?? null,
        ]);

        AuditLogger::record('organization_payout.settled_offline', 'organization_payout', $payout->id, [
            'organization_id' => $payout->organization_id,
            'method'          => $payout->settlement_method,
            'amount'          => $payout->amount,
            'their_ref'       => $payout->settlement_reference,
        ], $actorId);

        return [true, 'Recorded as paid by ' . Str::lower(SettlementMethod::label($payout->settlement_method)) . '.'];
    }

    /**
     * Cancel a payout that has not been paid, releasing its fees.
     *
     * The mirror of PayoutService::fail(): without the release, cancelling
     * would silently erase what the body is owed, because its fees would stay
     * attached to a payout that never paid.
     */
    public function cancel(OrganizationPayout $payout, string $reason, ?int $actorId = null): array
    {
        if ($payout->isPaid()) {
            return [false, 'This payout has already been paid and cannot be cancelled.'];
        }

        DB::transaction(function () use ($payout, $reason) {
            $payout->payments()->update(['organization_payout_id' => null]);

            $payout->update([
                'status'         => 'failed',
                'failure_reason' => Str::limit($reason, 500),
                'processed_at'   => now(),
            ]);
        });

        AuditLogger::record('organization_payout.cancelled', 'organization_payout', $payout->id, [
            'organization_id'   => $payout->organization_id,
            'reason'            => $reason,
            'released_payments' => true,
        ], $actorId);

        return [true, 'Cancelled. The fees are back in what this organization is owed.'];
    }

    /** Commission already sent, in minor units. */
    public function paidOut(Organization $organization): int
    {
        return (int) OrganizationPayout::where('organization_id', $organization->id)
            ->where('status', 'paid')
            ->sum('amount');
    }
}
