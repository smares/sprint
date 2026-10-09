<?php

use App\Concerns\EditsMarkdown;
use App\Models\Project;
use App\Services\MarkdownService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "New task" dialog of a project, the same in list, board, calendar and timeline. Opened with
 * $flux.modal('create-task').show() or, with a status or due date already chosen, by dispatching
 * `new-task` with statusId / dueDate.
 */
new class extends Component
{
    use EditsMarkdown;

    public Project $project;

    public string $title = '';

    public string $description = '';

    public string $statusId = '';

    public string $assigneeId = '';

    public string $startDate = '';

    public string $dueDate = '';

    public function mount(): void
    {
        Gate::authorize('edit', $this->project);

        $this->statusId = (string) $this->project->defaultStatus()->id;
    }

    public function hydrate(): void
    {
        Gate::authorize('edit', $this->project);
    }

    #[Computed]
    public function statuses(): Collection
    {
        return $this->project->statuses()->get();
    }

    #[Computed]
    public function users(): Collection
    {
        return $this->project->eligibleUsers()->orderBy('name')->get(['id', 'name']);
    }

    protected function mentionProject(): Project
    {
        return $this->project;
    }

    #[On('new-task')]
    public function open(?int $statusId = null, ?string $dueDate = null): void
    {
        $this->resetErrorBag();
        $this->statusId = (string) ($statusId !== null && $this->statuses->contains('id', $statusId) ? $statusId : $this->project->defaultStatus()->id);
        $this->dueDate = $dueDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) ? $dueDate : '';

        Flux::modal('create-task')->show();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'statusId' => ['required', Rule::in($this->statuses->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'assigneeId' => ['nullable', Rule::in($this->users->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
            'startDate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:dueDate'],
        ], attributes: ['title' => __('Title'), 'statusId' => __('Status'), 'dueDate' => __('Due on'), 'startDate' => __('Starts on')]);

        $task = $this->project->createRootTask([
            'title' => trim($validated['title']),
            'description' => $validated['description'] ?: null,
            'status_id' => (int) $validated['statusId'],
            'assignee_id' => $validated['assigneeId'] ?: null,
            'due_date' => $validated['dueDate'] ?: null,
            'start_date' => $validated['startDate'] ?: null,
        ]);

        $task->notifyMentionedInDescription(MarkdownService::mentionedUserIds($task->description), auth()->user());

        $this->reset('title', 'description', 'assigneeId', 'dueDate', 'startDate');
        $this->statusId = (string) $this->project->defaultStatus()->id;
        Flux::modal('create-task')->close();
        $this->dispatch('task-created');
    }
};
?>

<div>
    <flux:modal name="create-task" class="md:w-[34rem]">
        <form wire:submit="create" class="space-y-6">
            <flux:heading size="lg">{{ __('New task') }}</flux:heading>
            <flux:input wire:model="title" :label="__('Title')" autofocus />
            <x-markdown-editor wire:model="description" :label="__('Description')" :placeholder="__('Describe the task … (Markdown, @ for mentions)')" :rows="3" :mentions="$this->mentionOptions" />
            <div class="grid grid-cols-2 gap-4">
                <flux:select variant="listbox" wire:model="statusId" :label="__('Status')">
                    @foreach ($this->statuses as $status)
                        <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select variant="listbox" wire:model="assigneeId" :label="__('Assignee')">
                    <flux:select.option value="">{{ __('Nobody') }}</flux:select.option>
                    @foreach ($this->users as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <flux:date-picker wire:model="startDate" :label="__('Starts on')" locale="{{ app()->getLocale() }}" :placeholder="__('Select a date')" clearable />
                <flux:date-picker wire:model="dueDate" :label="__('Due on')" locale="{{ app()->getLocale() }}" :placeholder="__('Select a date')" clearable />
            </div>
            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
