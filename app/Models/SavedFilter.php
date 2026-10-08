<?php

namespace App\Models;

use Database\Factories\SavedFilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named combination of list filters and sorting, private or shared with the whole project.
 *
 * @property array{status: string, assignee: string, tag: string, fields: array<int|string, string>, sort: string, direction: string} $filters
 */
#[Fillable(['project_id', 'user_id', 'name', 'filters'])]
class SavedFilter extends Model
{
    /** @use HasFactory<SavedFilterFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Shared views and the person's own ones.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $views) => $views->whereNull('user_id')->orWhere('user_id', $user->getKey()));
    }
}
