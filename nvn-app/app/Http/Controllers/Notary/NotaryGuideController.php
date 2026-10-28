<?php

namespace App\Http\Controllers\Notary;

use App\Http\Controllers\Controller;
use App\Models\NotaryProfile;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The walkthrough a new partner reads once and then stops needing.
 *
 * Everything on the page is a screenshot of this software doing the thing it
 * describes, in the order a notary meets it: pay, upload the three marks, add a
 * payout account, price a category, ask to be listed, then take jobs.
 *
 * Two deliberate choices about what the controller does and does not do:
 *
 *   - Every figure on the page is read from the same place the real screens
 *     read it — the fee from PlatformSetting, the commission from the notary's
 *     own profile, the offsite charge from Settings. A guide that quotes a
 *     price the checkout does not charge is worse than no guide, and a number
 *     typed into a blade is a number nobody will remember to change.
 *   - The checklist is computed from the signed-in notary's real state, through
 *     NotaryProfile::listingBlockers() and canSeal() rather than a second
 *     opinion about what "ready" means. So the page is a tutorial to a notary
 *     who has done none of it and a progress report to one who is halfway.
 */
class NotaryGuideController extends Controller
{
    public function index(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $profile = $user->notaryProfile()
            ->with(['assets', 'services', 'bankDetails'])
            ->first();

        return view('notary.guide', [
            'profile'     => $profile,
            'isAdminDesk' => $user->isAdmin() && ! $user->isNotary(),
            'steps'       => $this->steps($profile),
            'feeDisplay'  => $this->feeDisplay(),
            'offsiteFee'  => Settings::offsiteFeeDisplay(),
            'rate'        => (int) ($profile?->commission_rate ?? Settings::defaultCommissionRate()),
            'assetGuide'  => Settings::assetGuideUrl(),
            'reviewDays'  => NotaryProfile::RENEWAL_NOTICE_DAYS,
        ]);
    }

    /**
     * The five things that have to be true before a client can book them, each
     * with where it is done and whether it is done.
     *
     * Ordered the way the software enforces it rather than the way it reads
     * best: the fee gates the profile screen, and the profile screen gates the
     * request to be listed, so a notary who works down this list never meets a
     * step they cannot complete yet.
     *
     * The admin at the platform desk is given no checklist. They hold the
     * system-native profile, which pays no fee and cannot lapse, so every
     * answer would be a yes that means nothing. See NotaryProfile.
     *
     * @return list<array{key: string, label: string, done: bool, route: ?string}>
     */
    private function steps(?NotaryProfile $profile): array
    {
        if (! $profile || $profile->is_system_native) {
            return [];
        }

        $bank = $profile->bankDetails;

        return [
            [
                'key'   => 'fee',
                'label' => 'Membership fee paid',
                'done'  => $profile->membershipActive(),
                'route' => route('notary.onboarding.fee'),
            ],
            [
                'key'   => 'assets',
                'label' => 'Signature, stamp and seal uploaded',
                'done'  => $profile->canSeal(),
                'route' => route('notary.profile.edit'),
            ],
            [
                'key'   => 'bank',
                'label' => 'Payout account added',
                'done'  => $bank?->bank_code !== null,
                'route' => route('notary.profile.edit'),
            ],
            [
                'key'   => 'service',
                'label' => 'At least one service priced',
                'done'  => $profile->services->count() > 0,
                'route' => route('notary.profile.edit'),
            ],
            [
                // Two different facts under one line, and the label says which:
                // asking is the notary's step, being listed is ours. Showing
                // only "listed" would read as a step they had failed to take
                // while it was sitting on our desk.
                'key'   => 'listed',
                'label' => $profile->public_listing_enabled
                    ? 'Listed in the marketplace'
                    : ($profile->isAwaitingListingReview()
                        ? 'Sent for listing review — with us now'
                        : 'Profile sent for listing review'),
                'done'  => $profile->public_listing_enabled || $profile->isAwaitingListingReview(),
                'route' => route('notary.profile.edit'),
            ],
        ];
    }

    /**
     * The yearly fee, read the way the checkout reads it.
     *
     * Same expression as OnboardingFeeController::show(), minor units and all,
     * so the guide cannot quote last year's price.
     */
    private function feeDisplay(): string
    {
        $minor = (int) PlatformSetting::get('onboarding_fee_ngn', config('nvn.onboarding_fee_ngn'));

        return '₦' . number_format($minor / 100, 2);
    }
}
