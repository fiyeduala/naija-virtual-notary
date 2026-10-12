<?php

namespace App\Http\Controllers\Candidate;

use App\Exceptions\StageTransitionException;
use App\Http\Controllers\Candidate\Concerns\OwnedByCandidate;
use App\Http\Controllers\Controller;
use App\Models\AssessmentAssignment;
use App\Services\Recruitment\AssessmentService;
use App\Support\ExternalLink;
use App\Support\WorkingCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The assessment, from the candidate's side.
 *
 * Three things worth stating:
 *
 *   - The tasks rendered here come off the ASSIGNMENT, not off the template.
 *     The template is snapshotted when the invitation is sent, so a reviewer
 *     rewriting the exercise this afternoon cannot change the exercise somebody
 *     is halfway through this morning.
 *   - A draft save and a submission are different routes. The draft is
 *     forgiving and keeps whatever is typed; the submission validates, and once
 *     it lands the answers are final unless a reviewer explicitly reopens the
 *     assignment — at which point the earlier answers are kept as history
 *     rather than overwritten.
 *   - The deadline is enforced here, on the server, and the page says so
 *     rather than accepting a submission it will not keep. What it does NOT
 *     do is decide anything about the candidate: the assignment is flagged
 *     overdue, the stage does not move, and nobody is rejected. Admitting
 *     late work is a reviewer's deliberate act — extend() or reopen() — and
 *     that is the only thing that puts this form back in her hands.
 */
class AssessmentController extends Controller
{
    use OwnedByCandidate;

    public function __construct(private AssessmentService $assessments) {}

    public function show(AssessmentAssignment $assignment): View
    {
        $assignment = $this->ownAssignment($assignment);

        return view('candidate.assessment', [
            'candidate'   => $this->candidate(),
            'assignment'  => $assignment,
            'application' => $assignment->application,
            'tasks'       => $assignment->tasks(),
            'answers'     => $assignment->isSubmitted()
                ? $assignment->finalAnswers()
                : $assignment->draftAnswers(),
            'editable'    => $assignment->acceptsChanges(),
            'due'         => $assignment->dueForHumans($this->candidate()->timezone),
            'zone'        => WorkingCalendar::zoneLabel($this->candidate()->timezone),
        ]);
    }

    /** Save and come back later. Nothing is validated and nothing is final. */
    public function draft(Request $request, AssessmentAssignment $assignment): RedirectResponse
    {
        $assignment = $this->ownAssignment($assignment);

        if ($closed = $assignment->closedReason()) {
            return back()->with('status', $closed);
        }

        $this->assessments->saveDraft($assignment, $this->answers($request));

        return back()->with('status', 'Saved. You can come back and finish this later.');
    }

    public function submit(Request $request, AssessmentAssignment $assignment): RedirectResponse
    {
        $assignment = $this->ownAssignment($assignment);

        $validated = $request->validate([
            'intro_video_url'  => ['nullable', 'string', 'max:500'],
            'intro_alternative' => ['nullable', 'string', 'max:8000'],
            'answers'          => ['array'],
            'answers.*'        => ['nullable', 'string', 'max:20000'],
        ]);

        $vacancy = $assignment->application?->vacancy;
        $link    = trim((string) ($validated['intro_video_url'] ?? ''));
        $written = trim((string) ($validated['intro_alternative'] ?? ''));

        // Scheme-checked before it goes anywhere near storage, and never
        // fetched: nothing on this server opens a candidate's URL, which is
        // both an SSRF hole and a way of telling a third party that we read it.
        if ($link !== '' && ! ExternalLink::valid($link)) {
            return back()
                ->withInput()
                ->withErrors(['intro_video_url' => 'Please give a full web address starting with http:// or https://.']);
        }

        // An introduction is only ever required WITH a way out of it. A
        // candidate on a bad connection, or one who would rather not be on
        // camera, writes it instead — and that is not scored any lower.
        if ($vacancy?->intro_video_required && $link === '' && $written === '') {
            return back()
                ->withInput()
                ->withErrors(['intro_video_url' => 'Please add either a short video link or the written introduction below it.']);
        }

        try {
            $this->assessments->submit(
                $assignment,
                $this->answers($request),
                $link !== '' ? $link : null,
                $written !== '' ? $written : null,
            );
        } catch (StageTransitionException $exception) {
            return back()->withInput()->withErrors(['answers' => $exception->getMessage()]);
        }

        return redirect()
            ->route('candidate.assessment', $assignment)
            ->with('status', 'Your assessment has been submitted. We will be in touch — your reference is '
                . $assignment->application?->reference . '.');
    }

    /** @return array<string, string> */
    private function answers(Request $request): array
    {
        $answers = [];

        foreach ((array) $request->input('answers', []) as $key => $value) {
            if (is_string($key) && (is_string($value) || is_null($value))) {
                $answers[$key] = (string) $value;
            }
        }

        return $answers;
    }
}
