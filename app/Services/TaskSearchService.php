<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Searches tasks. On SQLite with FTS5 an index table is used (ranked, prefix and diacritics
 * aware); everywhere else the search falls back to plain LIKE queries.
 */
class TaskSearchService
{
    public const TABLE = 'task_search';

    /** Without the full-text index, at least one search word needs this many characters. */
    public const MIN_LIKE_LENGTH = 3;

    private ?bool $fullText = null;

    /**
     * Whether the FTS5 index table exists on the current connection.
     */
    public function usesFullText(): bool
    {
        return $this->fullText ??= DB::connection()->getDriverName() === 'sqlite' && Schema::hasTable(self::TABLE);
    }

    /**
     * Create the FTS5 table; returns false when this database cannot do it.
     */
    public function createIndexTable(): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return false;
        }

        try {
            DB::statement('create virtual table if not exists '.self::TABLE.' using fts5(title, description, comments, attachments, tokenize = "unicode61 remove_diacritics 2")');
        } catch (Throwable) {
            return false;
        }

        $this->fullText = null;

        return true;
    }

    public function dropIndexTable(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('drop table if exists '.self::TABLE);
        }

        $this->fullText = null;
    }

    /**
     * Write the current state of the task into the index.
     */
    public function index(int|Task $task): void
    {
        if (! $this->usesFullText()) {
            return;
        }

        $task = $task instanceof Task ? $task : Task::find($task);

        if ($task === null || $task->is_section) {
            return;
        }

        $this->forget($task->id);

        DB::table(self::TABLE)->insert([
            'rowid' => $task->id,
            'title' => $task->title,
            'description' => (string) $task->description,
            'comments' => $task->comments()->orderBy('id')->pluck('body')->implode("\n"),
            'attachments' => $task->attachments()->orderBy('id')->pluck('name')->implode("\n"),
        ]);
    }

    public function forget(int $taskId): void
    {
        if ($this->usesFullText()) {
            DB::table(self::TABLE)->where('rowid', $taskId)->delete();
        }
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function forgetMany(array $taskIds): void
    {
        if ($this->usesFullText()) {
            foreach (array_chunk($taskIds, 500) as $chunk) {
                DB::table(self::TABLE)->whereIn('rowid', $chunk)->delete();
            }
        }
    }

    /**
     * Rebuild the whole index from scratch.
     */
    public function rebuild(): int
    {
        if (! $this->usesFullText()) {
            return 0;
        }

        DB::table(self::TABLE)->delete();

        $count = 0;

        Task::where('is_section', false)->orderBy('id')->chunkById(200, function ($tasks) use (&$count) {
            foreach ($tasks as $task) {
                $this->index($task);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Split what a person typed into search words; punctuation separates words.
     *
     * @return list<string>
     */
    public function terms(string $query): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            fn (string $term) => $term !== '',
        )));
    }

    /**
     * Without the full-text index every word scans titles, descriptions, comments and file names,
     * so searches made only of one- or two-letter words find nothing instead of almost everything.
     *
     * @param  list<string>  $terms
     */
    public function isTooShort(array $terms): bool
    {
        return $terms !== [] && ! $this->usesFullText() && max(array_map(mb_strlen(...), $terms)) < self::MIN_LIKE_LENGTH;
    }

    /**
     * Tasks matching all words and visible to the person, best match first.
     *
     * @param  array{project_id?: int|string|null, state?: string, mine?: bool}  $filters
     * @return Builder<Task>
     */
    public function search(User $user, string $query, array $filters = []): Builder
    {
        $terms = $this->terms($query);

        $tasks = Task::query()
            ->select('tasks.*')
            ->where('tasks.is_section', false)
            ->visibleTo($user);

        if ($terms === []) {
            return $tasks->whereRaw('0 = 1');
        }

        if ($this->usesFullText()) {
            $match = implode(' ', array_map(fn (string $term) => '"'.$term.'"*', $terms));

            $tasks->join(self::TABLE, self::TABLE.'.rowid', '=', 'tasks.id')
                ->whereRaw(self::TABLE.' match ?', [$match])
                ->orderByRaw('bm25('.self::TABLE.', 10.0, 3.0, 1.0, 1.0)');
        } else {
            if ($this->isTooShort($terms)) {
                return $tasks->whereRaw('0 = 1');
            }

            foreach ($terms as $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';

                $tasks->where(fn (Builder $any) => $any
                    ->whereLike('tasks.title', $like)
                    ->orWhereLike('tasks.description', $like)
                    ->orWhereIn('tasks.id', Comment::query()->whereLike('body', $like)->select('task_id'))
                    ->orWhereIn('tasks.id', Attachment::query()->whereLike('name', $like)->select('task_id'))
                );
            }

            $tasks->orderByDesc('tasks.updated_at');
        }

        if (! empty($filters['project_id'])) {
            $tasks->where('tasks.project_id', $filters['project_id']);
        }

        match ($filters['state'] ?? 'all') {
            'open' => $tasks->open(),
            'done' => $tasks->done(),
            default => null,
        };

        if (! empty($filters['mine'])) {
            $tasks->involving($user);
        }

        return $tasks->orderBy('tasks.id');
    }

    /**
     * Where the words were found, apart from the title: a short label and an excerpt.
     *
     * @param  list<string>  $terms
     * @return array{label: string, text: string}|null
     */
    public function explain(Task $task, array $terms): ?array
    {
        $contains = fn (?string $text) => $text !== null && collect($terms)->contains(fn (string $term) => mb_stripos($text, $term) !== false);
        $excerpt = fn (string $text) => Str::excerpt($text, collect($terms)->first(fn (string $term) => mb_stripos($text, $term) !== false), ['radius' => 70]) ?? Str::limit($text, 140);

        if ($contains($task->title) && collect($terms)->every(fn (string $term) => mb_stripos($task->title, $term) !== false)) {
            return null;
        }

        if ($contains($task->description)) {
            return ['label' => __('Description'), 'text' => $excerpt($task->description)];
        }

        foreach ($task->comments as $comment) {
            if ($contains($comment->body)) {
                return ['label' => __('Comment'), 'text' => $excerpt($comment->body)];
            }
        }

        foreach ($task->attachments as $attachment) {
            if ($contains($attachment->name)) {
                return ['label' => __('Attachment'), 'text' => $attachment->name];
            }
        }

        return null;
    }
}
