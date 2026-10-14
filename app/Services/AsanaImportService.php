<?php

namespace App\Services;

use App\Color;
use App\Enums\CustomFieldType;
use App\Enums\ProjectRole;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Closure;
use finfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes projects over from Asana through its API: the projects with their members, the sections (as statuses or as
 * headings), tasks with all their subtasks, assignees, followers, tags, custom fields, dependencies, comments and
 * attachments. People are matched by email address; links to other Asana tasks become mentions of the imported ones.
 *
 * A task that lies in several Asana projects is created in the first one given only. What has been taken over is
 * remembered (asana_imports), so a second run skips it and adds what is new. Nobody is notified: comments are saved
 * without their events and the tasks do not notify on creation.
 *
 * @phpstan-type Person array{name: ?string, email: ?string}
 * @phpstan-type Node array<string, mixed>
 * @phpstan-type Plan array<string, mixed>
 */
class AsanaImportService
{
    /** What is asked of Asana for a task; dependencies and custom fields come with it, comments and files do not. */
    private const string TASK_FIELDS = 'name,notes,html_notes,completed,completed_at,created_at,modified_at,due_on,due_at,start_on,start_at,resource_subtype,num_subtasks,assignee.gid,assignee.name,assignee.email,created_by.gid,created_by.name,followers.gid,parent.gid,tags.name,dependencies.gid,memberships.project.gid,memberships.section.gid,custom_fields.gid,custom_fields.name,custom_fields.resource_subtype,custom_fields.enum_value.name,custom_fields.multi_enum_values.name,custom_fields.number_value,custom_fields.text_value,custom_fields.date_value,custom_fields.people_value.name';

    private const string PERSON_FIELDS = 'name,email';

    /** Statuses with which Asana says that a feature belongs to a plan the account does not have. */
    private const array NOT_IN_PLAN = [402, 403];

    /** The name of the section Asana puts tasks in that were never given one. */
    private const string DEFAULT_SECTION = 'untitled section';

    /** Asana colors of a project and the swatch of the color picker they come closest to. */
    private const array COLORS = [
        'dark-pink' => '#ec4899', 'dark-green' => '#22c55e', 'dark-blue' => '#3b82f6', 'dark-red' => '#ef4444',
        'dark-teal' => '#14b8a6', 'dark-brown' => '#f97316', 'dark-orange' => '#f97316', 'dark-purple' => '#a855f7',
        'dark-warm-gray' => '#71717a', 'light-pink' => '#ec4899', 'light-green' => '#84cc16', 'light-blue' => '#0ea5e9',
        'light-red' => '#ef4444', 'light-teal' => '#14b8a6', 'light-yellow' => '#f59e0b', 'light-orange' => '#f97316',
        'light-purple' => '#a855f7', 'light-warm-gray' => '#71717a',
    ];

    /** @var array<string, bool|string> */
    private array $options = [];

    private ?Closure $log = null;

    private readonly AsanaTextService $text;

    /** @var array<string, array<string, int>> What has been taken over: kind => Asana id => id here. */
    private array $map = [];

    /** @var array<int, int> Project of every task taken over earlier, by its id. */
    private array $projectOfTask = [];

    /** @var array<string, Person> People Asana has told about, by Asana id. */
    private array $people = [];

    /** @var array<string, int> How often a person is needed (assignee, member), by Asana id. */
    private array $needed = [];

    /** @var array<string, ?User> */
    private array $users = [];

    /** @var ?Collection<int, User> */
    private ?Collection $sprintUsers = null;

    /** @var array<string, true> */
    private array $directories = [];

    /** @var array<string, true> Asana ids of the tasks planned so far, so a task of two projects is planned once. */
    private array $planned = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $counts = [
        'projects' => 0, 'tasks' => 0, 'tasks_new' => 0, 'subtasks' => 0, 'comments_new' => 0, 'attachments_new' => 0,
        'attachment_bytes' => 0, 'dependencies' => 0, 'tasks_elsewhere' => 0, 'comments_unknown_author' => 0,
    ];

    // State of the project being imported
    private Project $project;

    private User $importer;

    /** @var array<string, int> Status id by Asana section id. */
    private array $statusBySection = [];

    private int $defaultStatusId = 0;

    private int $doneStatusId = 0;

    /** @var array<int, CustomField> Field here by Asana field id. */
    private array $fields = [];

    /** @var Collection<string, Tag> */
    private Collection $tags;

    /** @var array<int, int> */
    private array $childPosition = [];

    private int $rootPosition = 0;

    private ?string $lastSection = null;

    /** @var array<int, bool> People who are members of the project here. */
    private array $memberIds = [];

    /** @var list<array{string, int}> Texts to look at once all tasks exist (table, id). */
    private array $linkTexts = [];

    public function __construct(private readonly AsanaClient $asana)
    {
        $this->text = new AsanaTextService;
        $this->tags = collect();
    }

    /**
     * The workspaces the token reaches with their projects (not archived ones), to pick the projects to import from.
     *
     * @return list<array{workspace: string, projects: list<array{gid: string, name: string}>}>
     */
    public function projects(): array
    {
        $found = [];

        foreach ($this->asana->paginate('workspaces', ['opt_fields' => 'name']) as $workspace) {
            $found[] = [
                'workspace' => (string) $workspace['name'],
                'projects' => array_values(array_map(
                    fn (array $project) => ['gid' => (string) $project['gid'], 'name' => (string) $project['name']],
                    iterator_to_array($this->asana->paginate('projects', ['workspace' => $workspace['gid'], 'archived' => 'false', 'opt_fields' => 'name']), false),
                )),
            ];
        }

        return $found;
    }

