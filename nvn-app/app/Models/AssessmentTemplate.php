<?php

namespace App\Models;

use App\Enums\RecruitmentRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The exercise for one role category, plus the rubric it is marked against.
 *
 * Editable, and that is the problem this class is shaped around: a template
 * edited today must not change an exercise somebody was invited to yesterday.
 * So `version` is bumped on every meaningful edit and an assignment carries a
 * full snapshot. The template is the current recipe; the snapshot is the meal
 * that was actually served.
 *
 * `brief` is the short set of approved facts about Naija Virtual Notary that a
 * candidate may rely on while answering. Without it, a customer-service
 * exercise quietly tests whether somebody can guess our refund policy, which
 * is not a skill anybody has.
 */
class AssessmentTemplate extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /** The field types an administrator may use for a task. */
    public const TASK_TYPES = [
        'long_text'  => 'Long answer',
        'short_text' => 'Short answer',
        'select'     => 'Choose one',
        'yes_no'     => 'Yes or no',
        'url'        => 'A link',
    ];

    protected function casts(): array
    {
        return [
            'role_key'  => RecruitmentRole::class,
            'tasks'     => 'array',
            'rubric'    => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(Vacancy::class, 'assessment_template_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssessmentAssignment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The snapshot an assignment stores.
     *
     * Everything a candidate and a reviewer need, frozen: the tasks as worded
     * today, the rubric as worded today, the brief, and the scale. Nothing
     * here is a reference to a row that can change underneath it.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'template_id'     => $this->id,
            'version'         => (int) $this->version,
            'name'            => (string) $this->name,
            'role_key'        => $this->role_key instanceof RecruitmentRole
                ? $this->role_key->value
                : (string) $this->role_key,
            'brief'           => (string) $this->brief,
            'tasks'           => $this->normalisedTasks(),
            'rubric'          => $this->normalisedRubric(),
            'score_scale_max' => (int) ($this->score_scale_max ?: 5),
            'effort_minutes'  => array_values(array_filter([
                $this->effort_minutes_min ? (int) $this->effort_minutes_min : null,
                $this->effort_minutes_max ? (int) $this->effort_minutes_max : null,
            ])),
            'snapshot_at'     => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, type: string, help: string, required: bool, options: list<string>}>
     */
    public function normalisedTasks(): array
    {
        $tasks = [];

        foreach ((array) ($this->tasks ?? []) as $index => $task) {
            if (! is_array($task) || blank($task['label'] ?? null)) {
                continue;
            }

            $type = (string) ($task['type'] ?? 'long_text');

            $tasks[] = [
                'key'      => (string) ($task['key'] ?? 't' . ($index + 1)),
                'label'    => (string) $task['label'],
                'type'     => array_key_exists($type, self::TASK_TYPES) ? $type : 'long_text',
                'help'     => (string) ($task['help'] ?? ''),
                'required' => (bool) ($task['required'] ?? true),
                'options'  => array_values(array_filter(array_map(
                    fn ($option) => is_string($option) ? trim($option) : null,
                    (array) ($task['options'] ?? []),
                ))),
            ];
        }

        return $tasks;
    }

    /**
     * @return list<array{key: string, label: string, anchors: array<string, string>}>
     */
    public function normalisedRubric(): array
    {
        $criteria = [];

        foreach ((array) ($this->rubric ?? []) as $index => $criterion) {
            if (! is_array($criterion) || blank($criterion['label'] ?? null)) {
                continue;
            }

            $anchors = [];

            foreach ((array) ($criterion['anchors'] ?? []) as $score => $text) {
                if (is_string($text) && filled($text)) {
                    $anchors[(string) $score] = trim($text);
                }
            }

            $criteria[] = [
                'key'     => (string) ($criterion['key'] ?? 'c' . ($index + 1)),
                'label'   => (string) $criterion['label'],
                'anchors' => $anchors,
            ];
        }

        return $criteria;
    }

    /** How long the exercise is advertised as taking. */
    public function effortLine(): ?string
    {
        $min = $this->effort_minutes_min ? (int) $this->effort_minutes_min : null;
        $max = $this->effort_minutes_max ? (int) $this->effort_minutes_max : null;

        return match (true) {
            $min && $max && $min !== $max => 'about ' . $min . '-' . $max . ' minutes',
            (bool) ($min ?: $max)         => 'about ' . ($min ?: $max) . ' minutes',
            default                       => null,
        };
    }
}
