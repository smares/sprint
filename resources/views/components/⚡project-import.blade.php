<?php

use App\Models\Project;
use App\Services\TaskCsvService;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?TemporaryUploadedFile $file = null;

    /** @var array{count: int, errors: list<array{line: int, message: string}>, warnings: list<array{line: int, message: string}>, error: ?string}|null */
    public ?array $report = null;

    public ?int $imported = null;

    public function hydrate(): void
    {
        Gate::authorize('edit', $this->project);
    }

    public function mount(): void
    {
        Gate::authorize('edit', $this->project);
    }

    /**
     * @return array{plan: array<string, mixed>|null, error: ?string}
     */
    private function planFromFile(): array
    {
        $csv = app(TaskCsvService::class);
        $parsed = $csv->parse((string) file_get_contents($this->file->getRealPath()));

        if ($parsed['error'] !== null) {
            return ['plan' => null, 'error' => $parsed['error']];
        }

        return ['plan' => $csv->plan($this->project, $parsed['rows']), 'error' => null];
    }

    public function updatedFile(): void
    {
        Gate::authorize('edit', $this->project);

        $this->imported = null;
        $this->validate(['file' => ['required', 'file', 'max:'.TaskCsvService::MAX_KILOBYTES]], [], ['file' => __('File')]);

        ['plan' => $plan, 'error' => $error] = $this->planFromFile();

        $this->report = [
            'count' => count($plan['tasks'] ?? []),
            'errors' => $plan['errors'] ?? [],
            'warnings' => $plan['warnings'] ?? [],
            'error' => $error,
        ];
    }

    public function import(): void
    {
        Gate::authorize('edit', $this->project);
        abort_if($this->file === null, 422);

        ['plan' => $plan] = $this->planFromFile();
        abort_if($plan === null || $plan['tasks'] === [], 422);

        $this->imported = app(TaskCsvService::class)->import($this->project, auth()->user(), $plan['tasks']);
        $this->reset('file', 'report');
    }

    public function startOver(): void
    {
        $this->reset('file', 'report', 'imported');
        $this->resetErrorBag();
    }

    /**
     * @param  array{line: int, message: string}  $entry
     */
    public function describe(array $entry): string
    {
        [$code, $detail] = array_pad(explode(':', $entry['message'], 2), 2, '');

        $text = match ($code) {
            'no-title' => __('No title'),
            'title-too-long' => __('Title longer than 255 characters'),
            'too-many-rows' => __('More than :max rows, the rest is not imported', ['max' => TaskCsvService::MAX_ROWS]),
            'unknown-status' => __('Status “:value” does not exist, the first open one applies', ['value' => $detail]),
            'unknown-person' => __('“:value” is not an active member of the project and is ignored', ['value' => $detail]),
            'bad-date' => __('Date “:value” is not readable and is ignored', ['value' => $detail]),
            'start-after-due' => __('Start is after the due date and is ignored'),
            'bad-field' => __('Field value “:value” does not fit and is ignored', ['value' => $detail]),
            default => $entry['message'],
        };

        return $entry['line'] > 0 ? __('Row :line: :text', ['line' => $entry['line'], 'text' => $text]) : $text;
    }
};
?>

<flux:modal name="project-import" class="w-full max-w-lg" x-on:close="$wire.startOver()">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Import tasks from CSV') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Upload a CSV file, for example an export from Sprint or Asana. Each row becomes a new task; existing tasks are not changed.') }}</flux:text>
        </div>

        @if ($imported !== null)
            <flux:callout variant="success" icon="check-circle" :heading="trans_choice('{1} One task imported|[0,*] :count tasks imported', $imported)" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="startOver">{{ __('Another file') }}</flux:button>
                <flux:modal.close><flux:button variant="primary" x-on:click="$dispatch('tasks-imported')">{{ __('Finish') }}</flux:button></flux:modal.close>
            </div>
        @else
            <flux:file-upload wire:model="file" :label="__('CSV file')">
                <flux:file-upload.dropzone :heading="__('Drag a file here or click')" text="{{ __('CSV up to :size MB, at most :rows rows', ['size' => intdiv(TaskCsvService::MAX_KILOBYTES, 1024), 'rows' => TaskCsvService::MAX_ROWS]) }}" inline />
            </flux:file-upload>
            <flux:error name="file" />

            @if ($report)
                @if ($report['error'] === 'no-title')
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('No title column found')" :text="__('The first row needs a column “title” (or “Name”, “Titel”, “Aufgabe”).')" />
                @elseif ($report['error'] === 'empty')
                    <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('The file is empty')" />
                @else
                    <flux:callout :variant="$report['count'] > 0 ? 'success' : 'warning'" icon="document-text" :heading="trans_choice('{1} One task ready to import|[0,*] :count tasks ready to import', $report['count'])" />

                    @if ($report['errors'])
                        <div>
                            <flux:heading size="sm">{{ __('These rows are skipped (:count)', ['count' => count($report['errors'])]) }}</flux:heading>
                            <ul class="mt-1 list-inside list-disc text-sm text-red-600 dark:text-red-400">
                                @foreach (array_slice($report['errors'], 0, 8) as $entry)
                                    <li>{{ $this->describe($entry) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($report['warnings'])
                        <div>
                            <flux:heading size="sm">{{ __('Notes (:count)', ['count' => count($report['warnings'])]) }}</flux:heading>
                            <ul class="mt-1 list-inside list-disc text-sm text-amber-700 dark:text-amber-400">
                                @foreach (array_slice($report['warnings'], 0, 8) as $entry)
                                    <li>{{ $this->describe($entry) }}</li>
                                @endforeach
                            </ul>
                            @if (count($report['warnings']) > 8)
                                <flux:text size="sm" class="mt-1">{{ __('… and :count more', ['count' => count($report['warnings']) - 8]) }}</flux:text>
                            @endif
                        </div>
                    @endif
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="import" :disabled="! $report || ($report['count'] ?? 0) === 0">{{ __('Import') }}</flux:button>
            </div>
        @endif
    </div>
</flux:modal>
