<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\User;
use App\Services\MarkdownService;
use Illuminate\Support\Collection;
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
     * @return array{users: list<array{id: int, name: string}>, searchTasks: bool}
     */
    #[Computed]
    public function mentionOptions(): array
    {
        return [
            'users' => $this->users->filter(fn (User $user) => $user->isActive())->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'searchTasks' => true,
        ];
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

    #[Renderless]
    public function previewMarkdown(string $text): string
    {
        return (string) MarkdownService::render(mb_substr($text, 0, 10000));
    }
}
