<?php

use App\Models\Project;
use App\TaskCsv;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
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
        $csv = app(TaskCsv::class);
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
        $this->validate(['file' => ['required', 'file', 'max:'.TaskCsv::MAX_KILOBYTES]], [], ['file' => 'Datei']);

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

        $this->imported = app(TaskCsv::class)->import($this->project, auth()->user(), $plan['tasks']);
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
            'no-title' => 'Kein Titel',
            'title-too-long' => 'Titel länger als 255 Zeichen',
            'too-many-rows' => 'Mehr als '.TaskCsv::MAX_ROWS.' Zeilen, der Rest wird nicht importiert',
            'unknown-status' => "Status „{$detail}“ gibt es nicht, es gilt der erste offene",
            'unknown-person' => "„{$detail}“ ist kein aktives Mitglied des Projekts und wird ignoriert",
            'bad-date' => "Datum „{$detail}“ nicht lesbar und wird ignoriert",
            'start-after-due' => 'Beginn liegt nach der Fälligkeit und wird ignoriert',
            'bad-field' => "Feldwert „{$detail}“ passt nicht und wird ignoriert",
            default => $entry['message'],
        };

        return $entry['line'] > 0 ? "Zeile {$entry['line']}: {$text}" : $text;
    }
};
?>

<flux:modal name="project-import" class="w-full max-w-lg" x-on:close="$wire.startOver()">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">Aufgaben aus CSV importieren</flux:heading>
            <flux:text class="mt-1">Lade eine CSV-Datei hoch, zum Beispiel einen Export aus Sprint oder aus Asana. Jede Zeile wird zu einer neuen Aufgabe; vorhandene Aufgaben ändern sich nicht.</flux:text>
        </div>

        @if ($imported !== null)
            <flux:callout variant="success" icon="check-circle" :heading="$imported === 1 ? 'Eine Aufgabe importiert' : $imported.' Aufgaben importiert'" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="startOver">Noch eine Datei</flux:button>
                <flux:modal.close><flux:button variant="primary" x-on:click="$dispatch('tasks-imported')">Fertig</flux:button></flux:modal.close>
            </div>
        @else
            <flux:file-upload wire:model="file" label="CSV-Datei">
                <flux:file-upload.dropzone heading="Datei hierher ziehen oder klicken" text="CSV bis {{ intdiv(TaskCsv::MAX_KILOBYTES, 1024) }} MB, höchstens {{ TaskCsv::MAX_ROWS }} Zeilen" inline />
            </flux:file-upload>
            @error('file') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

            @if ($report)
                @if ($report['error'] === 'no-title')
                    <flux:callout variant="danger" icon="exclamation-triangle" heading="Keine Titel-Spalte gefunden" text="Die erste Zeile braucht eine Spalte „title“ (oder „Name“, „Titel“, „Aufgabe“)." />
                @elseif ($report['error'] === 'empty')
                    <flux:callout variant="danger" icon="exclamation-triangle" heading="Die Datei ist leer" />
                @else
                    <flux:callout :variant="$report['count'] > 0 ? 'success' : 'warning'" icon="document-text" :heading="$report['count'] === 1 ? 'Eine Aufgabe bereit zum Import' : $report['count'].' Aufgaben bereit zum Import'" />

                    @if ($report['errors'])
                        <div>
                            <flux:heading size="sm">Diese Zeilen werden übersprungen ({{ count($report['errors']) }})</flux:heading>
                            <ul class="mt-1 list-inside list-disc text-sm text-red-600 dark:text-red-400">
                                @foreach (array_slice($report['errors'], 0, 8) as $entry)
                                    <li>{{ $this->describe($entry) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($report['warnings'])
                        <div>
                            <flux:heading size="sm">Hinweise ({{ count($report['warnings']) }})</flux:heading>
                            <ul class="mt-1 list-inside list-disc text-sm text-amber-700 dark:text-amber-400">
                                @foreach (array_slice($report['warnings'], 0, 8) as $entry)
                                    <li>{{ $this->describe($entry) }}</li>
                                @endforeach
                            </ul>
                            @if (count($report['warnings']) > 8)
                                <flux:text size="sm" class="mt-1">… und {{ count($report['warnings']) - 8 }} weitere</flux:text>
                            @endif
                        </div>
                    @endif
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Abbrechen</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="import" :disabled="! $report || ($report['count'] ?? 0) === 0">Importieren</flux:button>
            </div>
        @endif
    </div>
</flux:modal>
