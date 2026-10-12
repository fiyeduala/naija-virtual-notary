<?php

namespace App\Http\Controllers\Candidate;

use App\Exceptions\StageTransitionException;
use App\Http\Controllers\Candidate\Concerns\OwnedByCandidate;
use App\Http\Controllers\Controller;
use App\Models\OnboardingItem;
use App\Services\Recruitment\RecruitmentDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The checklist at the end: identity paperwork, referees, confirmations.
 *
 * This is the only point in the whole module where sensitive documents are
 * collected, and only for the items a vacancy actually switched on. Everything
 * uploaded here is marked sensitive on the way in, lands on the private disk,
 * and is readable afterwards only by a user holding sensitive.view — not by the
 * reviewer who scored the writing exercise, and never as an email attachment.
 *
 * An upload is a submission, not a verification. The item goes to "submitted"
 * and somebody has to say they checked it before it says anything more.
 */
class OnboardingController extends Controller
{
    use OwnedByCandidate;

    public function __construct(private RecruitmentDocumentService $documents) {}

    public function upload(Request $request, OnboardingItem $item): RedirectResponse
    {
        $item = $this->ownOnboardingItem($item);

        $max        = (int) config('recruitment.uploads.onboarding.max_kb', 8192);
        $extensions = implode(',', (array) config('recruitment.uploads.onboarding.extensions', ['pdf']));

        $request->validate([
            'file' => ['required', 'file', 'max:' . $max, 'mimes:' . $extensions],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'file.max'   => 'That file is larger than ' . round($max / 1024, 1) . ' MB.',
            'file.mimes' => 'Please upload one of: ' . str_replace(',', ', ', $extensions) . '.',
        ]);

        try {
            $this->documents->uploadOnboarding(
                $item,
                $this->candidate(),
                $request->file('file'),
                $request->string('note')->toString() ?: null,
            );
        } catch (StageTransitionException | \RuntimeException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return back()->with('status', 'Uploaded. We will confirm once somebody has checked it.');
    }

    /** A referee's details, or a written confirmation. No file involved. */
    public function answer(Request $request, OnboardingItem $item): RedirectResponse
    {
        $item = $this->ownOnboardingItem($item);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:2', 'max:4000'],
        ], [
            'note.required' => 'Please fill this in before submitting it.',
        ]);

        try {
            $this->documents->submitOnboardingAnswer($item, $this->candidate(), $validated['note']);
        } catch (StageTransitionException $exception) {
            return back()->withErrors(['note' => $exception->getMessage()]);
        }

        return back()->with('status', 'Saved, thank you.');
    }
}
