<?php

namespace App\Services\Recruitment;

use App\Enums\ApplicationStage;
use App\Models\ApplicationDocument;
use App\Models\Candidate;
use App\Models\JobApplication;
use App\Models\Vacancy;
use App\Support\ApplicationReference;
use App\Support\RecruitmentPrivacyNotice;
use App\Support\AuditLogger;
use App\Support\EmailNormalizer;
use App\Support\RecruitmentTemplates;
use App\Support\UploadGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Taking in one application from the public form.
 *
 * The hard requirement here is that a candidate pressing Apply twice, or
 * retrying after their phone lost signal halfway through, ends up with exactly
 * one application — and is told it worked both times. Two mechanisms do that
 * together:
 *
 *   - `candidates.email_canonical` is unique, and `job_applications` is unique
 *     on (vacancy, candidate). These are database constraints, not checks, so
 *     two requests arriving in the same millisecond cannot both pass.
 *   - A violation of either is caught and read as "somebody got there first",
 *     and the existing row is returned. The second submission is a success
 *     with nothing new written, which is what the candidate wants to hear.
 *
 * Saving does not depend on mail. The acknowledgment is a row in the outbox,
 * written in the same transaction; a mail server that is down delays the email
 * and loses nothing.
 *
 * What this never collects is as important as what it does: no government ID,
 * no bank details, no date of birth, no photograph, no guarantor. Those belong
 * to a late stage, if at all, and are asked for separately.
 */
class ApplicationIntakeService
{
    public function __construct(private RecruitmentOutbox $outbox) {}

    /**
     * @param  array<string, mixed>  $data   validated form input
     * @param  array<string, mixed>  $source ['channel' => , 'campaign' => , 'medium' => ]
     * @return array{application: JobApplication, created: bool}
     */
    public function submit(
        Vacancy $vacancy,
        array $data,
        ?UploadedFile $cv,
        array $source = [],
    ): array {
        // Checked here as well as in the controller: the server decides whether
        // a vacancy is open, not the page that drew the button.
        if (! $vacancy->acceptsApplications()) {
            throw new \RuntimeException('This vacancy is not accepting applications.');
        }

        // The file lands on the private disk before the transaction, because a
        // file write is not something a rollback can undo. If the insert turns
        // out to be a duplicate the bytes are removed again below.
        $stored = $cv ? UploadGuard::store($cv, 'cv', 'recruitment/cv') : null;

        try {
            $result = DB::transaction(fn () => $this->persist($vacancy, $data, $stored, $source));
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('private')->delete($stored['path']);
            }

            throw $e;
        }

