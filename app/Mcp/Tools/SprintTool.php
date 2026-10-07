<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolFailure;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared rules of all tools: they act as the token's owner and respect the same permissions as the app.
 */
abstract class SprintTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Not authenticated.');
        }

        try {
            return $this->perform($request, $user);
        } catch (ToolFailure $failure) {
            return Response::error($failure->getMessage());
        }
    }

    abstract protected function perform(Request $request, User $user): Response;

    protected function visibleProject(User $user, int $projectId): Project
    {
        return Project::query()->visibleTo($user)->find($projectId)
            ?? throw new ToolFailure("Project {$projectId} not found.");
    }

    /**
     * A task of a visible project; headings are no tasks.
     */
    protected function visibleTask(User $user, int $taskId): Task
    {
        return Task::query()
            ->where('is_section', false)
            ->whereHas('project', fn ($projects) => $projects->visibleTo($user))
            ->find($taskId) ?? throw new ToolFailure("Task {$taskId} not found.");
    }

    /**
     * Tokens may be read-only, and archived projects or viewers cannot be changed.
     */
    protected function requireEdit(User $user, Project $project): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null && ! $user->tokenCan('write')) {
            throw new ToolFailure('This token is read-only.');
        }

        if (! Gate::forUser($user)->allows('edit', $project)) {
            throw new ToolFailure($project->archived_at !== null
                ? 'This project is archived and cannot be changed.'
                : 'You may not change tasks in this project.');
        }
    }
}
