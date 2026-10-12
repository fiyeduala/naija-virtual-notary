<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Candidate\Concerns\OwnedByCandidate;
use App\Http\Controllers\Controller;
use App\Models\Interview;
use App\Services\Recruitment\InterviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The two things a candidate may do about an interview.
 *
 * Acknowledging it, so the office knows the invitation landed and is not left
 * guessing whether to chase; and asking for a different time, which is a
 * REQUEST and not a reschedule. The candidate cannot move the appointment —
 * somebody has to be free at the other end — so this records what they asked
 * for and leaves the decision with the coordinator.
 */
class InterviewController extends Controller
{
    use OwnedByCandidate;

    public function __construct(private InterviewService $interviews) {}

    public function acknowledge(Interview $interview): RedirectResponse
    {
        $interview = $this->ownInterview($interview);

        if (! $interview->isLive()) {
            return back()->with('status', 'That interview is no longer scheduled.');
        }

        $this->interviews->acknowledge($interview);

        return back()->with('status', 'Thank you — we have noted that you can make it.');
    }

    public function reschedule(Request $request, Interview $interview): RedirectResponse
    {
        $interview = $this->ownInterview($interview);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'note.required' => 'Please tell us roughly when would suit you better.',
        ]);

        if (! $interview->isLive()) {
            return back()->with('status', 'That interview is no longer scheduled.');
        }

        $this->interviews->requestReschedule($interview, $validated['note']);

        return back()->with('status',
            'Your request has been sent. We will confirm a new time by email — nothing is changed until we do.');
    }
}
