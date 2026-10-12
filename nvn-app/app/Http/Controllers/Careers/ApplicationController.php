<?php

namespace App\Http\Controllers\Careers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Careers\JobApplicationRequest;
use App\Models\Vacancy;
use App\Services\Recruitment\ApplicationIntakeService;
use App\Support\RecruitmentPermission;
use App\Support\SourceAttribution;
use App\Support\UploadGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The application form, and what happens when it is submitted.
 *
 * The one behaviour worth describing before reading the code: a repeat
 * submission is a success, not an error. The database refuses the duplicate,
 * the intake service catches that and hands back the application that already
 * exists, and this controller shows the same confirmation page with the same
 * reference. A candidate who double-tapped Submit, or whose phone retried the
 * POST on a flaky connection, sees the thing they expected rather than a
 * message telling them they have already applied — which would also be a way
 * of asking the form whether an address is on file.
 */
class ApplicationController extends Controller
{
    public function __construct(private ApplicationIntakeService $intake) {}

    public function create(Request $request, Vacancy $vacancy): View|RedirectResponse
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);
        abort_unless($vacancy->state->isPubliclyVisible(), 404);

        // A closed advert keeps its page, but there is nothing to fill in.
        if (! $vacancy->acceptsApplications()) {
            return redirect()->route('careers.show', $vacancy->slug)
                ->with('status', $vacancy->closureReason());
        }

        SourceAttribution::capture($request);

        return view('public.careers.apply', [
            'vacancy'   => $vacancy,
            'questions' => $vacancy->questions(),
            'accept'    => UploadGuard::acceptAttribute('cv'),
            'maxSize'   => UploadGuard::maxMb('cv'),
            'scanner'   => UploadGuard::scannerAvailable(),
        ]);
    }

    public function store(JobApplicationRequest $request, Vacancy $vacancy): RedirectResponse
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        // The server's answer, not the page's. A POST crafted against a closed
        // vacancy is refused here even though the form was never shown.
        if (! $vacancy->acceptsApplications()) {
            return redirect()->route('careers.show', $vacancy->slug)
                ->with('status', $vacancy->closureReason());
        }

        try {
            $result = $this->intake->submit(
                $vacancy,
                $request->applicationData(),
                $request->file('cv'),
                SourceAttribution::current($request),
            );
        } catch (\RuntimeException $e) {
            // An upload the guard refused, or a scanner that could not be
            // reached. The message is written for the candidate.
            return back()->withInput($request->except('cv'))
                ->withErrors(['cv' => $e->getMessage()]);
        }

        return redirect()->route('careers.applied', [
            'vacancy'   => $vacancy->slug,
            'reference' => $result['application']->reference,
        ]);
    }

    /**
     * The confirmation page.
     *
     * The reference is in the URL, and it is deliberately not a key to
     * anything: this page shows the role, the reference and what happens next.
     * It shows no name, no address and no answers, so a reference guessed or
     * shared reveals nothing about the person who applied.
     */
    public function done(Vacancy $vacancy, string $reference): View
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        return view('public.careers.applied', [
            'vacancy'   => $vacancy,
            'reference' => \App\Support\ApplicationReference::normalise($reference),
        ]);
    }
}
