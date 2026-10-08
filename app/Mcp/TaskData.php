<?php

namespace App\Mcp;

use App\Enums\CustomFieldType;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;

/**
 * How tasks look to agents.
 */
class TaskData
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Task $task): array
    {
        $task->loadMissing('project', 'status', 'assignee', 'tags', 'collaborators');

        return [
            'id' => $task->id,
            'title' => $task->title,
            'project' => ['id' => $task->project->id, 'name' => $task->project->name],
            'status' => $task->status->name,
            'done' => $task->isDone(),
            'assignee' => self::person($task->assignee),
            'due_date' => $task->due_date?->toDateString(),
            'start_date' => $task->start_date?->toDateString(),
            'tags' => $task->tags->pluck('name')->all(),
            'parent_id' => $task->parent_id,
            'url' => route('tasks.show', $task),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Task $task): array
    {
        $task->loadMissing('fieldValues.field', 'fieldValues.option', 'comments.user', 'children.status');

        return self::summary($task) + [
            'description' => $task->description,
            'collaborators' => $task->collaborators->map(fn (User $user) => self::person($user))->all(),
            'fields' => $task->fieldValues->mapWithKeys(fn ($value) => [
                $value->field->name => $value->field->type === CustomFieldType::Select ? $value->option?->name : $value->value,
            ])->all(),
            'subtasks' => $task->children->where('is_section', false)->map(fn (Task $child) => [
                'id' => $child->id,
                'title' => $child->title,
                'done' => $child->isDone(),
            ])->values()->all(),
            'comments' => $task->comments->take(-20)->map(fn (Comment $comment) => [
                'author' => $comment->user?->name,
                'at' => $comment->created_at->toIso8601String(),
                'body' => $comment->body,
            ])->values()->all(),
            'created_at' => $task->created_at->toIso8601String(),
            'updated_at' => $task->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    public static function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
