<?php

namespace App\Services;

use App\Color;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tasks of a project as CSV: a complete export, and an import that understands our own export as well as
 * spreadsheets from other tools (for example Asana) by looking at the column names.
 *
 * @phpstan-type Message array{line: int, message: string}
 * @phpstan-type PlannedTask array{line: int, file_id: string, title: string, description: ?string, status_id: ?int, assignee_id: ?int, collaborator_ids: list<int>, start_date: ?string, due_date: ?string, tag_ids: list<int>, new_tags: list<string>, fields: array<int, array{option_id: ?int, value: ?string}>, parent_ref: string}
 */
class TaskCsvService
{
    public const MAX_ROWS = 2000;

    public const MAX_KILOBYTES = 2048;

    /**
     * Column names that mean the same thing, by the name used in our own export.
     *
     * @var array<string, list<string>>
     */
    private const array ALIASES = [
        'id' => ['id', 'task id', 'nr', 'nummer'],
        'parent_id' => ['parent_id', 'parent id', 'übergeordnete id'],
        'parent' => ['parent', 'parent task', 'übergeordnete aufgabe'],
        'title' => ['title', 'name', 'task name', 'titel', 'aufgabe', 'summary'],
        'description' => ['description', 'notes', 'beschreibung', 'notizen'],
        'status' => ['status', 'section/column', 'section', 'column', 'spalte'],
        'completed' => ['completed', 'completed at', 'erledigt', 'erledigt am'],
        'assignee' => ['assignee', 'assignee email', 'assignee_email', 'zuständig', 'bearbeiter'],
        'collaborators' => ['collaborators', 'beteiligte', 'followers'],
        'start_date' => ['start_date', 'start date', 'start', 'beginn', 'beginnt am'],
        'due_date' => ['due_date', 'due date', 'due', 'fällig', 'fällig am', 'fälligkeit'],
        'tags' => ['tags', 'labels'],
    ];

