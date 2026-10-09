<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskData;
use App\Mcp\ToolFailure;
use App\Models\Task;
use App\Models\User;
use App\Services\MarkdownService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-task')]
#[Description('Creates a task in a project, or a subtask when "parent_id" is given. The token owner is recorded as creator. Returns the new task.')]
class CreateTask extends WriteTaskTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Id of the project (see list-projects).')->required(),
            'title' => $schema->string()->description('Short title, up to 255 characters.')->required(),
            'parent_id' => $schema->integer()->description('Id of a task in the same project to create this as a subtask of.'),
        ] + $this->taskSchema($schema);
    }

    protected function perform(Request $request, User $user): Response
    {
        $given = $request->validate([
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
        ] + $this->taskRules());

        $project = $this->visibleProject($user, $given['project_id']);
        $this->requireEdit($user, $project);

        $parent = null;

        if (! empty($given['parent_id'])) {
            $parent = $project->tasks()->where('is_section', false)->find($given['parent_id'])
                ?? throw new ToolFailure("Parent task {$given['parent_id']} is not in this project.");
        }

        $task = DB::transaction(function () use ($project, $parent, $given, $user) {
            $task = $project->tasks()->create($this->columns($project, $given) + [
                'parent_id' => $parent?->id,
                'creator_id' => $user->id,
                'position' => $parent === null
                    ? $project->nextRootPosition()
                    : Task::nextPositionIn($project->tasks()->where('parent_id', $parent->id)),
            ]);

            $task->setRelation('project', $project);
            $this->relations($task, $given);

            return $task;
        });

        // As in the "New task" dialog: people mentioned in the description of a new task are told
        $task->notifyMentionedInDescription(MarkdownService::mentionedUserIds($task->description), $user);

        return Response::json(TaskData::detail($task->fresh()));
    }
}
