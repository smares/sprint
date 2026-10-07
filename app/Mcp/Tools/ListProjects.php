<?php

namespace App\Mcp\Tools;

use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-projects')]
#[Description('Lists the projects you can access, each with your role, its statuses, tags, members (assignable people) and custom fields. Call this first to learn the ids and names the other tools need.')]
#[IsReadOnly]
class ListProjects extends SprintTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()->description('Also list archived projects (read-only). Default false.'),
        ];
    }

    protected function perform(Request $request, User $user): Response
    {
        $projects = Project::query()
            ->visibleTo($user)
            ->when(! $request->get('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->with(['statuses', 'tags', 'customFields.options'])
            ->orderBy('name')
            ->get();

        return Response::json($projects->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'archived' => $project->archived_at !== null,
            'your_role' => $project->roleFor($user)?->value,
            'statuses' => $project->statuses->map(fn ($status) => ['name' => $status->name, 'is_done' => $status->is_done])->all(),
            'tags' => $project->tags->pluck('name')->sort()->values()->all(),
            'members' => $project->eligibleUsers()->orderBy('name')->get(['name', 'email'])->map->only(['name', 'email'])->all(),
            'custom_fields' => $project->customFields->map(fn (CustomField $field) => [
                'name' => $field->name,
                'type' => $field->type->value,
                'options' => $field->options->pluck('name')->all(),
            ])->all(),
        ])->all());
    }
}
