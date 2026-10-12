<?php

namespace App\Http\Controllers\Candidate;

use App\Exceptions\StageTransitionException;
use App\Http\Controllers\Candidate\Concerns\OwnedByCandidate;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Services\Recruitment\OfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The offer, and the candidate's answer to it.
 *
 * Said plainly on the page and repeated here because this is a notarization
 * business and the distinction is the whole trade: clicking "I accept" records
 * that the candidate agreed to the terms as issued. It is NOT an electronic
 * signature, nothing here is certified, and this module never describes it as
 * either. If a signed instrument is wanted, that is a document somebody signs.
 *
 * Accepting does not make anybody an employee, does not create a user account
 * and does not grant a single permission. It unlocks the checklist and leaves
 * the hire as a manual decision.
 */
class OfferController extends Controller
{
    use OwnedByCandidate;

    public function __construct(private OfferService $offers) {}

    public function show(Offer $offer): View
    {
        $offer = $this->ownOffer($offer);

        return view('candidate.offer', [
            'offer'       => $offer,
            'application' => $offer->application,
            'document'    => $offer->document,
            'checklist'   => $offer->application?->onboardingItems()->get() ?? collect(),
        ]);
    }

    public function respond(Request $request, Offer $offer): RedirectResponse
    {
        $offer = $this->ownOffer($offer);

        $validated = $request->validate([
            'decision' => ['required', 'in:accept,decline'],
            'note'     => ['nullable', 'string', 'max:2000'],
            'confirm'  => ['required', 'accepted'],
        ], [
            'confirm.accepted' => 'Please tick the box to confirm your answer.',
            'decision.in'      => 'Please choose whether you are accepting or declining.',
        ]);

        try {
            $this->offers->recordResponse(
                $offer,
                $validated['decision'] === 'accept',
                $validated['note'] ?? null,
            );
        } catch (StageTransitionException $exception) {
            return back()->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()
            ->route('candidate.offer', $offer)
            ->with('status', $validated['decision'] === 'accept'
                ? 'Thank you. Your acceptance is recorded, and anything still needed is listed below.'
                : 'Thank you for letting us know. Your answer is recorded.');
    }
}
