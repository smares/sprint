<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MarkdownService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

/**
 * What the Markdown editor (x-markdown-editor) asks its component for: the people and tasks offered after @
 * and the rendered preview.
 *
 * @property-read Collection<int, User> $users
 */
trait EditsMarkdown
{
    /**
     * The project whose people and tasks can be mentioned.
     */
    abstract protected function mentionProject(): Project;

    /**
     * The people offered after @; tasks are looked up while typing (mentionTasks).
     *
     * @return array{users: list<array{id: int, name: string, note: ?string, initials: string, avatar: ?string, color: string}>, searchTasks: bool}
     */
    #[Computed]
    public function mentionOptions(): array
    {
        return ['users' => $this->mentionPeopleList(), 'searchTasks' => true];
    }

    /**
     * The people offered after @, for an editor that does not have them with the page (x-markdown-editor with mentions-on-demand):
     * it asks when it is focused or the first @ is typed.
     *
     * @return list<array{id: int, name: string, note: ?string, initials: string, avatar: ?string, color: string}>
     */
    #[Renderless]
    public function mentionPeople(): array
    {
        return $this->mentionPeopleList();
    }

    /**
     * @return list<array{id: int, name: string, note: ?string, initials: string, avatar: ?string, color: string}>
     */
    private function mentionPeopleList(): array
    {
        return $this->users->filter(fn (User $user) => $user->isActive())->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'note' => $user->absenceNote(), 'initials' => $user->initials(), 'avatar' => $user->avatarUrl(), 'color' => $user->avatarColor()])->values()->all();
    }

    /**
     * Tasks of the project whose title contains the typed text, newest first.
     *
     * @return list<array{id: int, title: string}>
     */
    #[Renderless]
    public function mentionTasks(string $query): array
    {
        return $this->mentionProject()->mentionableTasks($query);
    }

    /**
     * The task a pasted address leads to, for the editor that turns it into a mention: null when it is another address, the task
     * is gone, or the person may not see it (the address then stays as pasted). Nothing is kept: the title is read anew each time.
     *
     * @return array{id: int, title: string}|null
     */
    #[Renderless]
    public function resolveTaskLink(string $url): ?array
    {
        $id = MarkdownService::taskIdFromUrl(mb_substr($url, 0, 2000));
        $task = $id === null ? null : Task::withoutSections()->with('project')->find($id);

        return $task !== null && Gate::allows('view', $task->project) ? ['id' => $task->id, 'title' => $task->title] : null;
    }

    #[Renderless]
    public function previewMarkdown(string $text): string
    {
        return (string) MarkdownService::render(mb_substr($text, 0, Task::MAX_DESCRIPTION_LENGTH), cache: false);
    }
}
