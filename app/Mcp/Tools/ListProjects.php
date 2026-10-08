<?php

namespace App\Mcp\Tools;

use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
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

        Project::rememberRolesFor($user, $projects);
        $members = $this->membersByProject($projects);

        return Response::json($projects->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'archived' => $project->archived_at !== null,
            'your_role' => $project->roleFor($user)?->value,
            'statuses' => $project->statuses->map(fn ($status) => ['name' => $status->name, 'is_done' => $status->is_done])->all(),
            'tags' => $project->tags->pluck('name')->sort()->values()->all(),
            'members' => $members[$project->id] ?? [],
            'custom_fields' => $project->customFields->map(fn (CustomField $field) => [
                'name' => $field->name,
                'type' => $field->type->value,
                'options' => $field->options->pluck('name')->all(),
            ])->all(),
        ])->all());
    }

    /**
     * The assignable people (active admins, members and team members) of all projects in three queries.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, list<array{name: string, email: string}>>
     */
    private function membersByProject(Collection $projects): array
    {
        $ids = $projects->modelKeys();
        $admins = User::query()->active()->where('is_admin', true)->get(['name', 'email']);
        $assigned = User::query()->active()
            ->join('project_members', 'project_members.user_id', '=', 'users.id')
            ->whereIn('project_members.project_id', $ids)
            ->get(['users.name', 'users.email', 'project_members.project_id'])
            ->concat(User::query()->active()
                ->join('team_user', 'team_user.user_id', '=', 'users.id')
                ->join('project_team', 'project_team.team_id', '=', 'team_user.team_id')
                ->whereIn('project_team.project_id', $ids)
                ->get(['users.name', 'users.email', 'project_team.project_id']))
            ->groupBy('project_id');

        return $projects->mapWithKeys(fn (Project $project) => [$project->id => $admins
            ->concat($assigned->get($project->id, []))
            ->map(fn (User $user) => ['name' => $user->name, 'email' => $user->email])
            ->unique('email')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all()])->all();
    }
}
