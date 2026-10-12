<?php

namespace App\Http\Requests\Careers;

use App\Models\Vacancy;
use App\Support\ExternalLink;
use App\Support\UploadGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The initial application form.
 *
 * What is NOT here is as deliberate as what is. No government identity number,
 * no bank details, no guarantor, no date of birth, no marital status, no
 * religion, no photograph. None of those is needed to decide whether somebody
 * can do a customer-service job, and asking for them at application time means
 * holding sensitive records on dozens of people who will never be hired. The
 * few things that genuinely are needed later are asked for once an offer has
 * been accepted, and only when the role switches them on.
 *
 * The CV rule comes from config so the owner can change the limit without
 * touching code, and the real enforcement is App\Support\UploadGuard, which
 * sniffs the file's own content rather than trusting its extension.
 */
class JobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public form. Whether the vacancy will accept it is a separate
        // question, answered on the server in the controller and again in
        // ApplicationIntakeService.
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) config('recruitment.uploads.cv.max_kb', 5120);
        $exts  = implode(',', (array) config('recruitment.uploads.cv.extensions', ['pdf', 'docx']));

        return [
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            // rfc only, deliberately not dns. A DNS lookup here makes a public
            // form depend on this server resolving a stranger's mail domain at
            // the moment they press Apply — and when it fails, the application
            // is simply refused and the person goes away. A mistyped domain is
            // caught far more kindly by the confirmation email not arriving,
            // which they can see and we can resend.
            'email'     => ['required', 'string', 'email:rfc', 'max:190'],
            'phone'     => ['required', 'string', 'max:40'],
            'country'   => ['required', 'string', 'max:80'],
            'city'      => ['nullable', 'string', 'max:120'],
            'timezone'  => ['required', 'string', 'max:64', 'timezone'],

            'cv' => ['required', 'file', 'max:' . $maxKb, 'mimes:' . $exts],

            'motivation'          => ['required', 'string', 'min:40', 'max:6000'],
            'relevant_experience' => ['required', 'string', 'min:40', 'max:6000'],

            // Optional, and never a condition of applying. Nobody is asked for
            // a social media account.
            'linkedin_url'    => ['nullable', 'string', 'max:2048'],
            'portfolio_url'   => ['nullable', 'string', 'max:2048'],
            'work_sample_url' => ['nullable', 'string', 'max:2048'],

            'expected_salary_min'      => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'expected_salary_max'      => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'expected_salary_currency' => ['nullable', 'string', 'size:3'],
            'expected_pay_period'      => ['nullable', Rule::in(array_keys(Vacancy::PAY_PERIODS))],

            'availability_start' => ['nullable', 'date', 'after_or_equal:today'],
            'notice_period'      => ['nullable', 'string', 'max:80'],

            'available_during_hours' => ['accepted'],
            'has_suitable_computer'  => ['accepted'],
            'internet_reliability'   => ['required', 'string', 'max:40'],
            'backup_arrangements'    => ['required', 'string', 'max:40'],
            'remote_experience'      => ['nullable', 'string', 'max:2000'],

            'heard_about' => ['nullable', 'string', 'max:60'],

            // The privacy notice. Recorded with its version and the time, so
            // what somebody agreed to is knowable later.
            'privacy_accepted' => ['accepted'],

            // Separate, and unticked by default. A blank box here never turns
            // an earlier consent off.
            'future_opportunities_consent' => ['nullable', 'boolean'],

            'answers'   => ['array'],
            'answers.*' => ['nullable', 'string', 'max:8000'],
        ];
    }

    public function messages(): array
    {
        return [
            'available_during_hours.accepted' => 'Please confirm you can work the hours this role states. If you cannot, this is not the right role and we would rather you knew now.',
            'has_suitable_computer.accepted'  => 'Please confirm you have a computer you can work on.',
            'privacy_accepted.accepted'       => 'Please confirm you have read how we handle your application.',
            'cv.mimes'                        => 'Please upload your CV as a PDF or a Word (.docx) file.',
            'cv.max'                          => 'Your CV must be smaller than ' . UploadGuard::maxMb('cv') . '.',
            'timezone.timezone'               => 'Please pick your time zone from the list.',
        ];
    }

    /**
     * The vacancy's own questions, and the links.
     *
     * Screening questions are administrator-written, so their required flags
     * live in data rather than in the rules above. The links are validated for
     * shape only — http or https, a plausible host, a sane length. Nothing
     * fetches them, nothing embeds them, and nothing renders them as anything
     * other than text with an href.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $vacancy = $this->route('vacancy');
            $answers = (array) $this->input('answers', []);

            if ($vacancy instanceof Vacancy) {
                foreach ($vacancy->questions() as $question) {
                    $key = (string) $question['key'];

                    if ($question['required'] && blank($answers[$key] ?? null)) {
                        $validator->errors()->add('answers.' . $key, 'This question needs an answer.');
                    }

                    if ($question['type'] === 'url' && filled($answers[$key] ?? null)
                        && ! ExternalLink::valid((string) $answers[$key])) {
                        $validator->errors()->add('answers.' . $key, 'Please give a full web address starting with https://');
                    }

                    if ($question['type'] === 'select' && filled($answers[$key] ?? null)
                        && $question['options'] !== []
                        && ! in_array((string) $answers[$key], $question['options'], true)) {
                        $validator->errors()->add('answers.' . $key, 'Please choose one of the options listed.');
                    }
                }
            }

            foreach (['linkedin_url', 'portfolio_url', 'work_sample_url'] as $field) {
                if (filled($this->input($field)) && ! ExternalLink::valid((string) $this->input($field))) {
                    $validator->errors()->add($field, 'Please give a full web address starting with https://, or leave this blank.');
                }
            }

            $min = $this->input('expected_salary_min');
            $max = $this->input('expected_salary_max');

            if (filled($min) && filled($max) && (float) $max < (float) $min) {
                $validator->errors()->add('expected_salary_max', 'The top of your range cannot be below the bottom of it.');
            }
        });
    }

    /**
     * Normalised values for the intake service.
     *
     * The links are put through ExternalLink::normalise() here rather than in
     * the service, so what is stored is already the cleaned form.
     *
     * @return array<string, mixed>
     */
    public function applicationData(): array
    {
        $data = $this->safe()->all();

        foreach (['linkedin_url', 'portfolio_url', 'work_sample_url'] as $field) {
            $data[$field] = filled($data[$field] ?? null)
                ? ExternalLink::normalise((string) $data[$field])
                : null;
        }

        $data['privacy_version']              = \App\Support\RecruitmentPrivacyNotice::version();
        $data['future_opportunities_consent'] = (bool) $this->boolean('future_opportunities_consent');

        return $data;
    }
}
