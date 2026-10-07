<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskData;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update-task')]
#[Description('Changes a task. Only the fields you pass are changed; everything else stays. To finish a task set the status to the project\'s done status. Returns the task afterwards.')]
#[IsIdempotent]
class UpdateTask extends WriteTaskTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Id of the task.')->required(),
            'title' => $schema->string()->description('New title, up to 255 characters.'),
        ] + $this->taskSchema($schema);
    }

    protected function perform(Request $request, User $user): Response
    {
        $given = $request->validate([
            'task_id' => ['required', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
        ] + $this->taskRules());

        $task = $this->visibleTask($user, $given['task_id']);
        $this->requireEdit($user, $task->project);

        DB::transaction(function () use ($task, $given) {
            $task->update($this->columns($task->project, $given, $task));
            $this->relations($task, $given);
        });

        return Response::json(TaskData::detail($task->fresh()));
    }
}
