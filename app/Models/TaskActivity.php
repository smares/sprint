<?php

namespace App\Models;

use Database\Factories\TaskActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'user_id', 'type', 'data'])]
class TaskActivity extends Model
{
    /** @use HasFactory<TaskActivityFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What happened, as a sentence that continues after the person's name.
     */
    public function sentence(): string
    {
        $data = $this->data ?? [];
        $from = $data['from'] ?? '–';
        $to = $data['to'] ?? '–';
        $names = implode(', ', $data['names'] ?? []);

        return match ($this->type) {
            'created' => 'hat die Aufgabe angelegt',
            'status_changed' => "hat den Status von „{$from}“ auf „{$to}“ geändert",
            'assignee_changed' => "hat die Zuständigkeit von {$from} auf {$to} geändert",
            'due_date_changed' => "hat die Fälligkeit von {$from} auf {$to} geändert",
            'title_changed' => "hat den Titel von „{$from}“ in „{$to}“ geändert",
            'description_changed' => 'hat die Beschreibung geändert',
            'parent_changed' => ($data['to'] ?? null) === null
                ? 'hat die Aufgabe aus „'.$from.'“ gelöst'
                : "hat die Aufgabe unter „{$to}“ verschoben",
            'tags_added' => "hat die Tags {$names} hinzugefügt",
            'tags_removed' => "hat die Tags {$names} entfernt",
            'collaborators_added' => "hat {$names} als Beteiligte hinzugefügt",
            'collaborators_removed' => "hat {$names} als Beteiligte entfernt",
            'blockers_added' => "hat {$names} als Blocker hinzugefügt",
            'blockers_removed' => "hat {$names} als Blocker entfernt",
            'blocking_added' => "blockiert jetzt {$names}",
            'blocking_removed' => "blockiert {$names} nicht mehr",
            default => $this->type,
        };
    }
}
