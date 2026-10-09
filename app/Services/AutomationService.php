<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\AutomationAction;
use App\Enums\AutomationTrigger;
use App\Models\Automation;
use App\Models\Comment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationNotice;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs the automations of a project after a task changed.
 *
 * `Task::logActivity()` reports every change; the ones that can trigger a rule are held back until the save
 * that caused them is complete (`flush()`), so the rule works on the finished task and a nested save cannot
 * disturb the one still running. While a rule runs, nothing it changes is reported again: rules do not chain.
 * What a rule changes is recorded under the rule's name, not under the name of the person who triggered it.
 */
class AutomationService
{
    /** @var list<array{task: Task, type: ActivityType, data: array<string, mixed>}> */
    private array $pending = [];

    private ?Automation $running = null;

    /**
     * The rule that is running right now; history, comments and notifications are written in its name.
     */
    public function running(): ?Automation
    {
        return $this->running;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Task $task, ActivityType $type, array $data): void
    {
        if ($this->running instanceof Automation || ! AutomationTrigger::forActivity($type) instanceof AutomationTrigger) {
            return;
        }

        $this->pending[] = ['task' => $task, 'type' => $type, 'data' => $data];
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $entry) {
            $this->process($entry['task'], $entry['type'], $entry['data']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function process(Task $task, ActivityType $type, array $data): void
    {
        // Only the rules for this kind of change; most changes find none and cost a single query
        $rules = Automation::query()->enabled()
            ->where('project_id', $task->project_id)
            ->where('trigger', AutomationTrigger::forActivity($type))
            ->with(['creator', 'project'])
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $current = Task::query()->find($task->id);

        if ($current === null || $current->is_section) {
            return;
        }

        $ran = false;

        foreach ($rules as $rule) {
            if ($rule->matches($current, $type, $data)) {
                $this->run($rule, $current);
                $ran = true;
            }
        }

        if ($ran) {
            $this->carryOver($current, $task);
        }
    }

    private function run(Automation $rule, Task $task): void
    {
        if (! $rule->creatorMayEdit()) {
            $rule->update(['enabled' => false]);

            return;
        }

        $this->running = $rule;

        try {
            foreach ($rule->steps() as $step) {
                try {
                    $this->apply($rule, $task, $step['type'], $step['value']);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        } finally {
            $this->running = null;
        }
    }

    private function apply(Automation $rule, Task $task, AutomationAction $action, mixed $value): void
    {
        $project = $rule->project;

        match ($action) {
            AutomationAction::SetAssignee => $this->setAssignee($task, $value === null ? null : $this->viewer($task, (int) $value), $value === null),
            AutomationAction::SetStatus => $this->setStatus($task, (int) $value),
            AutomationAction::AddTag => $this->addTag($task, $project->tags()->find((int) $value)),
            AutomationAction::ShiftDueDate => $this->shiftDueDate($task, (int) $value),
            AutomationAction::Comment => $this->comment($rule, $task, trim((string) $value)),
            AutomationAction::Notify => $this->notify($rule, $task, $this->viewer($task, (int) $value)),
        };
    }

    /**
     * A person who is active and may see the task's project, or null.
     */
    private function viewer(Task $task, int $userId): ?User
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->isActive() || ! in_array($user->id, $task->project->viewerIds(collect([$user])), true)) {
            return null;
        }

        return $user;
    }

    private function setAssignee(Task $task, ?User $user, bool $unassign): void
    {
        if ($user instanceof User || $unassign) {
            $task->update(['assignee_id' => $user?->id]);
        }
    }

    private function setStatus(Task $task, int $statusId): void
    {
        if ($task->project->statuses()->whereKey($statusId)->exists()) {
            $task->update(['status_id' => $statusId]);
        }
    }

    private function addTag(Task $task, ?Tag $tag): void
    {
        if (! $tag instanceof Tag) {
            return;
        }

        $task->logSyncChanges(ActivityType::TagsAdded, ActivityType::TagsRemoved, $task->tags()->syncWithoutDetaching([$tag->id]), fn () => [$tag->name]);
    }

    /**
     * Moves the due date by some days (from today if there is none). A start that would then lie after the
     * due date moves to the due date, as in the bulk edit.
     */
    private function shiftDueDate(Task $task, int $days): void
    {
        $due = ($task->due_date ?? today())->copy()->addDays($days);
        $changes = ['due_date' => $due];

        if ($task->start_date !== null && $task->start_date->greaterThan($due)) {
            $changes['start_date'] = $due;
        }

        $task->update($changes);
    }

    private function comment(Automation $rule, Task $task, string $text): void
    {
        if ($text !== '') {
            Comment::create(['task_id' => $task->id, 'user_id' => null, 'automation_name' => $rule->name, 'body' => $text]);
        }
    }

    /**
     * The person who triggered the rule does not get a notice about their own action.
     */
    private function notify(Automation $rule, Task $task, ?User $user): void
    {
        if ($user instanceof User && $user->id !== auth()->id()) {
            Notification::send($user, new AutomationNotice($task, $rule->label()));
        }
    }

    /**
     * The task that triggered the rule was loaded before the rule changed it; bring its fields up to date.
     */
    private function carryOver(Task $current, Task $original): void
    {
        foreach (['status_id', 'assignee_id', 'due_date'] as $attribute) {
            $original->setAttribute($attribute, $current->getAttribute($attribute));
            $original->syncOriginalAttribute($attribute);
        }

        foreach (['status', 'assignee', 'tags'] as $relation) {
            if ($original->relationLoaded($relation)) {
                $original->setRelation($relation, $current->load($relation)->getRelation($relation));
            }
        }
    }
}
