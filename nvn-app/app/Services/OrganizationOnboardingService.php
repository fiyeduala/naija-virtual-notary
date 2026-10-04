<?php

namespace App\Services;

use App\Models\Organization;
use App\Notifications\Organization\OrganizationApproved;
use App\Notifications\Organization\OrganizationDeclined;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Taking a partnership application live, or declining it.
 *
 * Lives in a service rather than inside the Filament action so that the one
 * rule that matters can be tested without a panel: a body is never approved
 * until it has an arrangement, a price, and — under commission — a rate and
 * somewhere to send the money. A body that is reachable but unpriced would
 * quote its applicants ₦0.00 under a government body's name, and the link may
 * already be printed on something by the time anyone notices.
 */
class OrganizationOnboardingService
{
    /**
     * Approve a body and hand it its link.
     *
     * Returns [ok, message]. A refusal names what is missing rather than
     * failing silently — see Organization::missingBeforeApproval().
     */
    public function approve(Organization $organization, ?int $actorId = null): array
    {
        if ($organization->isActive()) {
            return [false, 'This organization is already active.'];
        }

        if ($missing = $organization->missingBeforeApproval()) {
            return [false, 'Not yet — this still needs ' . $this->sentenceList($missing)];
        }

        $password = null;

        DB::transaction(function () use ($organization, $actorId, &$password) {
            $attributes = [
                'status'      => 'active',
                'reviewed_at' => now(),
                'reviewed_by' => $actorId,
            ];

            // Generated here rather than by hand so the link and the code are
            // guaranteed unique and in the house format. Re-approving a paused
            // body keeps the ones it already has — they may be in print.
            if (! $organization->slug) {
                $attributes['slug'] = Organization::generateSlug($organization->name);
            }

            if (! $organization->code) {
                $attributes['code'] = Organization::generateCode($organization->name);
            }

            // The portal login. The address defaults to the contact's, which
            // is who asked for the partnership and who the credentials are
            // being sent to.
            if (! $organization->email) {
                $attributes['email'] = $organization->contact_email;
            }

            if (! $organization->password) {
                $password = Str::password(14, symbols: false);
                $attributes['password'] = $password;
            }

            // price_only bodies hold no rate, whatever was typed before the
            // arrangement was settled.
            if ($organization->arrangement === Organization::PRICE_ONLY) {
                $attributes['commission_rate'] = 0;
            }

            $organization->update($attributes);

            $organization->documents()->where('status', 'pending')->update(['status' => 'approved']);
        });

        AuditLogger::record('organization.approved', 'organization', $organization->id, [
            'name'            => $organization->name,
            'slug'            => $organization->slug,
            'arrangement'     => $organization->arrangement,
            'commission_rate' => $organization->commission_rate,
            'default_price'   => $organization->default_price_ngn,
        ], $actorId);

        $organization->notify(new OrganizationApproved($organization->refresh(), $password));

        return [true, $organization->name . ' is live. Their link and sign-in details have been emailed to '
            . $organization->contact_email . '.'];
    }

    /** Decline an application, keeping the record and the reason. */
    public function reject(Organization $organization, ?string $note = null, ?int $actorId = null): array
    {
        if ($organization->isActive()) {
            return [false, 'This organization is active. Pause it instead of rejecting it.'];
        }

        $organization->update([
            'status'      => 'rejected',
            'review_note' => $note,
            'reviewed_at' => now(),
            'reviewed_by' => $actorId,
        ]);

        AuditLogger::record('organization.rejected', 'organization', $organization->id, [
            'name' => $organization->name,
            'note' => $note,
        ], $actorId);

        $organization->notify(new OrganizationDeclined($organization->refresh()));

        return [true, 'Declined, and ' . $organization->contact_email . ' has been told.'];
    }

    /**
     * Issue a fresh portal password and email it.
     *
     * The only way back in for a body that has lost its credentials, since
     * nobody at the platform can read the stored hash.
     */
    public function resetPortalPassword(Organization $organization, ?int $actorId = null): array
    {
        if (! $organization->email) {
            return [false, 'This organization has no portal login yet.'];
        }

        $password = Str::password(14, symbols: false);

        $organization->update(['password' => $password]);

        AuditLogger::record('organization.portal_password_reset', 'organization', $organization->id, [], $actorId);

        $organization->notify(new OrganizationApproved($organization->refresh(), $password));

        return [true, 'A new password has been emailed to ' . $organization->contact_email . '.'];
    }

    /** "a, b and c" — so a refusal reads as a sentence. */
    private function sentenceList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