        if ($stored && ! $result['created']) {
            // A repeat submission. The earlier CV stands; this upload is
            // discarded rather than added as a second version, so a network
            // retry cannot quietly replace the evidence we already have.
            Storage::disk('private')->delete($stored['path']);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>  $source
     * @return array{application: JobApplication, created: bool}
     */
    private function persist(Vacancy $vacancy, array $data, ?array $stored, array $source): array
    {
        $candidate = $this->candidateFor($data);

        $existing = JobApplication::query()
            ->where('vacancy_id', $vacancy->id)
            ->where('candidate_id', $candidate->id)
            ->first();

        if ($existing) {
            return ['application' => $existing, 'created' => false];
        }

        $reference = ApplicationReference::unique(
            fn (string $candidateRef) => JobApplication::query()->where('reference', $candidateRef)->exists(),
        );

        try {
            $application = JobApplication::create([
                'reference'               => $reference,
                'vacancy_id'              => $vacancy->id,
                'candidate_id'            => $candidate->id,
                'stage'                   => ApplicationStage::Applied->value,
                'stage_changed_at'        => now(),
                'applied_at'              => now(),
                'motivation'              => $this->text($data, 'motivation', 6000),
                'relevant_experience'     => $this->text($data, 'relevant_experience', 6000),
                'linkedin_url'            => $data['linkedin_url'] ?? null,
                'portfolio_url'           => $data['portfolio_url'] ?? null,
                'work_sample_url'         => $data['work_sample_url'] ?? null,
                'expected_salary_min_minor' => $this->minor($data['expected_salary_min'] ?? null),
                'expected_salary_max_minor' => $this->minor($data['expected_salary_max'] ?? null),
                'expected_salary_currency'  => $data['expected_salary_currency'] ?? null,
                'expected_pay_period'       => $data['expected_pay_period'] ?? null,
                'availability_start'      => $data['availability_start'] ?? null,
                'notice_period'           => $this->text($data, 'notice_period', 190),
                'available_during_hours'  => (bool) ($data['available_during_hours'] ?? false),
                'has_suitable_computer'   => (bool) ($data['has_suitable_computer'] ?? false),
                'internet_reliability'    => $this->text($data, 'internet_reliability', 190),
                'backup_arrangements'     => $this->text($data, 'backup_arrangements', 1000),
                'remote_experience'       => $this->text($data, 'remote_experience', 2000),
                'heard_about'             => $this->text($data, 'heard_about', 190),
                'source_channel'          => $source['channel'] ?? null,
                'source_campaign'         => $source['campaign'] ?? null,
                'source_medium'           => $source['medium'] ?? null,
                'privacy_version'         => (string) ($data['privacy_version'] ?? RecruitmentPrivacyNotice::version()),
                'privacy_accepted_at'     => now(),
                'screening_snapshot'      => $vacancy->questions(),
            ]);
        } catch (QueryException $e) {
            // The unique index fired: a concurrent request created it between
            // our select and our insert. Theirs is the application.
            $concurrent = JobApplication::query()
                ->where('vacancy_id', $vacancy->id)
                ->where('candidate_id', $candidate->id)
                ->first();

            if (! $concurrent) {
                throw $e;
            }

            return ['application' => $concurrent, 'created' => false];
        }

        $this->writeAnswers($application, $vacancy, $data);

        if ($stored) {
            ApplicationDocument::create([
                'job_application_id'   => $application->id,
                'candidate_id'         => $candidate->id,
                'kind'                 => ApplicationDocument::KIND_CV,
                'disk'                 => 'private',
                'path'                 => $stored['path'],
                'original_name'        => $stored['original_name'],
                'mime'                 => $stored['mime'],
                'size'                 => $stored['size'],
                'scan_status'          => $stored['scan_status'],
                'scanned_at'           => $stored['scanned_at'],
                'is_sensitive'         => false,
                'uploaded_by_candidate' => true,
                'version'              => 1,
            ]);
        }

        $application->stageEvents()->create([
            'from_stage'  => null,
            'to_stage'    => ApplicationStage::Applied->value,
            'actor_type'  => \App\Models\ApplicationStageEvent::ACTOR_CANDIDATE,
            'reason'      => 'Application submitted.',
        ]);

        $this->outbox->queue(RecruitmentTemplates::ACKNOWLEDGEMENT, $application, [
            'action_url' => route('candidate.login'),
            'data'       => $this->outbox->baseData($application),
        ]);

        AuditLogger::record('recruitment_application.received', 'job_application', $application->id, [
            'reference' => $application->reference,
            'vacancy'   => $vacancy->slug,
            'source'    => $source['channel'] ?? null,
            // Masked: an audit row should not be a list of who applied here.
            'email'     => EmailNormalizer::mask((string) $candidate->email),
        ]);

        return ['application' => $application, 'created' => true];
    }

    /**
     * Find or make the candidate record, race-safe.
     *
     * The address is matched on its canonical form and stored as typed. Note
     * what updating does NOT touch: the future-opportunities consent. A second
     * application must not quietly flip a choice made on the first one.
     *
     * @param  array<string, mixed>  $data
     */
    private function candidateFor(array $data): Candidate
    {
        $email     = EmailNormalizer::clean((string) $data['email']);
        $canonical = EmailNormalizer::canonical($email);

        $attributes = [
            'full_name'            => $this->text($data, 'full_name', 190),
            'email'                => $email,
            'phone'                => $this->text($data, 'phone', 40),
            'country'              => $this->text($data, 'country', 90),
            'city'                 => $this->text($data, 'city', 90),
            'timezone'             => $this->text($data, 'timezone', 64),
            'privacy_version'      => (string) ($data['privacy_version'] ?? RecruitmentPrivacyNotice::version()),
            'privacy_accepted_at'  => now(),
        ];

        $existing = Candidate::query()->where('email_canonical', $canonical)->first();

        if ($existing) {
            // Their latest details win — somebody who has moved city since
            // their last application should not be contacted at the old one.
            $existing->forceFill($attributes)->save();

            $this->applyConsent($existing, $data);

            return $existing;
        }

        try {
            $candidate = Candidate::create($attributes + ['email_canonical' => $canonical]);
        } catch (QueryException $e) {
            $candidate = Candidate::query()->where('email_canonical', $canonical)->first();

            if (! $candidate) {
                throw $e;
            }
        }

        $this->applyConsent($candidate, $data);

        return $candidate;
    }

    /**
     * The separate, unticked box about future openings.
     *
     * Only ever turned on by somebody ticking it, and only ever turned off by
     * the candidate themselves on their own screen — never cleared here by a
     * later application that left it blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyConsent(Candidate $candidate, array $data): void
    {
        if (! ($data['future_opportunities_consent'] ?? false)) {
            return;
        }

        if ($candidate->future_opportunities_consent) {
            return;
        }

        $candidate->forceFill([
            'future_opportunities_consent'    => true,
            'future_opportunities_consent_at' => now(),
        ])->save();
    }

    /**
     * Store the answers to this vacancy's own screening questions.
     *
     * The questions are snapshotted onto the application as well, so a
     * reviewer reading it in three months sees the question that was actually
     * asked rather than whatever the vacancy says by then.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeAnswers(JobApplication $application, Vacancy $vacancy, array $data): void
    {
        $answers = (array) ($data['answers'] ?? []);
        $order   = 0;

        foreach ($vacancy->questions() as $question) {
            $key = (string) ($question['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $application->answers()->create([
                'question_key'   => $key,
                'question_label' => Str::limit((string) ($question['label'] ?? $key), 190, ''),
                'question_type'  => (string) ($question['type'] ?? 'long_text'),
                'answer'         => Str::limit((string) ($answers[$key] ?? ''), 8000, ''),
                'position'       => $order++,
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function text(array $data, string $key, int $limit): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    /**
     * Money in minor units, always. A salary expectation typed as "250000"
     * is stored as 25000000 kobo, so nothing downstream has to guess whether
     * a figure is naira or kobo.
     */
    private function minor(mixed $major): ?int
    {
        if ($major === null || $major === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9.]/', '', (string) $major) ?? '';

        return $clean === '' ? null : (int) round(((float) $clean) * 100);
    }
}
