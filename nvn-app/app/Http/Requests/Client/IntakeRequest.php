<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Personal details (pre-filled from profile, still validated)
            'first_name'   => ['required', 'string', 'max:255'],
            'last_name'    => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255'],
            'phone'        => ['required', 'string', 'max:30'],

            // Document details
            'document_use'     => ['required', 'string', 'max:2000'],
            'document'         => ['required', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:15360'],
            'additional'       => ['nullable', 'array'],
            'additional.*'     => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:15360'],
            'identification'   => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            // Optional in-app signature (base64 PNG data URI from canvas)
            'client_signature' => ['nullable', 'string', 'starts_with:data:image/png;base64,'],

            // Currency for this request
            'currency'     => ['required', Rule::in(config('nvn.currencies'))],

            // Hard-copy delivery
            'hard_copy'    => ['required', 'boolean'],
            'street'       => ['nullable', 'required_if:hard_copy,1', 'string', 'max:255'],
            'apartment'    => ['nullable', 'string', 'max:255'],
            'city'         => ['nullable', 'required_if:hard_copy,1', 'string', 'max:255'],
            'state'        => ['nullable', 'required_if:hard_copy,1', 'string', 'max:255'],
            'postal_code'  => ['nullable', 'string', 'max:30'],
            'country'      => ['nullable', 'required_if:hard_copy,1', Rule::in(config('nvn.countries'))],

            // Consent
            'consent'      => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'You must agree to the privacy policy and terms, and confirm legal use, to continue.',
            'document.required' => 'Please upload the document to be notarized (signed and filled).',
        ];
    }
}
