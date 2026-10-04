<?php

namespace App\Http\Requests\Organization;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A body applying to partner with the platform.
 *
 * Modelled on NotaryApplicationRequest, and asking the same kind of questions
 * the admin asks when onboarding a body by hand — minus the money. Nothing
 * here sets a price, a rate or an arrangement: those are the negotiation, and
 * an applicant does not propose their own terms.
 *
 * Contact details are mandatory and separate from the body itself, because
 * they are what the office uses when a submission has a mistake in it.
 */
class OrganizationApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'sector'              => ['required', Rule::in(array_keys(self::SECTORS))],
            'website'             => ['nullable', 'url', 'max:255'],
            'address'             => ['required', 'string', 'max:1000'],
            'about'               => ['required', 'string', 'max:5000'],
            'expected_volume'     => ['required', Rule::in(array_keys(self::VOLUMES))],

            // The person to telephone.
            'contact_name'  => ['required', 'string', 'max:255'],
            'contact_role'  => ['required', 'string', 'max:255'],
            // One application per contact address. A body that applies twice
            // should have its first application corrected, not duplicated —
            // see OrganizationApplicationController.
            'contact_email' => ['required', 'email', 'max:255'],
            'phone'         => ['required', 'string', 'max:30'],

            // Supporting paperwork. The logo is optional because a government
            // office often does not have a file of one to hand, and the
            // application should not stall on it.
            'logo'           => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'registration'   => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'authorisation'  => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            'accuracy_consent' => ['accepted'],
            'contact_consent'  => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'accuracy_consent.accepted' => 'Please confirm the information you have given is accurate.',
            'contact_consent.accepted'  => 'We need your permission to contact you about this application.',
            'registration.required'     => 'Please attach your certificate of registration, or a request on your letterhead.',
        ];
    }

    /** Only the fields the organization record lets an application write. */
    public function applicationFields(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(Organization::APPLICATION_FIELDS),
        );
    }

    /** What kind of body this is. Shown as a dropdown, stored as a string. */
    public const SECTORS = [
        'government'   => 'Government body or agency',
        'embassy'      => 'Embassy, consulate or high commission',
        'law_firm'     => 'Law firm or chambers',
        'corporate'    => 'Company or private firm',
        'education'    => 'School, college or university',
        'ngo'          => 'NGO or non-profit',
        'other'        => 'Something else',
    ];

    /** Roughly how much work they expect to send. A planning figure only. */
    public const VOLUMES = [
        'under_10'   => 'Fewer than 10 notarizations a month',
        '10_to_50'   => '10 to 50 a month',
        '50_to_200'  => '50 to 200 a month',
        'over_200'   => 'More than 200 a month',
        'unsure'     => 'We are not sure yet',
    ];
}
