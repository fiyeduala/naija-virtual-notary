<?php

namespace App\Http\Controllers\Careers;

use App\Http\Controllers\Controller;
use App\Models\Vacancy;
use App\Support\RecruitmentPermission;
use App\Support\SourceAttribution;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public careers pages.
 *
 * Two visibility rules, and they are not the same rule:
 *
 *   - The LISTING shows published vacancies only. A paused or closed role is
 *     not advertised.
 *   - A DIRECT LINK renders published, paused and closed roles, because a link
 *     posted on a job board three weeks ago should explain itself rather than
 *     404 at somebody who is trying to work out what happened. Draft and
 *     archived roles 404 for everyone except a member of staff who holds
 *     vacancies.manage, who needs to proof the page before publishing it.
 *
 * Whether the page may be APPLIED to is a third question, answered by
 * Vacancy::acceptsApplications() on the server at submission time.
 */
class CareersController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        // First touch wins, and it is captured here as well as on the detail
        // page so a visitor who arrives on the listing from a WhatsApp advert
        // is still attributed when they click through.
        SourceAttribution::capture($request);

        return view('public.careers.index', [
            'vacancies' => Vacancy::query()
                ->listed()
                ->where(fn ($query) => $query->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('closes_at')->orWhere('closes_at', '>', now()))
                ->orderBy('closes_at')
                ->orderBy('title')
                ->get(),
        ]);
    }

    public function show(Request $request, Vacancy $vacancy): View
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        $visible = $vacancy->state->isPubliclyVisible()
            || RecruitmentPermission::check(RecruitmentPermission::MANAGE_VACANCIES);

        abort_unless($visible, 404);

        SourceAttribution::capture($request);

        return view('public.careers.show', [
            'vacancy'  => $vacancy,
            'preview'  => ! $vacancy->state->isPubliclyVisible(),
            'template' => $vacancy->template,
        ]);
    }

    /**
     * The recruitment privacy notice.
     *
     * Editable by the owner and marked for their review. Nothing on this
     * platform asserts that the wording satisfies any particular law; it says
     * what is collected, who sees it, how long it is kept and who to write to.
     */
    public function privacy(): View
    {
        abort_unless(RecruitmentPermission::moduleEnabled(), 404);

        return view('public.careers.privacy');
    }
}
