<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskData;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-task')]
#[Description('Returns one task with description, collaborators, custom field values, subtasks and the latest 20 comments.')]
#[IsReadOnly]
class GetTask extends SprintTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Id of the task.')->required(),
        ];
    }

    protected function perform(Request $request, User $user): Response
    {
        $validated = $request->validate(['task_id' => ['required', 'integer']]);

        return Response::json(TaskData::detail($this->visibleTask($user, $validated['task_id'])));
    }
}
