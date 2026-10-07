<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add-comment')]
#[Description('Adds a comment to a task as the token owner. Mention people with @[Name](user:ID); mentioned people and the task\'s followers are notified.')]
class AddComment extends SprintTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Id of the task.')->required(),
            'body' => $schema->string()->description('Markdown text, up to 5000 characters.')->required(),
        ];
    }

    protected function perform(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $task = $this->visibleTask($user, $validated['task_id']);
        $this->requireEdit($user, $task->project);

        $comment = $task->comments()->create(['user_id' => $user->id, 'body' => $validated['body']]);

        return Response::json(['id' => $comment->id, 'task_id' => $task->id, 'created_at' => $comment->created_at->toIso8601String()]);
    }
}
