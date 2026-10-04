<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\OrganizationApplicationRequest;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Notifications\Admin\OrganizationAppliedNotification;
use App\Support\AdminAlert;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A body asking to partner with the platform.
 *
 * The application IS the organization record, created at status 'pending'.
 * That is the one design decision worth stating: there is no applications
 * table to copy across at approval, so an admin correcting a misspelled name
 * or a wrong RC number is simply editing the record they will keep using
 * afterwards. It follows NotaryProfile's verification_status pattern rather
 * than inventing a second one.
 *
 * A pending body is inert. It has no slug, so its landing page cannot be
 * reached; no portal credentials, so it cannot sign in; and it is absent from
 * every count that means "our partners".
 */
class OrganizationApplicationController extends Controller
{
    public function show(): View
    {
        return view('public.organization-apply');
    }

    public function store(OrganizationApplicationRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('contact_email');

        // A body that applies twice is almost always the same person sending
        // it again because they spotted a mistake, or because nobody has
        // replied yet. A second record would split one conversation in half
        // and give the office two half-right versions of the same body, so an
        // open application is updated in place instead.
        $existing = Organization::where('contact_email', $email)
            ->whereIn('status', ['pending', 'active', 'paused'])
            ->first();

        if ($existing && ! $existing->isPending()) {
            return back()->withInput()->withErrors([
                'contact_email' => 'There is already a partnership on this email address. '
                    . 'Please get in touch with us rather than applying again.',
            ]);
        }

        $organization = DB::transaction(function () use ($request, $existing) {
            $fields = $request->applicationFields();

            $organization = $existing
                ? tap($existing)->update($fields + ['applied_at' => now()])
                : Organization::create($fields + [
                    'status'     => 'pending',
                    'applied_at' => now(),
                ]);

            if ($logo = $request->file('logo')) {
                // The logo is shown on a public landing page, so it goes on
                // the public disk; the paperwork behind the application does
                // not, and goes on the private one.
                $organization->update([
                    'logo_url' => $logo->store('organization-logos', 'public'),
                ]);
            }

            foreach (['registration' => 'registration', 'authorisation' => 'authorisation'] as $field => $type) {
                if (! $file = $request->file($field)) {
                    continue;
                }

                OrganizationDocument::create([
                    'organization_id'   => $organization->id,
                    'document_type'     => $type,
                    'file_url'          => $file->store('organization-documents', 'private'),
                    'original_filename' => $file->getClientOriginalName(),
                    'status'            => 'pending',
                ]);
            }

            return $organization;
        });

        AuditLogger::record('organization.applied', 'organization', $organization->id, [
            'name'   => $organization->name,
            'sector' => $organization->sector,
            'resubmitted' => $existing !== null,
        ]);

        AdminAlert::send(new OrganizationAppliedNotification($organization));

        return redirect()->route('organization.apply.show')
            ->with('status', 'Thank you — your application is with us. We will be in touch on '
                . $organization->contact_email . ' or ' . $organization->phone . '.');
    }
}
