<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\OwnedByCandidate;
use App\Http\Controllers\Controller;
use App\Models\ApplicationDocument;
use App\Models\JobApplication;
use App\Services\Recruitment\ApplicationStageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What a candidate sees about themselves.
 *
 * The rule that shapes every query here: a candidate sees facts about their own
 * progress and nothing about anybody's opinion of them. Reviewer scores,
 * internal notes, reviewer identities, interview feedback and the reason behind
 * a rejection are all on records this controller never loads — not hidden in a
 * view that could be changed later, simply absent from the data handed to it.
 *
 * The stage they are shown is `candidateStageLabel()`, which is deliberately
 * coarser than the internal one: "Being reviewed" rather than "Initial review,
 * two of three reviewers scored".
 */
class DashboardController extends Controller
{
    use OwnedByCandidate;

    public function __construct(private ApplicationStageService $stages) {}

    public function index(): View
    {
        $applications = JobApplication::query()
            ->with(['vacancy'])
            ->where('candidate_id', $this->candidate()->id)
            ->orderByDesc('applied_at')
            ->get();

        return view('candidate.home', [
            'candidate'    => $this->candidate(),
            'applications' => $applications,
        ]);
    }

    public function show(JobApplication $application): View
    {
        $application = $this->ownApplication($application);

        // Loaded by name, one relation at a time. `notes`, `reviews`, `scores`
        // and the interviews' feedback columns are simply not here.
        $application->load([
            'answers',
            'assignments' => fn ($query) => $query->whereNull('cancelled_at')->orderByDesc('id'),
            'interviews',
            'onboardingItems',

            // Read for the dates only. The view renders the stage reached and
            // when; the actor, the reason and the metadata on these rows are
            // never put on the page.
            'stageEvents',
        ]);

        $offer = $application->offers()
            ->whereIn('status', [\App\Models\Offer::SENT, \App\Models\Offer::ACCEPTED, \App\Models\Offer::DECLINED])
            ->orderByDesc('id')
            ->first();

        return view('candidate.application', [
            'candidate'   => $this->candidate(),
            'application' => $application,
            'assignment'  => $application->currentAssignment(),
            'interview'   => $application->upcomingInterview(),
            'offer'       => $offer,
            'documents'   => $application->documents()
                ->whereIn('kind', [ApplicationDocument::KIND_CV, ApplicationDocument::KIND_OFFER])
                ->orderByDesc('id')
                ->get(),
            'checklist'   => $application->onboardingItems,
        ]);
    }

    /**
     * Withdrawing.
     *
     * A candidate may stop at any point, and the moment they do, every pending
     * message for them is cancelled — an assessment reminder landing two days
     * after somebody withdrew is the kind of thing that makes an employer look
     * careless. ApplicationStageService::withdraw() does the cancelling and
     * writes the stage event; the only thing this adds is the confirmation,
     * because withdrawing cannot be undone from this side.
     */
    public function withdraw(Request $request, JobApplication $application): RedirectResponse
    {
        $application = $this->ownApplication($application);

        $validated = $request->validate([
            'confirm' => ['required', 'accepted'],
            'reason'  => ['nullable', 'string', 'max:1000'],
        ], [
            'confirm.accepted' => 'Please tick the box to confirm you want to withdraw.',
        ]);

        if (! $application->isActive()) {
            return back()->with('status', 'This application is already closed.');
        }

        $this->stages->withdraw($application, $validated['reason'] ?? null);

        return redirect()
            ->route('candidate.application', $application)
            ->with('status', 'Your application has been withdrawn. Thank you for letting us know.');
    }
}
