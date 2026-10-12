<?php

namespace App\Http\Controllers\Candidate\Concerns;

use App\Models\AssessmentAssignment;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\Offer;
use App\Models\OnboardingItem;

/**
 * The ownership check every candidate route runs.
 *
 * Route model binding resolves an id into a record. It does not ask whose
 * record it is, and nothing about an id in a URL makes it the requester's —
 * which is the whole of the IDOR class of bug. So each helper below walks from
 * the bound model back to its candidate_id and compares it to the signed-in
 * candidate, and aborts 404 rather than 403 when they differ: a 403 confirms
 * the row exists, and whether somebody else has an application here is not
 * this candidate's business.
 *
 * Deliberately not a policy class. This codebase has no app/Policies and
 * authorizes with explicit checks at the point of use; a new convention for one
 * module would be the odd one out.
 */
trait OwnedByCandidate
{
    protected function candidate(): Candidate
    {
        $candidate = auth('candidate')->user();

        abort_unless($candidate instanceof Candidate, 403);

        return $candidate;
    }

    protected function ownApplication(JobApplication $application): JobApplication
    {
        abort_unless($application->candidate_id === $this->candidate()->id, 404);

        return $application->load('vacancy');
    }

    protected function ownAssignment(AssessmentAssignment $assignment): AssessmentAssignment
    {
        $assignment->loadMissing('application.vacancy', 'template');

        abort_unless(
            $assignment->application
                && $assignment->application->candidate_id === $this->candidate()->id,
            404,
        );

        // A cancelled assignment is gone as far as the candidate is concerned.
        // It happens when a reviewer re-invites, and showing the dead one would
        // let somebody work on a task nobody will ever read.
        abort_if($assignment->cancelled_at !== null, 404);

        return $assignment;
    }

    protected function ownInterview(Interview $interview): Interview
    {
        $interview->loadMissing('application');

        abort_unless(
            $interview->application
                && $interview->application->candidate_id === $this->candidate()->id,
            404,
        );

        return $interview;
    }

    protected function ownOffer(Offer $offer): Offer
    {
        $offer->loadMissing('application.vacancy');

        abort_unless(
            $offer->application
                && $offer->application->candidate_id === $this->candidate()->id,
            404,
        );

        // A draft or an approved-but-unsent offer is an internal document. The
        // candidate sees it when somebody decides to send it, not before.
        abort_unless(in_array($offer->status, [
            Offer::SENT,
            Offer::ACCEPTED,
            Offer::DECLINED,
        ], true), 404);

        return $offer;
    }

    protected function ownOnboardingItem(OnboardingItem $item): OnboardingItem
    {
        $item->loadMissing('application.vacancy');

        abort_unless(
            $item->application
                && $item->application->candidate_id === $this->candidate()->id,
            404,
        );

        return $item;
    }
}
