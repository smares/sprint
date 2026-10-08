<?php

namespace App\Mcp\Tools;

use App\Enums\CustomFieldType;
use App\Mcp\ToolFailure;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;

/**
 * What creating and changing a task have in common: the same fields, resolved by name like a person would type them.
 */
abstract class WriteTaskTool extends SprintTool
{
    /**
     * @return array<string, mixed>
     */
    protected function taskSchema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->description('Markdown text. Mention people with @[Name](user:ID). Empty string clears it.'),
            'status' => $schema->string()->description('Name of a status of the project, e.g. "Open" or "Done".'),
            'assignee' => $schema->string()->description('E-mail address of a project member. Empty string unassigns.'),
            'due_date' => $schema->string()->description('Date as YYYY-MM-DD. Empty string clears it.'),
            'start_date' => $schema->string()->description('Date as YYYY-MM-DD. Must not be after the due date. Empty string clears it.'),
            'tags' => $schema->array()->items($schema->string())->description('Names of existing tags of the project. Replaces the current tags.'),
            'collaborators' => $schema->array()->items($schema->string())->description('E-mail addresses of project members who follow the task. Replaces the current ones.'),
            'fields' => $schema->object()->description('Custom field values by field name, e.g. {"Priority": "High"}. Select fields take the option name. Empty string clears a value.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskRules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', 'string', 'max:255'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:255'],
            'collaborators' => ['nullable', 'array', 'max:50'],
            'collaborators.*' => ['string', 'max:255'],
            'fields' => ['nullable', 'array', 'max:50'],
        ];
    }

    /**
     * The columns the caller asked to set; what is not mentioned stays as it is.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>
     */
    protected function columns(Project $project, array $given, ?Task $task = null): array
    {
        $columns = [];

        if (array_key_exists('title', $given)) {
            $columns['title'] = trim($given['title']);
        }

        if (array_key_exists('description', $given)) {
            $columns['description'] = ($given['description'] ?? '') === '' ? null : $given['description'];
        }

        if (! empty($given['status'])) {
            $columns['status_id'] = $project->statuses->first(fn ($status) => mb_strtolower($status->name) === mb_strtolower($given['status']))?->id
                ?? throw new ToolFailure("Unknown status \"{$given['status']}\". Available: ".$project->statuses->pluck('name')->implode(', ').'.');
        }

        if (array_key_exists('assignee', $given)) {
            $columns['assignee_id'] = ($given['assignee'] ?? '') === '' ? null : $this->member($project, $given['assignee'])->id;
        }

        foreach (['due_date', 'start_date'] as $date) {
            if (array_key_exists($date, $given)) {
                $columns[$date] = ($given[$date] ?? '') === '' ? null : $given[$date];
            }
        }

        $start = array_key_exists('start_date', $columns) ? $columns['start_date'] : $task?->start_date?->toDateString();
        $due = array_key_exists('due_date', $columns) ? $columns['due_date'] : $task?->due_date?->toDateString();

        throw_if($start !== null && $due !== null && $start > $due, ToolFailure::class, 'The start date must not be after the due date.');

        return $columns;
    }

    protected function member(Project $project, string $email): User
    {
        return $project->eligibleUsers()->where('email', $email)->first()
            ?? throw new ToolFailure("\"{$email}\" is not an active member of this project. Use an e-mail address from list-projects.");
    }

    /**
     * Tags, collaborators and field values, after the task itself was saved.
     *
     * @param  array<string, mixed>  $given
     */
    protected function relations(Task $task, array $given): void
    {
        if (array_key_exists('tags', $given)) {
            $ids = collect($given['tags'] ?? [])->map(fn (string $name) => $task->project->tags->first(fn ($tag) => mb_strtolower($tag->name) === mb_strtolower($name))?->id
                ?? throw new ToolFailure("Unknown tag \"{$name}\". Available: ".$task->project->tags->pluck('name')->implode(', ').'.'))->unique()->all();

            $this->logSync($task, 'tags', $task->tags()->sync($ids), 'tags', 'name');
        }

        if (array_key_exists('collaborators', $given)) {
            $ids = collect($given['collaborators'] ?? [])->map(fn (string $email) => $this->member($task->project, $email)->id)
                ->reject(fn (int $id) => $id === $task->assignee_id)->unique()->all();

            $this->logSync($task, 'collaborators', $task->collaborators()->sync($ids), 'users', 'name');
        }

        if (array_key_exists('fields', $given)) {
            $this->fieldValues($task, $given['fields'] ?? []);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fieldValues(Task $task, array $values): void
    {
        $fields = $task->project->customFields()->with('options')->get();

        foreach ($values as $name => $value) {
            /** @var CustomField|null $field */
            $field = $fields->first(fn (CustomField $field) => mb_strtolower($field->name) === mb_strtolower((string) $name))
                ?? throw new ToolFailure("Unknown field \"{$name}\". Available: ".$fields->pluck('name')->implode(', ').'.');

            $value = trim((string) ($value ?? ''));

            if ($value === '') {
                $task->fieldValues()->where('custom_field_id', $field->id)->delete();

                continue;
            }

            $attributes = match ($field->type) {
                CustomFieldType::Select => ['option_id' => $field->options->first(fn ($option) => mb_strtolower($option->name) === mb_strtolower($value))?->id
                    ?? throw new ToolFailure("Unknown option \"{$value}\" for \"{$field->name}\". Available: ".$field->options->pluck('name')->implode(', ').'.'), 'value' => null],
                CustomFieldType::Number => ['option_id' => null, 'value' => is_numeric($value) ? $value : throw new ToolFailure("\"{$field->name}\" needs a number.")],
                CustomFieldType::Date => ['option_id' => null, 'value' => strtotime($value) !== false ? $value : throw new ToolFailure("\"{$field->name}\" needs a date as YYYY-MM-DD.")],
                CustomFieldType::Text => ['option_id' => null, 'value' => mb_strlen($value) <= 500 ? $value : throw new ToolFailure("\"{$field->name}\" is limited to 500 characters.")],
            };

            $task->fieldValues()->updateOrCreate(['custom_field_id' => $field->id], $attributes);
        }
    }

    /**
     * @param  array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}  $changes
     */
    private function logSync(Task $task, string $kind, array $changes, string $table, string $nameColumn): void
    {
        foreach (['attached' => 'added', 'detached' => 'removed'] as $key => $suffix) {
            if ($changes[$key] !== []) {
                $task->logActivity("{$kind}_{$suffix}", [
                    'names' => DB::table($table)->whereIn('id', $changes[$key])->orderBy($nameColumn)->pluck($nameColumn)->all(),
                ]);
            }
        }
    }
}