    /**
     * Reads everything from Asana and, unless `dry_run`, creates it here.
     *
     * Options: `sections` (`status` or `headings`), `create_users`, `ignore_missing_users`, `followers`, `dry_run`.
     *
     * @param  list<array{gid: string, project_id: ?int}>  $targets  Projects in the order of importing; a task of several goes to the first.
     * @param  array<string, bool|string>  $options
     * @return array{counts: array<string, int>, warnings: list<string>, missing: list<array{gid: string, name: string, email: ?string, needed: int}>}
     */
    public function run(array $targets, User $importer, array $options = [], ?Closure $log = null): array
    {
        $this->options = $options + ['sections' => 'status', 'create_users' => false, 'ignore_missing_users' => false, 'followers' => true, 'dry_run' => false];
        $this->log = $log;
        $this->importer = $importer;
        $this->loadMap();

        $plans = array_map(fn (array $target) => $this->plan($target['gid'], $target['project_id']), $targets);
        $missing = $this->missingPeople();

        if (! $this->options['dry_run']) {
            throw_if($missing !== [] && ! $this->options['create_users'] && ! $this->options['ignore_missing_users'], AsanaException::class, 'Some people in Asana have no account here (see the list). Create them, run with --create-users to have them created, or with --ignore-missing-users to leave their assignments out.');

            $this->apply($plans, $missing);
        }

        return ['counts' => $this->counts, 'warnings' => $this->warnings, 'missing' => $missing];
    }

    // ----- Reading from Asana ------------------------------------------------------------------------------------

    /**
     * @return Plan
     */
    private function plan(string $gid, ?int $projectId): array
    {
        $this->say("Reading project {$gid} …");

        $project = $this->asana->get("projects/{$gid}", ['opt_fields' => 'name,notes,color,archived,owner.gid,workspace.gid,members.gid,members.name,members.email']);
        $workspace = (string) ($project['workspace']['gid'] ?? '');

        if ($workspace !== '' && ! isset($this->directories[$workspace])) {
            $this->directories[$workspace] = true;

            foreach ($this->asana->paginate('users', ['workspace' => $workspace, 'opt_fields' => self::PERSON_FIELDS]) as $person) {
                $this->remember($person);
            }
        }

        $members = [];

        foreach ($project['members'] ?? [] as $member) {
            $this->remember($member);
            $this->needed[(string) $member['gid']] = ($this->needed[(string) $member['gid']] ?? 0) + 1;
            $members[] = (string) $member['gid'];
        }

        $projectId ??= $this->knownProject($gid);

        $plan = [
            'gid' => $gid,
            'project_id' => $projectId,
            'name' => (string) ($project['name'] ?? $gid),
            'notes' => (string) ($project['notes'] ?? ''),
            'color' => self::COLORS[(string) ($project['color'] ?? '')] ?? null,
            'archived' => (bool) ($project['archived'] ?? false),
            'owner' => isset($project['owner']['gid']) ? (string) $project['owner']['gid'] : null,
            'members' => $members,
            'sections' => array_values(array_map(
                fn (array $section) => ['gid' => (string) $section['gid'], 'name' => trim((string) ($section['name'] ?? ''))],
                iterator_to_array($this->asana->paginate("projects/{$gid}/sections", ['opt_fields' => 'name']), false),
            )),
            'fields' => $this->planFields($gid),
        ];

        $plan['tasks'] = $this->planTasks("projects/{$gid}/tasks", $gid, $projectId, null);
        $this->counts['projects']++;

        return $plan;
    }

    /**
     * @return list<array{gid: string, name: string, type: string, options: list<string>}>
     */
    private function planFields(string $projectGid): array
    {
        $fields = [];
        $query = ['opt_fields' => 'custom_field.name,custom_field.resource_subtype,custom_field.enum_options.name,custom_field.enum_options.enabled'];

        try {
            foreach ($this->asana->paginate("projects/{$projectGid}/custom_field_settings", $query) as $setting) {
                $field = $setting['custom_field'] ?? [];
                $fields[] = [
                    'gid' => (string) ($field['gid'] ?? ''),
                    'name' => trim((string) ($field['name'] ?? '')),
                    'type' => (string) ($field['resource_subtype'] ?? ''),
                    'options' => array_values(array_map(
                        fn (array $option) => (string) $option['name'],
                        array_filter($field['enum_options'] ?? [], fn (array $option) => $option['enabled'] ?? true),
                    )),
                ];
            }
        } catch (AsanaException $exception) {
            // Custom fields are a paid feature: without them the project simply has none
            throw_unless(in_array($exception->status, self::NOT_IN_PLAN, true), $exception);

            $this->warn("Custom fields are not available with this Asana plan ({$exception->getMessage()}); the project is imported without them.");
        }

        return $fields;
    }

