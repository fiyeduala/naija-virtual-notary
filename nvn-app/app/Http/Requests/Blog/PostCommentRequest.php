<?php

namespace App\Http\Requests\Blog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;

/**
 * A reader commenting on an article.
 *
 * Two spam measures live here rather than in the controller, because both are
 * really statements about whether the input is valid at all:
 *
 * - a honeypot field that a person never sees and a form-filling bot almost
 *   always completes, and
 * - a minimum time between the page rendering and the form arriving.
 *
 * Neither is a captcha and neither pretends to be. Together they stop the
 * indiscriminate traffic that finds any public form within days of it
 * existing, and anything that gets past them still lands in the moderation
 * queue, which is the actual defence.
 */
class PostCommentRequest extends FormRequest
{
    /** Below this, nobody read an article and wrote a comment. */
    private const MINIMUM_SECONDS = 3;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // A signed-in reader is not asked to type their own name and email
        // again; the form does not show those fields, so they are filled in
        // here from the account before validation sees them.
        if ($user = $this->user()) {
            $this->merge([
                'author_name'  => $user->full_name,
                'author_email' => $user->email,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'author_name'  => ['required', 'string', 'max:120'],
            'author_email' => ['required', 'email', 'max:190'],
            'body'         => ['required', 'string', 'min:2', 'max:3000'],

            // A reply to a comment on this same article, or nothing. The
            // controller checks it belongs to the right article; existence
            // alone is not enough, or a reply could be hung off a comment on
            // somebody else's post.
            'parent_id' => ['nullable', 'integer', 'exists:post_comments,id'],

            // The honeypot. Hidden from a person by CSS and left empty by
            // them; "must be empty" is the whole rule.
            'website' => ['nullable', 'prohibited'],

            'posted_at' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'website.prohibited' => 'That comment looked automated. If this is a mistake, please try again.',
            'body.required'      => 'Your comment cannot be empty.',
            'body.max'           => 'Please keep a comment under 3,000 characters.',
        ];
    }

    /**
     * The timing check, after the field rules have passed.
     *
     * `posted_at` is an encrypted timestamp written into the form when the
     * page rendered, so it cannot be back-dated by whoever is posting — a
     * plain hidden timestamp would be edited by the first bot that bothered.
     * A value we cannot decrypt is treated as a failure rather than waved
     * through: it did not come from one of our forms.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            try {
                $rendered = (int) Crypt::decryptString((string) $this->input('posted_at'));
            } catch (\Throwable) {
                $validator->errors()->add('body', 'That form has expired. Please reload the page and try again.');

                return;
            }

            if (time() - $rendered < self::MINIMUM_SECONDS) {
                $validator->errors()->add('body', 'That was submitted very quickly. Please try again.');
            }
        });
    }
}
