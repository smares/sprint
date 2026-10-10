<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * A rule of a project: when the trigger fires (and the conditions hold), the actions run on the task.
 * The rule acts under its own name, with the rights of the person who created it: if they may no longer
 * edit the project, the rule switches itself off instead of running.
 *
 * `conditions` is `{status_id?, tag_id?, assignee_id?}` and is checked against the task after the change;
 * `actions` is a list of `{type, value}` (an id, a number of days or a text, depending on the type).
 */
#[Fillable(['project_id', 'created_by', 'name', 'trigger', 'trigger_value', 'conditions', 'actions', 'enabled'])]
class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'trigger' => AutomationTrigger::class,
            'conditions' => 'array',
            'actions' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * How the rule is named in history, comments and the inbox.
     */
    public function label(): string
    {
        return self::labelFor($this->name);
    }

    /**
     * The same for a name that history or a comment kept after the rule was renamed or deleted.
     */
    public static function labelFor(string $name): string
    {
        return __('Automation “:name”', ['name' => $name]);
    }

    /**
     * Whether the creator may still let the rule act in the project.
     */
    public function creatorMayEdit(): bool
    {
        return $this->creator !== null
            && $this->creator->isActive()
            && Gate::forUser($this->creator)->allows('edit', $this->project);
    }

    /**
     * @param  array<string, mixed>  $data  The data of the history entry that fired the trigger.
     */
    public function matches(Task $task, ActivityType $type, array $data): bool
    {
        if ($this->trigger->activityType() !== $type) {
            return false;
        }

        $triggered = match ($this->trigger) {
            AutomationTrigger::StatusChanged => $task->status_id === $this->trigger_value,
            AutomationTrigger::AssigneeChanged => $this->trigger_value === null || $task->assignee_id === $this->trigger_value,
            AutomationTrigger::TagAdded => in_array($this->trigger_value, $data['ids'] ?? [], true),
            AutomationTrigger::FieldSet => ($data['option_id'] ?? null) === $this->trigger_value,
        };

        if (! $triggered) {
            return false;
        }

        $conditions = $this->conditions ?? [];

        return (! isset($conditions['status_id']) || $task->status_id === (int) $conditions['status_id'])
            && (! isset($conditions['assignee_id']) || $task->assignee_id === (int) $conditions['assignee_id'])
            && (! isset($conditions['tag_id']) || $task->tags()->whereKey((int) $conditions['tag_id'])->exists());
    }

    /**
     * @return list<array{type: AutomationAction, value: mixed}>
     */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->actions ?? [] as $action) {
            $type = AutomationAction::tryFrom((string) ($action['type'] ?? ''));

            if ($type !== null) {
                $steps[] = ['type' => $type, 'value' => $action['value'] ?? null];
            }
        }

        return $steps;
    }
}
