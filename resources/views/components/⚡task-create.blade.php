<?php

use App\Concerns\EditsMarkdown;
use App\Enums\ActivityType;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Services\MarkdownService;
use App\Services\TaskSearchService;
use App\Services\RealtimeService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The "New task" dialog of a project, the same in list, board, calendar and timeline. Opened with
 * $flux.modal('create-task').show() or, with a status or due date already chosen, by dispatching
 * `new-task` with statusId / dueDate.
 */
new class extends Component
{
    use EditsMarkdown {
        previewMarkdown as private renderMarkdownPreview;
    }
    use WithFileUploads;

    /** How many images a text can carry before the task exists (the images wait in Livewire's temporary uploads). */
    private const int MAX_DRAFT_IMAGES = 20;

    /**
     * The numbers the editor puts into `attachment:` before the task exists. They are far above any real attachment, so the
     * preview never shows somebody else's image under them; create() replaces them with the real ids.
     */
    private const int DRAFT_ID_BASE = 9_000_000_000;

    /** How many files the dialog takes, like the attachments of a task. */
    private const int MAX_UPLOADS = 20;

    public Project $project;

    /**
     * The files chosen for the new task, kept as temporary uploads until it is created (then they are its attachments).
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $uploads = [];

    /** The image just pasted, dropped or chosen (see storeInlineImage). */
    public $inlineUpload = null;

    /**
     * Images of the text, by their draft number, until the task is created.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    #[Locked]
    public array $draftImages = [];

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
        return $this->project->assignableUsers();
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

    /**
     * The same limits as the attachments of a task: 20 files at a time, each up to the size limit.
     */
    public function updatedUploads(): void
    {
        $this->validate(['uploads' => ['array', 'max:'.self::MAX_UPLOADS], 'uploads.*' => ['file', 'max:'.Attachment::MAX_KILOBYTES]], [], ['uploads.*' => __('File')]);
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
        $this->resetErrorBag(['uploads', 'uploads.*']);
    }

    /**
     * Keeps the image pasted or dropped into the description until the task exists (it cannot be attached before) and returns
     * what the text needs to refer to it.
     *
     * @return array{id: int, name: string}|null
     */
    public function storeInlineImage(): ?array
    {
        Gate::authorize('edit', $this->project);

        $this->validate(['inlineUpload' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:'.Attachment::MAX_KILOBYTES]], [], ['inlineUpload' => __('File')]);

        if (count($this->draftImages) >= self::MAX_DRAFT_IMAGES) {
            $this->reset('inlineUpload');
            $this->addError('inlineUpload', __('A text can carry up to :count images.', ['count' => self::MAX_DRAFT_IMAGES]));

            return null;
        }

        $id = self::DRAFT_ID_BASE + count($this->draftImages) + 1;
        $this->draftImages[$id] = $this->inlineUpload;
        $name = $this->inlineUpload->getClientOriginalName();
        $this->reset('inlineUpload');

        return ['id' => $id, 'name' => $name];
    }

    /**
     * The preview of the text: an image that waits for the task (its draft number) is announced instead of shown as removed.
     */
    #[Renderless]
    public function previewMarkdown(string $text): string
    {
        $text = preg_replace_callback(
            '/!\[([^\]\n]{0,255})\]\(attachment:(\d{10,})\)/u',
            fn (array $match) => '_'.__('Image “:name” is attached when the task is created.', ['name' => str_replace(['_', '*', '`'], '', $match[1])]).'_',
            $text,
        ) ?? $text;

        return $this->renderMarkdownPreview($text);
    }

    /**
     * Attaches what was chosen for the task: the files, and the images the description still refers to (their draft numbers in
     * the text become the real ids; the others are dropped).
     *
     * @param  list<string>  $storedPaths  filled with the paths of the files stored, so a failure can remove them again
     */
    private function attachFiles(Task $task, array &$storedPaths): void
    {
        $names = [];

        foreach ($this->uploads as $file) {
            $attachment = $task->attachUpload($file);
            $storedPaths[] = $attachment->path;
            $names[] = $attachment->name;
        }

        $description = (string) $task->description;
        $textChanged = false;

        foreach ($this->draftImages as $id => $file) {
            if (! str_contains($description, "](attachment:{$id})")) {
                continue;
            }

            $attachment = $task->attachUpload($file);
            $storedPaths[] = $attachment->path;
            $description = str_replace("](attachment:{$id})", "](attachment:{$attachment->id})", $description);
            $names[] = $attachment->name;
            $textChanged = true;
        }

        if ($names === []) {
            return;
        }

        if ($textChanged) {
            // The text only changes in the numbers of the images: no entry in the history, no new mentions
            $task->forceFill(['description' => $description])->saveQuietly();
            app(TaskSearchService::class)->index($task);
            app(RealtimeService::class)->taskChanged($task->project_id, $task->id, 'task');
        }

        $task->logActivity(ActivityType::AttachmentsAdded, ['names' => $names]);
    }

    public function create(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:'.Task::MAX_DESCRIPTION_LENGTH],
            'statusId' => ['required', Rule::in($this->statuses->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'assigneeId' => ['nullable', Rule::in($this->users->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
            'startDate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:dueDate'],
            'uploads' => ['array', 'max:'.self::MAX_UPLOADS],
            'uploads.*' => ['file', 'max:'.Attachment::MAX_KILOBYTES],
        ], attributes: ['title' => __('Title'), 'description' => __('Description'), 'statusId' => __('Status'), 'assigneeId' => __('Assignee'), 'dueDate' => __('Due on'), 'startDate' => __('Starts on'), 'uploads.*' => __('File')]);

        // The task and its attachments are one step: if a file cannot be stored, there is no task (and no file left over) to be created twice
        $storedPaths = [];

        try {
            $task = DB::transaction(function () use ($validated, &$storedPaths): Task {
                $task = $this->project->createRootTask([
                    'title' => trim($validated['title']),
                    'description' => $validated['description'] ?: null,
                    'status_id' => (int) $validated['statusId'],
                    'assignee_id' => $validated['assigneeId'] ?: null,
                    'due_date' => $validated['dueDate'] ?: null,
                    'start_date' => $validated['startDate'] ?: null,
                ]);

                $this->attachFiles($task, $storedPaths);

                return $task;
            });
        } catch (\Throwable $exception) {
            Storage::delete($storedPaths);

            throw $exception;
        }

        $task->notifyMentionedInDescription(MarkdownService::mentionedUserIds($task->description), auth()->user());

        $this->reset('title', 'description', 'assigneeId', 'dueDate', 'startDate', 'draftImages', 'uploads');
        $this->statusId = (string) $this->project->defaultStatus()->id;
        Flux::modal('create-task')->close();
        $this->dispatch('task-created', taskId: $task->id);
    }
};
?>

<div>
    <flux:modal name="create-task" class="w-full max-w-lg">
        {{-- After a validation error the keyboard moves to the first field that is wrong (the submit button, disabled while saving, lost it) --}}
        <form x-on:submit.prevent="$wire.create().then(() => $nextTick(() => $el.querySelector('input[data-invalid], textarea[data-invalid], button[data-invalid], [data-invalid] input, [data-invalid] button')?.focus()))" class="space-y-6">
            <flux:heading size="lg">{{ __('New task') }}</flux:heading>
            <flux:input wire:model="title" :label="__('Title')" autofocus />
            <x-markdown-editor wire:model="description" :label="__('Description')" grow :max="Task::MAX_DESCRIPTION_LENGTH" :placeholder="__('Describe the task … (Markdown, @ for mentions)')" :rows="3" :images="[]" :mentions="$this->mentionOptions" />
            <flux:error name="inlineUpload" />
            <div class="space-y-2">
                <flux:label>{{ __('Attachments') }}</flux:label>
                <flux:file-upload wire:model="uploads" multiple>
                    <flux:file-upload.dropzone :heading="__('Drag files here or click')" :text="__('Up to :size MB per file', ['size' => intdiv(\App\Models\Attachment::MAX_KILOBYTES, 1024)])" with-progress inline />
                </flux:file-upload>
                <flux:error name="uploads" />
                @if ($uploads !== [])
                    <div class="space-y-2">
                        @foreach ($uploads as $index => $file)
                            <flux:file-item wire:key="upload-{{ $index }}-{{ $file->getFilename() }}" :heading="$file->getClientOriginalName()" :text="\App\Models\Attachment::formatSize($file->getSize())">
                                <x-slot name="actions">
                                    <flux:button size="sm" variant="ghost" icon="x-mark" inset wire:click="removeUpload({{ $index }})" :aria-label="__('Remove')" :tooltip="__('Remove')" />
                                </x-slot>
                            </flux:file-item>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <flux:select variant="listbox" wire:model="statusId" :label="__('Status')">
                    @foreach ($this->statuses as $status)
                        <flux:select.option value="{{ $status->id }}">{{ $status->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select variant="listbox" wire:model="assigneeId" :label="__('Assignee')">
                    <flux:select.option value="">{{ __('Nobody') }}</flux:select.option>
                    @foreach ($this->users as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->labelledName() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:date-picker wire:model="startDate" :label="__('Starts on')" locale="{{ app()->getLocale() }}" :placeholder="__('Select a date')" clearable />
                <flux:date-picker wire:model="dueDate" :label="__('Due on')" locale="{{ app()->getLocale() }}" :placeholder="__('Select a date')" clearable />
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
