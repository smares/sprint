<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskData;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-tasks')]
#[Description('Finds tasks you can see. Without "query" they are listed in project order; with "query" the full-text search over titles, descriptions, comments and attachment names is used. Filter by project, state, assignee. Returns at most 50 tasks per call; use "offset" to continue.')]
#[IsReadOnly]
class ListTasks extends SprintTool
{
    private const PAGE = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Only tasks of this project.'),
            'query' => $schema->string()->description('Words to search for.'),
            'state' => $schema->string()->enum(['open', 'done', 'all'])->description('Open or finished tasks. Default open.'),
            'assignee' => $schema->string()->description('"me" or the e-mail address of the assignee.'),
            'overdue' => $schema->boolean()->description('Only open tasks whose due date has passed.'),
            'include_subtasks' => $schema->boolean()->description('Include subtasks, not only top-level tasks. Default true when searching, false otherwise.'),
            'offset' => $schema->integer()->description('How many results to skip. Default 0.'),
        ];
    }

    protected function perform(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'query' => ['nullable', 'string', 'max:200'],
            'state' => ['nullable', 'in:open,done,all'],
            'assignee' => ['nullable', 'string', 'max:255'],
            'overdue' => ['nullable', 'boolean'],
            'include_subtasks' => ['nullable', 'boolean'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $state = $validated['state'] ?? 'open';
        $search = trim($validated['query'] ?? '');

        if (isset($validated['project_id'])) {
            $this->visibleProject($user, $validated['project_id']);
        }

        if ($search !== '') {
            $tasks = app(TaskSearch::class)->search($user, $search, ['project_id' => $validated['project_id'] ?? null, 'state' => $state]);
        } else {
            $tasks = Task::query()
                ->where('tasks.is_section', false)
                ->whereHas('project', fn ($projects) => $projects->visibleTo($user))
                ->when(isset($validated['project_id']), fn ($query) => $query->where('tasks.project_id', $validated['project_id']))
                ->when(! ($validated['include_subtasks'] ?? false), fn ($query) => $query->whereNull('tasks.parent_id'))
                ->when($state !== 'all', fn ($query) => $query->whereHas('status', fn ($status) => $status->where('is_done', $state === 'done')))
                ->orderBy('tasks.project_id')->orderBy('tasks.position')->orderBy('tasks.id');
        }

        if (! empty($validated['assignee'])) {
            $assigneeId = $validated['assignee'] === 'me'
                ? $user->id
                : User::query()->where('email', $validated['assignee'])->value('id');

            $tasks->where('tasks.assignee_id', $assigneeId ?? 0);
        }

        if (! empty($validated['overdue'])) {
            $tasks->whereDate('tasks.due_date', '<', today())
                ->whereHas('status', fn ($status) => $status->where('is_done', false));
        }

        $page = $tasks->with(['project', 'status', 'assignee', 'tags'])
            ->skip($validated['offset'] ?? 0)->take(self::PAGE + 1)->get();

        return Response::json([
            'tasks' => $page->take(self::PAGE)->map(fn (Task $task) => TaskData::summary($task))->values()->all(),
            'has_more' => $page->count() > self::PAGE,
        ]);
    }
}
