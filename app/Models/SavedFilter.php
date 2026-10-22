<?php

namespace App\Models;

use Database\Factories\SavedFilterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named combination of filters and sorting of the list, or the sorting of the board, private or shared with the whole project.
 * One shared view per project and kind can be the default for everybody (is_default); each person can pick another one for
 * themselves (see User::defaultSavedView()).
 *
 * @property string $view
 * @property bool $is_default
 * @property array{status?: string, assignee?: string, collaborators?: list<string>, tag?: string, dates?: string, fields?: array<int|string, string>, sort?: string, direction?: string} $filters
 */
#[Fillable(['project_id', 'user_id', 'name', 'filters', 'view'])]
class SavedFilter extends Model
{
    /** @use HasFactory<SavedFilterFactory> */
    use HasFactory;

    public const string LIST = 'list';

    public const string BOARD = 'board';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['view' => self::LIST, 'is_default' => false];

    protected function casts(): array
    {
        return ['filters' => 'array', 'is_default' => 'boolean'];
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

    /**
     * The views of the list or of the board.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeForView(Builder $query, string $view): void
    {
        $query->where('view', $view);
    }
}