    /** @var list<string> */
    private const array DATE_FORMATS = ['Y-m-d', 'd.m.Y', 'd.m.y', 'm/d/Y', 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP'];

    /**
     * The whole project, parents before their subtasks, one header row first.
     *
     * @return Generator<int, list<string>>
     */
    public function export(Project $project): Generator
    {
        $fields = $project->customFields()->with('options')->get();

        yield [
            'id', 'parent_id', 'title', 'description', 'status', 'completed', 'assignee', 'collaborators',
            'start_date', 'due_date', 'tags',
            ...$fields->map(fn (CustomField $field) => 'field:'.$field->name)->all(),
            'created_at', 'updated_at', 'url',
        ];

        $tree = $project->tasks()->where('is_section', false)->orderBy('position')->orderBy('id')->get(['id', 'parent_id']);
        $orderedIds = collect(iterator_to_array($this->inTreeOrder($tree->groupBy(fn (Task $task) => $task->parent_id ?? 0), 0), false))->pluck('id')->all();

        foreach ($this->inTreeOrderChunks($project, $orderedIds) as $task) {
            $values = $task->fieldValues->keyBy('custom_field_id');

            yield [
                $task->id,
                $task->parent_id,
                $task->title,
                $task->description,
                $task->status->name,
                $task->isDone() ? 'yes' : '',
                $task->assignee?->email,
                $task->collaborators->pluck('email')->join(', '),
                $task->start_date?->toDateString(),
                $task->due_date?->toDateString(),
                $task->tags->pluck('name')->join(', '),
                ...$fields->map(fn (CustomField $field) => $field->text($values->get($field->id)?->stored()))->all(),
                $task->created_at->toIso8601String(),
                $task->updated_at->toIso8601String(),
                route('tasks.show', $task),
            ];
        }
    }

    /**
     * The tasks in the given order with their relations, loaded a chunk at a time so a large project is not held in memory at once.
     *
     * @param  list<int>  $orderedIds
     * @return Generator<int, Task>
     */
    private function inTreeOrderChunks(Project $project, array $orderedIds): Generator
    {
        foreach (array_chunk($orderedIds, 200) as $chunk) {
            $loaded = $project->tasks()
                ->whereKey($chunk)
                ->with(['status', 'assignee', 'collaborators', 'tags', 'fieldValues'])
                ->get()
                ->keyBy('id');

            foreach ($chunk as $id) {
                yield $loaded->get($id);
            }
        }
    }

    /**
     * @param  SupportCollection<array-key, SupportCollection<array-key, Task>>  $byParent
     * @return Generator<int, Task>
     */
    private function inTreeOrder(SupportCollection $byParent, int $parentId): Generator
    {
        foreach ($byParent->get($parentId, []) as $task) {
            yield $task;

            yield from $this->inTreeOrder($byParent, $task->id);
        }
    }

    /**
     * Cells that a spreadsheet would run as formulas get a leading apostrophe; the import takes it off again.
     */
    public function guard(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * Write the export as CSV text to the handle.
     *
     * @param  resource  $handle
     */
    public function write($handle, Project $project, string $delimiter = ','): void
    {
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($this->export($project) as $row) {
            fputcsv($handle, array_map($this->guard(...), $row), $delimiter, '"', '\\', "\r\n");
        }
    }

    /**
     * Rows of the file with the column names mapped to ours. Unknown columns are kept under their own name.
     *
     * @return array{rows: list<array<string, string>>, columns: list<string>, error: ?string}
     */
    public function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = $this->detectDelimiter((string) strtok($contents, "\n"));
        $header = fgetcsv($handle, null, $delimiter, '"', '\\');

        if ($header === false || $header === [null]) {
            return ['rows' => [], 'columns' => [], 'error' => 'empty'];
        }

        $columns = array_map(fn (?string $name) => $this->canonicalColumn((string) $name), $header);

        if (! in_array('title', $columns, true)) {
            return ['rows' => [], 'columns' => $columns, 'error' => 'no-title'];
        }

        $rows = [];

        while (($cells = fgetcsv($handle, null, $delimiter, '"', '\\')) !== false) {
            if ($cells === [null] || count(array_filter($cells, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $row = [];

            foreach ($columns as $index => $column) {
                $row[$column] ??= $this->unguard(trim($cells[$index] ?? ''));
            }

            $rows[] = $row;
        }

        fclose($handle);

        return ['rows' => $rows, 'columns' => $columns, 'error' => null];
    }

    private function detectDelimiter(string $firstLine): string
    {
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return array_key_first($counts);
    }

    private function canonicalColumn(string $name): string
    {
        $name = trim(mb_strtolower($name));

        if (str_starts_with($name, 'field:')) {
            return 'field:'.trim(mb_substr($name, 6));
        }

        foreach (self::ALIASES as $canonical => $aliases) {
            if (in_array($name, $aliases, true)) {
                return $canonical;
            }
        }

        return 'custom:'.$name;
    }

    private function unguard(string $value): string
    {
        return preg_match("/^'[=+\\-@]/", $value) === 1 ? substr($value, 1) : $value;
    }

    /**
     * Check every row against the project and say what an import would do, without saving anything.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{tasks: list<PlannedTask>, errors: list<Message>, warnings: list<Message>}
     */
    public function plan(Project $project, array $rows): array
    {
        $statuses = $project->statuses()->get();
        $users = $project->eligibleUsers()->get();
        $tags = $project->tags()->get();
        $fields = $project->customFields()->with('options')->get();

        $tasks = [];
        $errors = [];
        $warnings = [];
        $seenIds = [];

        foreach (array_slice($rows, 0, self::MAX_ROWS) as $index => $row) {
            $line = $index + 2;
            $title = trim($row['title'] ?? '');

            if ($title === '' || mb_strlen($title) > 255) {
                $errors[] = ['line' => $line, 'message' => $title === '' ? 'no-title' : 'title-too-long'];

                continue;
            }

            $warn = function (string $message) use (&$warnings, $line): void {
                $warnings[] = ['line' => $line, 'message' => $message];
            };

            $fileId = $row['id'] ?? '';

            if ($fileId !== '' && isset($seenIds[$fileId])) {
                $fileId = '';
            } elseif ($fileId !== '') {
                $seenIds[$fileId] = true;
            }

            $description = $row['description'] ?? '';

            $tasks[] = [
                'line' => $line,
                'file_id' => $fileId,
                'title' => $title,
                'description' => $description === '' ? null : mb_substr($description, 0, 10000),
                'status_id' => $this->planStatus($row, $statuses, $warn),
                ...$this->planPeople($row, $users, $warn),
                ...$this->planDates($row, $warn),
                ...$this->planTags($row, $tags),
                'fields' => $this->planFields($row, $fields, $warn),
                'parent_ref' => ($row['parent_id'] ?? '') !== '' ? 'id:'.$row['parent_id'] : (($row['parent'] ?? '') !== '' ? 'title:'.mb_strtolower($row['parent']) : ''),
            ];
        }

        if (count($rows) > self::MAX_ROWS) {
            $errors[] = ['line' => 0, 'message' => 'too-many-rows'];
        }

        return ['tasks' => $tasks, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * The named status; without one, done or open depending on the "completed" column.
     *
     * @param  Collection<int, TaskStatus>  $statuses
     * @param  Closure(string): void  $warn
     */
    private function planStatus(array $row, Collection $statuses, Closure $warn): ?int
    {
        $name = $row['status'] ?? '';
        $status = $name === '' ? null : $statuses->first(fn (TaskStatus $candidate) => mb_strtolower($candidate->name) === mb_strtolower($name));

        if ($name !== '' && $status === null) {
            $warn('unknown-status:'.$name);
        }

        $completed = ($row['completed'] ?? '') !== '' && ! in_array(mb_strtolower($row['completed']), ['no', 'nein', 'false', '0'], true);

        return $status?->id ?? ($completed ? $statuses->firstWhere('is_done', true)?->id : null) ?? $statuses->firstWhere('is_done', false)?->id;
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Closure(string): void  $warn
     * @return array{assignee_id: ?int, collaborator_ids: list<int>}
     */
    private function planPeople(array $row, Collection $users, Closure $warn): array
    {
        $assigneeId = null;

        if (($row['assignee'] ?? '') !== '') {
            $assigneeId = $this->findUser($users, $row['assignee'])?->id;

            if ($assigneeId === null) {
                $warn('unknown-person:'.$row['assignee']);
            }
        }

        $collaboratorIds = [];

        foreach ($this->split($row['collaborators'] ?? '') as $entry) {
            $user = $this->findUser($users, $entry);

            if (! $user instanceof User) {
                $warn('unknown-person:'.$entry);
            } elseif ($user->id !== $assigneeId) {
                $collaboratorIds[] = $user->id;
            }
        }

        return ['assignee_id' => $assigneeId, 'collaborator_ids' => $collaboratorIds];
    }

    /**
     * @param  Closure(string): void  $warn
     * @return array{start_date: ?string, due_date: ?string}
     */
    private function planDates(array $row, Closure $warn): array
    {
        $dates = ['start_date' => null, 'due_date' => null];

        foreach (array_keys($dates) as $column) {
            if (($row[$column] ?? '') !== '') {
                $dates[$column] = $this->parseDate($row[$column]);

                if ($dates[$column] === null) {
                    $warn('bad-date:'.$row[$column]);
                }
            }
        }

        if ($dates['start_date'] !== null && $dates['due_date'] !== null && $dates['start_date'] > $dates['due_date']) {
            $dates['start_date'] = null;
            $warn('start-after-due');
        }

        return $dates;
    }

    /**
     * Existing tags by name in any case, the rest to be created (up to 50 characters).
     *
     * @param  Collection<int, Tag>  $tags
     * @return array{tag_ids: list<int>, new_tags: list<string>}
     */
    private function planTags(array $row, Collection $tags): array
    {
        $planned = ['tag_ids' => [], 'new_tags' => []];

        foreach ($this->split($row['tags'] ?? '') as $name) {
            $tag = $tags->first(fn (Tag $candidate) => mb_strtolower($candidate->name) === mb_strtolower($name));

            if ($tag !== null) {
                $planned['tag_ids'][] = $tag->id;
            } elseif (mb_strlen($name) <= 50) {
                $planned['new_tags'][] = $name;
            }
        }

        return $planned;
    }

    /**
     * @param  Collection<int, CustomField>  $fields
     * @param  Closure(string): void  $warn
     * @return array<int, array{option_id: ?int, value: ?string}>
     */
    private function planFields(array $row, Collection $fields, Closure $warn): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $row['field:'.mb_strtolower($field->name)] ?? '';

            if ($value === '') {
                continue;
            }

            $stored = $this->fieldValue($field, $value);

            if ($stored === null) {
                $warn('bad-field:'.$field->name.'='.$value);
            } else {
                $values[$field->id] = $stored;
            }
        }

        return $values;
    }

    /**
     * Create the planned tasks, parents before their subtasks as they appear in the file.
     *
     * @param  list<PlannedTask>  $tasks
     * @return int Number of tasks created.
     */
    public function import(Project $project, User $user, array $tasks): int
    {
        return app(RealtimeService::class)->bundling(fn () => DB::transaction(function () use ($project, $user, $tasks): int {
            $createdById = [];
            $createdByTitle = [];
            $tags = $project->tags()->get()->keyBy(fn (Tag $tag) => mb_strtolower($tag->name));
            $rootPosition = $project->nextRootPosition();
            $childNext = [];

            foreach ($tasks as $planned) {
                $parentId = null;

                if ($planned['parent_ref'] !== '') {
                    $parentId = str_starts_with($planned['parent_ref'], 'id:')
                        ? ($createdById[substr($planned['parent_ref'], 3)] ?? null)
                        : ($createdByTitle[substr($planned['parent_ref'], 6)] ?? null);
                }

                if ($parentId === null) {
                    $position = $rootPosition++;
                } else {
                    $childNext[$parentId] ??= Task::nextPositionIn($project->tasks()->where('parent_id', $parentId));
                    $position = $childNext[$parentId]++;
                }

                $task = $project->tasks()->create([
                    'parent_id' => $parentId,
                    'title' => $planned['title'],
                    'description' => $planned['description'],
                    'status_id' => $planned['status_id'],
                    'assignee_id' => $planned['assignee_id'],
                    'creator_id' => $user->id,
                    'start_date' => $planned['start_date'],
                    'due_date' => $planned['due_date'],
                    'position' => $position,
                ]);

                $tagIds = $planned['tag_ids'];

                foreach ($planned['new_tags'] as $name) {
                    $key = mb_strtolower($name);
                    $tags[$key] ??= $project->tags()->create(['name' => $name, 'color' => Color::next($tags->count())]);
                    $tagIds[] = $tags[$key]->id;
                }

                $task->tags()->sync(array_unique($tagIds));
                $task->collaborators()->sync($planned['collaborator_ids']);

                foreach ($planned['fields'] as $fieldId => $stored) {
                    $task->fieldValues()->create(['custom_field_id' => $fieldId] + $stored);
                }

                if ($planned['file_id'] !== '') {
                    $createdById[$planned['file_id']] = $task->id;
                }

                $createdByTitle[mb_strtolower($planned['title'])] ??= $task->id;
            }

            return count($tasks);
        }));
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function findUser(SupportCollection $users, string $value): ?User
    {
        $value = mb_strtolower(trim($value));

        return $users->first(fn (User $user) => mb_strtolower($user->email) === $value)
            ?? $users->first(fn (User $user) => mb_strtolower($user->name) === $value);
    }

    /**
     * @return list<string>
     */
    private function split(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/[,;\n]+/', $value) ?: []), fn (string $part) => $part !== ''));
    }

    private function parseDate(string $value): ?string
    {
        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * @return array{option_id: ?int, value: ?string}|null
     */
    private function fieldValue(CustomField $field, string $value): ?array
    {
        // Spreadsheets bring dates in several formats and long texts; both are tidied up before the field checks them.
        return $field->parse(match ($field->type) {
            CustomFieldType::Date => $this->parseDate($value) ?? '',
            CustomFieldType::Text => Str::limit($value, 500, ''),
            default => $value,
        });
    }
}