    /**
     * @return list<Node>
     */
    private function planTasks(string $path, string $projectGid, ?int $projectId, ?string $parentGid): array
    {
        $nodes = [];
        $raws = iterator_to_array($this->asana->paginate($path, ['opt_fields' => self::TASK_FIELDS]), false);

        if ($parentGid === null) {
            // A subtask that is a member of the project itself is listed there too; it comes with its parent
            $listed = array_flip(array_map(fn (array $raw) => (string) $raw['gid'], $raws));
            $raws = array_values(array_filter($raws, fn (array $raw) => ! isset($listed[(string) ($raw['parent']['gid'] ?? '')])));
        }

        foreach ($raws as $raw) {
            $gid = (string) $raw['gid'];

            if (isset($this->planned[$gid])) {
                $this->counts['tasks_elsewhere']++;

                continue;
            }

            $this->planned[$gid] = true;
            $knownId = $this->map['task'][$gid] ?? null;
            $node = $this->node($raw, $projectGid);
            $node['id'] = $knownId;

            if ($knownId !== null && ($this->projectOfTask[$knownId] ?? null) !== $projectId) {
                $this->counts['tasks_elsewhere']++;

                continue;
            }

            $this->counts['tasks']++;
            $this->counts['tasks_new'] += $knownId === null ? 1 : 0;
            $this->counts['subtasks'] += $parentGid === null ? 0 : 1;

            $node['comments'] = $this->planComments($gid);
            $node['attachments'] = $this->planAttachments($gid);
            $node['subtasks'] = ((int) ($raw['num_subtasks'] ?? 0)) > 0 ? $this->planTasks("tasks/{$gid}/subtasks", $projectGid, $projectId, $gid) : [];
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return Node
     */
    private function node(array $raw, string $projectGid): array
    {
        foreach (array_merge([$raw['assignee'] ?? null, $raw['created_by'] ?? null], $raw['followers'] ?? []) as $person) {
            if (is_array($person)) {
                $this->remember($person);
            }
        }

        $assignee = isset($raw['assignee']['gid']) ? (string) $raw['assignee']['gid'] : null;

        if ($assignee !== null) {
            $this->needed[$assignee] = ($this->needed[$assignee] ?? 0) + 1;
        }

        $section = null;

        foreach ($raw['memberships'] ?? [] as $membership) {
            if ((string) ($membership['project']['gid'] ?? '') === $projectGid && isset($membership['section']['gid'])) {
                $section = (string) $membership['section']['gid'];
            }
        }

        $values = [];

        foreach ($raw['custom_fields'] ?? [] as $field) {
            $value = $this->fieldText($field);

            if ($value !== null) {
                $values[(string) $field['gid']] = $value;
            }
        }

        $dependencies = array_map(fn (array $dependency) => (string) $dependency['gid'], $raw['dependencies'] ?? []);
        $this->counts['dependencies'] += count($dependencies);

        return [
            'gid' => (string) $raw['gid'],
            'name' => trim((string) ($raw['name'] ?? '')),
            'notes' => (string) ($raw['notes'] ?? ''),
            'html' => $raw['html_notes'] ?? null,
            'completed' => (bool) ($raw['completed'] ?? false),
            'completed_at' => $this->moment($raw['completed_at'] ?? null),
            'created_at' => $this->moment($raw['created_at'] ?? null),
            'modified_at' => $this->moment($raw['modified_at'] ?? null),
            'start' => $this->day($raw['start_on'] ?? null, $raw['start_at'] ?? null),
            'due' => $this->day($raw['due_on'] ?? null, $raw['due_at'] ?? null),
            'milestone' => ($raw['resource_subtype'] ?? '') === 'milestone',
            'assignee' => $assignee,
            'creator' => isset($raw['created_by']['gid']) ? (string) $raw['created_by']['gid'] : null,
            'followers' => array_map(fn (array $person) => (string) $person['gid'], $raw['followers'] ?? []),
            'tags' => array_values(array_filter(array_map(fn (array $tag) => trim((string) ($tag['name'] ?? '')), $raw['tags'] ?? []))),
            'dependencies' => $dependencies,
            'section' => $section,
            'values' => $values,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planComments(string $taskGid): array
    {
        $comments = [];

        foreach ($this->asana->paginate("tasks/{$taskGid}/stories", ['opt_fields' => 'resource_subtype,created_at,created_by.gid,created_by.name,text,html_text']) as $story) {
            if (($story['resource_subtype'] ?? '') !== 'comment_added' || isset($this->map['comment'][(string) $story['gid']])) {
                continue;
            }

            if (isset($story['created_by']['gid'])) {
                $this->remember($story['created_by']);
            }

            $comments[] = [
                'gid' => (string) $story['gid'],
                'author' => isset($story['created_by']['gid']) ? (string) $story['created_by']['gid'] : null,
                'created_at' => $this->moment($story['created_at'] ?? null),
                'html' => $story['html_text'] ?? null,
                'text' => (string) ($story['text'] ?? ''),
            ];
            $this->counts['comments_new']++;
        }

        return $comments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planAttachments(string $taskGid): array
    {
        $attachments = [];

        foreach ($this->asana->paginate('attachments', ['parent' => $taskGid, 'opt_fields' => 'name,host,size,created_at,permanent_url,view_url']) as $attachment) {
            if (isset($this->map['attachment'][(string) $attachment['gid']])) {
                continue;
            }

            $size = (int) ($attachment['size'] ?? 0);
            $stored = ($attachment['host'] ?? 'asana') === 'asana' && $size <= Attachment::MAX_KILOBYTES * 1024;

            $attachments[] = [
                'gid' => (string) $attachment['gid'],
                'name' => trim((string) ($attachment['name'] ?? '')) ?: 'file',
                'created_at' => $this->moment($attachment['created_at'] ?? null),
                'size' => $size,
                'stored' => $stored,
                'url' => $attachment['permanent_url'] ?? $attachment['view_url'] ?? null,
            ];

            if ($stored) {
                $this->counts['attachments_new']++;
                $this->counts['attachment_bytes'] += $size;
            } elseif (($attachment['host'] ?? 'asana') === 'asana') {
                $this->warn('The file “'.($attachment['name'] ?? $attachment['gid']).'” is larger than '.(Attachment::MAX_KILOBYTES / 1024).' MB and only gets a link in the description.');
            }
        }

        return $attachments;
    }

    // ----- People ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $person
     */
    private function remember(array $person): void
    {
        $gid = (string) ($person['gid'] ?? '');

        if ($gid === '') {
            return;
        }

        $known = $this->people[$gid] ?? ['name' => null, 'email' => null];
        $this->people[$gid] = [
            'name' => ($person['name'] ?? null) ?: $known['name'],
            'email' => ($person['email'] ?? null) ?: $known['email'],
        ];
    }

    private function user(string $gid): ?User
    {
        if (array_key_exists($gid, $this->users)) {
            return $this->users[$gid];
        }

        // Deactivated accounts count too: people created by an earlier run stay matched
        $this->sprintUsers ??= User::query()->get();
        $person = $this->people[$gid] ?? ['name' => null, 'email' => null];
        $email = mb_strtolower(trim((string) $person['email']));
        $name = mb_strtolower(trim((string) $person['name']));

        $user = $email !== '' ? $this->sprintUsers->first(fn (User $user) => mb_strtolower($user->email) === $email) : null;

        if ($user === null && $name !== '') {
            $byName = $this->sprintUsers->filter(fn (User $user) => mb_strtolower($user->name) === $name);
            $user = $byName->count() === 1 ? $byName->first() : null;
        }

        return $this->users[$gid] = $user;
    }

    /**
     * People that assignments or memberships need and who have no account here.
     *
     * @return list<array{gid: string, name: string, email: ?string, needed: int}>
     */
    private function missingPeople(): array
    {
        $missing = [];

        foreach ($this->needed as $gid => $needed) {
            if (! $this->user((string) $gid) instanceof User) {
                $person = $this->people[(string) $gid] ?? ['name' => null, 'email' => null];
                $missing[] = ['gid' => (string) $gid, 'name' => $person['name'] ?? (string) $gid, 'email' => $person['email'], 'needed' => $needed];
            }
        }

        usort($missing, fn (array $a, array $b) => $b['needed'] <=> $a['needed']);

        return $missing;
    }

    /**
     * @param  list<array{gid: string, name: string, email: ?string, needed: int}>  $missing
     */
    private function createUsers(array $missing): void
    {
        foreach ($missing as $person) {
            if ($person['email'] === null || User::query()->where('email', $person['email'])->exists()) {
                $this->warn("{$person['name']} has no email address in Asana (or it is taken here), so no account was created.");

                continue;
            }

            User::create([
                'name' => mb_substr($person['name'], 0, 255),
                'email' => $person['email'],
                'password' => Str::password(24, symbols: false),
                'locale' => config('app.locale'),
                'deactivated_at' => now(),
            ]);
            $this->say("Created the account of {$person['name']} ({$person['email']}) as deactivated; an administrator activates it when the person is to sign in.");
        }

        $this->sprintUsers = null;
        $this->users = [];
    }

    // ----- Writing -----------------------------------------------------------------------------------------------

    /**
     * @param  list<Plan>  $plans
     * @param  list<array{gid: string, name: string, email: ?string, needed: int}>  $missing
     */
    private function apply(array $plans, array $missing): void
    {
        Auth::setUser($this->importer);

        if ($missing !== [] && $this->options['create_users']) {
            $this->createUsers($missing);
        }

        app(RealtimeService::class)->bundling(function () use ($plans): void {
            foreach ($plans as $plan) {
                $this->applyProject($plan);
            }

            $this->linkDependencies($plans);
            $this->rewriteLinks();
        });
    }

    /**
     * @param  Plan  $plan
     */
    private function applyProject(array $plan): void
    {
        $this->say("Importing “{$plan['name']}” …");

        $fresh = $plan['project_id'] === null;
        $this->project = $fresh ? $this->createProject($plan) : Project::findOrFail($plan['project_id']);
        $this->remembered('project', $plan['gid'], $this->project->id);
        $this->project->setRole($this->importer, ProjectRole::Admin);
        $this->memberIds = $this->project->members()->pluck('users.id')->mapWithKeys(fn (mixed $id) => [(int) $id => true])->all();
        $this->tags = $this->project->tags()->get()->keyBy(fn (Tag $tag) => mb_strtolower($tag->name));
        $this->childPosition = [];
        $this->rootPosition = $this->project->nextRootPosition();
        $this->lastSection = null;

        foreach ($plan['members'] as $gid) {
            if (($user = $this->user($gid)) instanceof User) {
                $this->ensureMember($user, $gid === $plan['owner'] ? ProjectRole::Admin : ProjectRole::Editor);
            }
        }

        $this->prepareStatuses($plan, $fresh);
        $this->prepareFields($plan);
        $this->importTasks($plan, $plan['tasks'], null);

        if ($plan['archived'] && $fresh) {
            $this->project->update(['archived_at' => now()]);
        }
    }

    /**
     * @param  Plan  $plan
     */
    private function createProject(array $plan): Project
    {
        return Project::create([
            'name' => mb_substr($plan['name'], 0, 255) ?: 'Asana '.$plan['gid'],
            'description' => trim(mb_substr($plan['notes'], 0, 5000)) ?: null,
            'color' => $plan['color'],
        ]);
    }

    private function ensureMember(User $user, ProjectRole $role): void
    {
        if (isset($this->memberIds[$user->id]) || $user->is_admin) {
            return;
        }

        $this->project->setRole($user, $role);
        $this->memberIds[$user->id] = true;
    }

    // Sections become statuses (the default) or headings in the list

    /**
     * @param  Plan  $plan
     */
    private function prepareStatuses(array $plan, bool $fresh): void
    {
        $this->statusBySection = [];
        $sections = array_values(array_filter($plan['sections'], fn (array $section) => ! $this->isDefaultSection($section['name'])));

        if ($this->options['sections'] === 'status') {
            foreach ($sections as $section) {
                $this->statusBySection[$section['gid']] = $this->ensureStatus($section['name'])->id;
            }

            if ($fresh && $sections !== []) {
                $this->tidyStatuses($plan);
            }
        }

        $this->defaultStatusId = $this->project->defaultStatus()->id;
        $this->doneStatusId = $this->project->doneStatus()->id;
    }

    private function isDefaultSection(string $name): bool
    {
        return $name === '' || mb_strtolower($name) === self::DEFAULT_SECTION;
    }

    private function ensureStatus(string $name): TaskStatus
    {
        $name = mb_substr($name, 0, 50);
        $statuses = $this->project->statuses()->get();
        $found = $statuses->first(fn (TaskStatus $status) => mb_strtolower($status->name) === mb_strtolower($name));

        if ($found !== null) {
            return $found;
        }

        $status = $this->project->statuses()->create([
            'name' => $name,
            'color' => Color::next($statuses->count()),
            'position' => TaskStatus::nextPositionIn($this->project->statuses()->reorder()),
            'is_done' => false,
        ]);

        // New statuses go before the done ones
        $order = $statuses->where('is_done', false)->pluck('id')->push($status->id)->merge($statuses->where('is_done', true)->pluck('id'))->all();
        TaskStatus::writePositions($order, $statuses->pluck('position', 'id')->all());

        return $status;
    }

    /**
     * A new project starts with the standard statuses; with sections of its own it keeps only what is used: the first
     * standard one for tasks without a section (and subtasks), the sections in their order, the done ones last.
     *
     * @param  Plan  $plan
     */
    private function tidyStatuses(array $plan): void
    {
        $statuses = $this->project->statuses()->get();
        $open = $statuses->where('is_done', false);
        $sectionIds = array_values(array_unique($this->statusBySection));
        $keepFirst = $this->usesDefaultStatus($plan['tasks']);
        $first = $open->first();
        $keep = array_values(array_unique([...($keepFirst && $first !== null ? [$first->id] : []), ...$sectionIds]));

        $this->project->statuses()->whereIn('id', $open->pluck('id')->diff($keep)->all())->delete();
        TaskStatus::writePositions([...$keep, ...$statuses->where('is_done', true)->pluck('id')->all()], $statuses->pluck('position', 'id')->all());
    }

    /**
     * @param  list<Node>  $nodes
     */
    private function usesDefaultStatus(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node['subtasks'] !== [] || ! isset($this->statusBySection[(string) $node['section']])) {
                return true;
            }
        }

        return false;
    }

    // Custom fields

    /**
     * @param  Plan  $plan
     */
    private function prepareFields(array $plan): void
    {
        $this->fields = [];

        foreach ($plan['fields'] as $setting) {
            $type = match ($setting['type']) {
                'enum' => CustomFieldType::Select,
                'number' => CustomFieldType::Number,
                'date' => CustomFieldType::Date,
                'text', 'multi_enum', 'people' => CustomFieldType::Text,
                default => null,
            };

            if ($type === null || $setting['name'] === '') {
                $this->warn("The field “{$setting['name']}” ({$setting['type']}) cannot be taken over and is left out.");

                continue;
            }

            $field = $this->ensureField($setting['name'], $type);

            foreach ($type === CustomFieldType::Select ? $setting['options'] : [] as $option) {
                $this->ensureOption($field, $option);
            }

            $this->fields[(int) $setting['gid']] = $field;
        }
    }

    private function ensureField(string $name, CustomFieldType $type): CustomField
    {
        $name = mb_substr($name, 0, 100);
        $fields = $this->project->customFields()->with('options')->get();
        $found = $fields->first(fn (CustomField $field) => mb_strtolower($field->name) === mb_strtolower($name) && $field->type === $type);

        if ($found !== null) {
            return $found;
        }

        $taken = $fields->contains(fn (CustomField $field) => mb_strtolower($field->name) === mb_strtolower($name));

        return $this->project->customFields()->create([
            'name' => $taken ? mb_substr($name, 0, 90).' (Asana)' : $name,
            'type' => $type,
            'position' => CustomField::nextPositionIn($this->project->customFields()),
            'show_in_list' => false,
        ])->load('options');
    }

    private function ensureOption(CustomField $field, string $name): void
    {
        $name = mb_substr(trim($name), 0, 100);

        if ($name === '' || $field->options->contains(fn (CustomFieldOption $option) => mb_strtolower($option->name) === mb_strtolower($name))) {
            return;
        }

        $field->options()->create(['name' => $name, 'color' => Color::next($field->options->count()), 'position' => $field->options->count()]);
        $field->load('options');
    }

    /**
     * What a custom field of a task says, as text.
     *
     * @param  array<string, mixed>  $field
     */
    private function fieldText(array $field): ?string
    {
        $value = match ($field['resource_subtype'] ?? '') {
            'enum' => $field['enum_value']['name'] ?? null,
            'multi_enum' => implode(', ', array_column($field['multi_enum_values'] ?? [], 'name')),
            'people' => implode(', ', array_column($field['people_value'] ?? [], 'name')),
            'number' => isset($field['number_value']) ? (string) $field['number_value'] : null,
            'text' => $field['text_value'] ?? null,
            'date' => $field['date_value']['date'] ?? $this->day(null, $field['date_value']['date_time'] ?? null),
            default => null,
        };

        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    // Tasks

    /**
     * @param  Plan  $plan
     * @param  list<Node>  $nodes
     */
    private function importTasks(array $plan, array $nodes, ?Task $parent): void
    {
        foreach ($nodes as $node) {
            if (! $parent instanceof Task && $this->options['sections'] === 'headings') {
                $this->headingFor($plan, $node);
            }

            $task = $node['id'] !== null ? Task::find($node['id']) : $this->createTask($node, $parent);

            if ($task === null) {
                continue;
            }

            $this->importComments($task, $node);
            $this->importAttachments($task, $node);
            $this->importTasks($plan, $node['subtasks'], $task);
        }
    }

    /**
     * In heading mode the first task of a new section is preceded by a heading with its name.
     *
     * @param  Plan  $plan
     * @param  Node  $node
     */
    private function headingFor(array $plan, array $node): void
    {
        $section = $node['section'];

        if ($section === null || $section === $this->lastSection) {
            return;
        }

        $this->lastSection = $section;
        $name = collect($plan['sections'])->firstWhere('gid', $section)['name'] ?? '';

        if ($this->isDefaultSection($name) || isset($this->map['section'][$section])) {
            return;
        }

        $heading = $this->project->tasks()->create([
            'title' => mb_substr($name, 0, 255),
            'is_section' => true,
            'creator_id' => $this->importer->id,
            'position' => $this->rootPosition++,
        ]);
        $this->remembered('section', $section, $heading->id);
    }

    /**
     * @param  Node  $node
     */
    private function createTask(array $node, ?Task $parent): Task
    {
        $assignee = $node['assignee'] !== null ? $this->user($node['assignee']) : null;

        if ($assignee instanceof User) {
            $this->ensureMember($assignee, ProjectRole::Editor);
        }

        $creator = $node['creator'] !== null ? $this->user($node['creator']) : null;
        $position = $parent instanceof Task
            ? $this->childPosition[$parent->id] ??= Task::nextPositionIn($this->project->tasks()->where('parent_id', $parent->id))
            : ($this->rootPosition++);

        if ($parent instanceof Task) {
            $this->childPosition[$parent->id] = $position + 1;
        }

        $description = $this->description($node);

        return DB::transaction(function () use ($node, $parent, $assignee, $creator, $position, $description): Task {
            $task = $this->project->tasks()->make([
                'parent_id' => $parent?->id,
                'title' => mb_substr($node['name'], 0, 255) ?: __('Untitled task'),
                'description' => $description,
                'status_id' => $this->statusFor($node, ! $parent instanceof Task),
                'assignee_id' => $assignee?->id,
                'creator_id' => ($creator ?? $this->importer)->id,
                'start_date' => $node['start'],
                'due_date' => $node['due'],
                'completed_at' => $node['completed'] ? ($node['completed_at'] ?? now()) : null,
                'position' => $position,
            ]);
            $task->forceFill(['created_at' => $node['created_at'] ?? now(), 'updated_at' => $node['modified_at'] ?? $node['created_at'] ?? now()])->save();

            $this->remembered('task', $node['gid'], $task->id);
            $this->projectOfTask[$task->id] = $this->project->id;
            $this->syncTags($task, $node);
            $task->collaborators()->sync($this->followers($node, $assignee));
            $this->storeFieldValues($task, $node);

            if ($this->text->hasTaskLinks((string) $description)) {
                $this->linkTexts[] = ['tasks', $task->id];
            }

            return $task;
        });
    }

    /**
     * @param  Node  $node
     */
    private function description(array $node): ?string
    {
        $description = $this->text->toMarkdown($node['html'], $node['notes'], fn (string $gid) => $this->mentionText($gid));
        $links = [];

        foreach ($node['attachments'] as $attachment) {
            if (! $attachment['stored'] && $attachment['url'] !== null) {
                $links[] = '- ['.str_replace(['[', ']'], '', $attachment['name']).']('.$attachment['url'].')';
            }
        }

        if ($links !== []) {
            $description .= "\n\n---\n\n**".__('Attachments not copied')."**\n\n".implode("\n", $links);
        }

        // Without an account the person cannot be assigned, so the description says who it was
        $assignee = $node['assignee'] !== null && ! $this->user($node['assignee']) instanceof User ? ($this->people[$node['assignee']]['name'] ?? null) : null;

        if ($assignee !== null) {
            $description .= "\n\n---\n\n**".__('Assignee in Asana').':** '.$assignee;
        }

        return trim($description) === '' ? null : trim($description);
    }

    /**
     * @param  Node  $node
     */
    private function statusFor(array $node, bool $topLevel): int
    {
        if ($node['completed']) {
            return $this->doneStatusId;
        }

        return $topLevel && $node['section'] !== null ? ($this->statusBySection[$node['section']] ?? $this->defaultStatusId) : $this->defaultStatusId;
    }

    /**
     * @param  Node  $node
     */
    private function syncTags(Task $task, array $node): void
    {
        $names = $node['tags'];

        if ($node['milestone']) {
            $names[] = __('Milestone');
        }

        $ids = [];

        foreach ($names as $name) {
            $name = mb_substr($name, 0, 50);
            $key = mb_strtolower($name);
            $this->tags[$key] ??= $this->project->tags()->create(['name' => $name, 'color' => Color::next($this->tags->count())]);
            $ids[] = $this->tags[$key]->id;
        }

        $task->tags()->sync(array_unique($ids));
    }

    /**
     * Followers become collaborators, as far as they are members of the project here.
     *
     * @param  Node  $node
     * @return list<int>
     */
    private function followers(array $node, ?User $assignee): array
    {
        if (! $this->options['followers']) {
            return [];
        }

        $ids = [];

        foreach ($node['followers'] as $gid) {
            $user = $this->user($gid);

            if ($user instanceof User && $user->id !== $assignee?->id && (isset($this->memberIds[$user->id]) || $user->is_admin)) {
                $ids[] = $user->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Node  $node
     */
    private function storeFieldValues(Task $task, array $node): void
    {
        foreach ($node['values'] as $gid => $value) {
            $field = $this->fields[(int) $gid] ?? null;

            if ($field === null) {
                continue;
            }

            if ($field->type === CustomFieldType::Select) {
                $this->ensureOption($field, $value);
            }

            $stored = $field->parse($field->type === CustomFieldType::Text ? mb_substr($value, 0, 500) : $value);

            if ($stored === null) {
                $this->warn("“{$task->title}”: the value “{$value}” does not fit the field “{$field->name}”.");

                continue;
            }

            $task->fieldValues()->create(['custom_field_id' => $field->id] + $stored);
        }
    }

    // Comments and attachments

    /**
     * @param  Node  $node
     */
    private function importComments(Task $task, array $node): void
    {
        if ($node['comments'] === []) {
            return;
        }

        foreach ($node['comments'] as $story) {
            $author = $story['author'] !== null ? $this->user($story['author']) : null;
            $body = $this->text->toMarkdown($story['html'], $story['text'], fn (string $gid) => $this->mentionText($gid));

            if ($body === '') {
                continue;
            }

            if (! $author instanceof User) {
                $name = $this->people[$story['author'] ?? '']['name'] ?? __('someone');
                $body = '**'.__('From :name in Asana', ['name' => $name])."**\n\n".$body;
                $this->counts['comments_unknown_author']++;
            }

            $comment = new Comment(['task_id' => $task->id, 'user_id' => ($author ?? $this->importer)->id, 'body' => $body]);
            $comment->forceFill(['created_at' => $story['created_at'] ?? now(), 'updated_at' => $story['created_at'] ?? now()]);
            Comment::withoutEvents(fn () => $comment->save());
            $this->remembered('comment', $story['gid'], $comment->id);

            if ($this->text->hasTaskLinks($body)) {
                $this->linkTexts[] = ['comments', $comment->id];
            }
        }

        app(TaskSearchService::class)->index($task);
    }

    /**
     * @param  Node  $node
     */
    private function importAttachments(Task $task, array $node): void
    {
        foreach ($node['attachments'] as $attachment) {
            if (! $attachment['stored']) {
                continue;
            }

            try {
                $this->storeAttachment($task, $attachment);
            } catch (Throwable $exception) {
                $this->warn("“{$task->title}”: the file “{$attachment['name']}” was not taken over ({$exception->getMessage()}).");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function storeAttachment(Task $task, array $attachment): void
    {
        $details = $this->asana->get('attachments/'.$attachment['gid'], ['opt_fields' => 'download_url']);
        $temporary = (string) tempnam(sys_get_temp_dir(), 'asana');

        try {
            $downloaded = isset($details['download_url']) && $this->asana->download((string) $details['download_url'], $temporary);

            throw_unless($downloaded, AsanaException::class, 'Asana gives no download for it');

            $size = (int) filesize($temporary);

            throw_if($size > Attachment::MAX_KILOBYTES * 1024, AsanaException::class, 'it is larger than '.(Attachment::MAX_KILOBYTES / 1024).' MB');

            $name = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '_', (string) $attachment['name']) ?? 'file', 0, 255);
            $extension = strtolower(substr((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION)), 0, 8));
            $path = "attachments/{$task->project_id}/{$task->id}/".Str::random(40).($extension !== '' ? ".{$extension}" : '');
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: 'application/octet-stream';

            $stream = fopen($temporary, 'rb');
            Storage::disk()->writeStream($path, $stream);
            fclose($stream);
        } finally {
            @unlink($temporary);
        }

        try {
            $record = new Attachment([
                'task_id' => $task->id,
                'user_id' => $this->importer->id,
                'name' => $name,
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => $size,
            ]);
            $record->forceFill(['created_at' => $attachment['created_at'] ?? now(), 'updated_at' => $attachment['created_at'] ?? now()])->save();
        } catch (Throwable $exception) {
            Storage::disk()->delete($path);

            throw $exception;
        }

        $this->remembered('attachment', (string) $attachment['gid'], $record->id);
    }

    // Once everything exists: dependencies and links between tasks

    /**
     * @param  list<Plan>  $plans
     */
    private function linkDependencies(array $plans): void
    {
        $pending = [];

        foreach ($plans as $plan) {
            $pending += $this->dependenciesOf($plan['tasks']);
        }

        foreach ($pending as $taskId => $gids) {
            $ids = [];

            foreach ($gids as $gid) {
                $blocker = $this->map['task'][$gid] ?? null;

                if ($blocker === null) {
                    $this->warn("A task depends on the Asana task {$gid}, which was not imported; the dependency is left out.");
                } elseif ($blocker !== $taskId) {
                    $ids[] = $blocker;
                }
            }

            if ($ids !== []) {
                Task::query()->find($taskId)?->blockers()->syncWithoutDetaching($ids);
            }
        }
    }

    /**
     * The dependencies (Asana ids of the blockers) of all tasks of the plan that exist here, taken over now or earlier.
     *
     * @param  list<Node>  $nodes
     * @return array<int, list<string>> By the id of the task here.
     */
    private function dependenciesOf(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            $taskId = $this->map['task'][$node['gid']] ?? null;

            if ($taskId !== null && $node['dependencies'] !== []) {
                $found[$taskId] = $node['dependencies'];
            }

            $found += $this->dependenciesOf($node['subtasks']);
        }

        return $found;
    }

    /**
     * Links to Asana tasks in the texts that were created become mentions of the imported tasks.
     */
    private function rewriteLinks(): void
    {
        $resolve = function (string $gid): ?array {
            $id = $this->map['task'][$gid] ?? null;
            $title = $id === null ? null : Task::query()->whereKey($id)->value('title');

            return $title === null ? null : ['id' => $id, 'title' => (string) $title];
        };

        foreach ($this->linkTexts as [$table, $id]) {
            $column = $table === 'tasks' ? 'description' : 'body';
            $current = (string) DB::table($table)->where('id', $id)->value($column);
            $linked = $this->text->linkTasks($current, $resolve);

            if ($linked !== $current) {
                DB::table($table)->where('id', $id)->update([$column => $linked]);

                if ($table === 'tasks') {
                    app(TaskSearchService::class)->index($id);
                }
            }
        }
    }

    // ----- Helpers -----------------------------------------------------------------------------------------------

    /**
     * How a mention of an Asana person reads here: a mention of the person, or just the name.
     */
    private function mentionText(string $gid): string
    {
        $user = $this->user($gid);

        if ($user instanceof User) {
            return '@['.str_replace(']', '', $user->name).'](user:'.$user->id.')';
        }

        return '@'.($this->people[$gid]['name'] ?? __('someone'));
    }

    private function loadMap(): void
    {
        $this->map = [];

        foreach (DB::table('asana_imports')->get(['kind', 'gid', 'model_id']) as $row) {
            $this->map[$row->kind][$row->gid] = (int) $row->model_id;
        }

        // Rows whose task or project is gone are forgotten: it would not be created again otherwise
        $this->projectOfTask = Task::query()->whereIn('id', array_values($this->map['task'] ?? []))->pluck('project_id', 'id')->map(fn (mixed $id) => (int) $id)->all();
        $this->map['task'] = array_filter($this->map['task'] ?? [], fn (int $id) => isset($this->projectOfTask[$id]));
        $projects = Project::query()->whereIn('id', array_values($this->map['project'] ?? []))->pluck('id')->all();
        $this->map['project'] = array_filter($this->map['project'] ?? [], fn (int $id) => in_array($id, $projects, true));
    }

    private function knownProject(string $gid): ?int
    {
        return $this->map['project'][$gid] ?? null;
    }

    private function remembered(string $kind, string $gid, int $id): void
    {
        $this->map[$kind][$gid] = $id;

        DB::table('asana_imports')->upsert(
            ['kind' => $kind, 'gid' => $gid, 'model_id' => $id, 'created_at' => now(), 'updated_at' => now()],
            ['kind', 'gid'],
            ['model_id', 'updated_at'],
        );
    }

    private function moment(?string $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * A due or start day: the date Asana gives, or the date of the moment in the application's time zone.
     */
    private function day(?string $date, ?string $moment): ?string
    {
        return $date !== null && $date !== '' ? $date : $this->moment($moment)?->toDateString();
    }

    private function say(string $message): void
    {
        if ($this->log instanceof Closure) {
            ($this->log)($message);
        }
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
