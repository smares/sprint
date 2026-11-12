<?php

namespace App\Concerns;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;
use ReflectionProperty;

/**
 * What a project view does with finished tasks: it shows only the open ones, all of them (the finished ones dimmed) or only
 * the finished ones. The component keeps the choice in `$doneFilter` (open, all or done; in the address as `done`, in a saved
 * view as `done`), and the default the view starts with is the value it declares for that property.
 */
trait FiltersDoneTasks
{
    /**
     * An address from before finishing was separate from the status (`?status=open` or `?status=all`, as the list used to take
     * them) keeps meaning what it meant.
     */
    public function mountFiltersDoneTasks(): void
    {
        if (property_exists($this, 'statusFilter') && in_array($this->statusFilter, ['open', 'all'], true)) {
            if (! request()->query->has('done')) {
                $this->doneFilter = $this->statusFilter;

                // The address says it, so a default saved view does not take it back (ManagesSavedViews keeps what the address names)
                request()->query->set('done', $this->doneFilter);
            }

            $this->statusFilter = '';
        }
    }

    /**
     * @return array<string, string>
     */
    public function doneFilterOptions(): array
    {
        return [
            'open' => __('Open tasks'),
            'all' => __('All tasks'),
            'done' => __('Done tasks'),
        ];
    }

    /**
     * Limit the tasks to what the filter asks for; a value that is none of the three (an edited address) shows everything.
     *
     * @param  Builder<Task>|Relation<Task, Project, mixed>  $query
     */
    protected function applyDoneFilter(Builder|Relation $query): void
    {
        match ($this->doneFilter) {
            'open' => $query->open(),
            'done' => $query->done(),
            default => null,
        };
    }

    /**
     * What the view really shows: the value of `$doneFilter`, or the view's default when an edited address says something else (which
     * shows everything, see applyDoneFilter). The button of the phones and the line under the views go by this.
     */
    #[Computed]
    public function shownDoneFilter(): string
    {
        return $this->validDoneFilter($this->doneFilter, (string) (new ReflectionProperty($this, 'doneFilter'))->getDefaultValue());
    }

    /**
     * The value of a saved view, or the default when it is none of the three.
     */
    protected function validDoneFilter(mixed $value, string $default): string
    {
        return is_string($value) && array_key_exists($value, $this->doneFilterOptions()) ? $value : $default;
    }
}
