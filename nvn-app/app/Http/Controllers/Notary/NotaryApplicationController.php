<?php

namespace App\Http\Controllers\Notary;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notary\NotaryApplicationRequest;
use App\Models\NotaryCredential;
use App\Models\NotaryProfile;
use App\Models\User;
use App\Services\OtpService;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotaryApplicationController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function show(): View
    {
        return view('public.partner');
    }

    public function store(NotaryApplicationRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'full_name' => $request->validated('full_name'),
                'email'     => $request->validated('email'),
                'phone'     => $request->validated('phone'),
                'password'  => $request->validated('password'),
                'role'      => UserRole::Notary,
                'status'    => 'pending',
            ]);

            $profile = NotaryProfile::create([
                'user_id'              => $user->id,
                'entity_type'          => $request->validated('entity_type'),
                'organization_name'    => $request->validated('organization_name'),
                'license_ref'          => $request->validated('license_ref'),
                'scn'                  => $request->validated('scn'),
                'year_of_oath'         => $request->validated('year_of_oath'),
                'experience'           => $request->validated('experience'),
                'specialties'          => $request->validated('specialties'),
                'motivation'           => $request->validated('motivation'),
                'verification_status'  => 'pending',
                'commission_rate'      => \App\Support\Settings::defaultCommissionRate(),
                'delegation_consent'   => true,
                'delegation_consent_at'=> now(),
            ]);

            // Store uploaded credential documents (private disk)
            foreach (['valid_id' => 'valid_id', 'oath_of_office' => 'oath_of_office'] as $field => $type) {
                $path = $request->file($field)->store('notary-credentials', 'private');
                NotaryCredential::create([
                    'notary_profile_id' => $profile->id,
                    'document_type'     => $type,
                    'file_url'          => $path,
                    'original_filename' => $request->file($field)->getClientOriginalName(),
                    'status'            => 'pending',
                ]);
            }

            return $user;
        });

        AuditLogger::record('notary.applied', 'user', $user->id, [], $user->id);

        // Log in, verify email via OTP, then move to onboarding-fee payment.
        Auth::login($user);
        $this->otp->issue($user);

        return redirect()->route('verify.show')
            ->with('status', 'Application received. Verify your email, then pay the onboarding fee.');
    }
}
