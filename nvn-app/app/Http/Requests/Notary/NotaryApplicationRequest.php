<?php

namespace App\Http\Requests\Notary;

use App\Enums\Specialty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class NotaryApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name'         => ['required', 'string', 'max:255'],
            'email'             => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'             => ['required', 'string', 'max:30'],
            'password'          => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],

            'entity_type'       => ['required', Rule::in(['individual', 'agency'])],
            'organization_name' => ['nullable', 'required_if:entity_type,agency', 'string', 'max:255'],
            'license_ref'       => ['required', 'string', 'max:255'],
            'scn'               => ['required', 'string', 'max:100'],
            'year_of_oath'      => ['required', 'integer', 'min:1950', 'max:' . date('Y')],
            'experience'        => ['required', 'string', 'max:5000'],
            'specialties'       => ['required', 'array', 'min:1'],
            'specialties.*'     => [Rule::enum(Specialty::class)],
            'motivation'        => ['required', 'string', 'max:5000'],

            // Uploaded documents
            'valid_id'          => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'oath_of_office'    => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            // Consents
            'accuracy_consent'  => ['accepted'],
            'delegation_consent'=> ['accepted'],
            'commission_consent'=> ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'accuracy_consent.accepted'   => 'You must confirm the information provided is accurate.',
            'delegation_consent.accepted' => 'You must consent to the delegated-notarization terms to register.',
            'commission_consent.accepted' => 'You must agree to the 50% commission split to register.',
        ];
    }
}
