<?php

namespace App\Concerns;

use App\Enums\CustomFieldType;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The sort order that a person picks for the tasks of a project view, by due date, title, status or a custom field,
 * instead of the manual one (the `position` column). Written as SQL terms, so that the list adds them to its query and
 * the board to the window that numbers the tasks of a column.
 *
 * @property Collection $customFields
 */
trait SortsTasks
{
    #[Url(as: 'sort')]
    public string $sortBy = '';

    #[Url(as: 'dir')]
    public string $sortDirection = 'asc';

    /**
     * What the view reads again after the order changed (and starts again at its first page).
     */
    abstract protected function sorted(): void;

    /**
     * The plain criteria this view can sort by, besides the custom fields.
     *
     * @return list<string>
     */
    protected function sortableColumns(): array
    {
        return ['due', 'title', 'status'];
    }

    protected function isSortable(string $column): bool
    {
        return in_array($column, $this->sortableColumns(), true)
            || (str_starts_with($column, 'field:') && $this->customFields->contains('id', (int) substr($column, 6)));
    }

    /**
     * The sort direction from the address, limited to the two valid values.
     */
    protected function direction(): string
    {
        return $this->sortDirection === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Click on a criterion: ascending, then descending, then back to the manual order.
     */
    public function sort(string $column): void
    {
        if (! $this->isSortable($column)) {
            return;
        }

        if ($this->sortBy === $column) {
            if ($this->sortDirection === 'asc') {
                $this->sortDirection = 'desc';
            } else {
                $this->resetSort();

                return;
            }
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->sorted();
    }

    /**
     * The criterion was picked in a menu: only a valid one counts, and none is the manual order.
     */
    public function updatedSortBy(): void
    {
        if (! $this->isSorted()) {
            $this->reset('sortBy', 'sortDirection');
        }

        $this->sorted();
    }

    public function reverseSort(): void
    {
        if (! $this->isSorted()) {
            return;
        }

        $this->sortDirection = $this->direction() === 'asc' ? 'desc' : 'asc';

        $this->sorted();
    }

    /**
     * Back to the manual order.
     */
    public function resetSort(): void
    {
        $this->reset('sortBy', 'sortDirection');

        $this->sorted();
    }

    /**
     * Whether a criterion is in force instead of the manual order.
     */
    #[Computed]
    public function sortActive(): bool
    {
        return $this->isSorted();
    }

    protected function isSorted(): bool
    {
        return $this->sortBy !== '' && $this->isSortable($this->sortBy);
    }

    /**
     * The order as SQL for the table `tasks`: terms with their bindings, empty for the manual order. Tasks without a
     * value come last in both directions.
     *
     * @return list<array{0: string, 1: list<mixed>}>
     */
    protected function sortTerms(): array
    {
        if (! $this->isSorted()) {
            return [];
        }

        $direction = $this->direction();

        return match (true) {
            $this->sortBy === 'due' => [['tasks.due_date is null', []], ["tasks.due_date {$direction}", []]],
            $this->sortBy === 'title' => [['tasks.title'.$this->caseInsensitiveCollation()." {$direction}", []]],
            $this->sortBy === 'status' => [["(select task_statuses.position from task_statuses where task_statuses.id = tasks.status_id) {$direction}", []]],
            default => $this->fieldSortTerms($direction),
        };
    }

    /**
     * SQLite compares bytes, so "Zebra" would stand before "anton"; the other databases ignore the case by their collation.
     */
    private function caseInsensitiveCollation(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? ' collate nocase' : '';
    }

    /**
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function fieldSortTerms(string $direction): array
    {
        $field = $this->customFields->firstWhere('id', (int) substr($this->sortBy, 6));

        if ($field === null) {
            return [];
        }

        $value = CustomFieldValue::query()
            ->where('custom_field_values.custom_field_id', $field->id)
            ->whereColumn('custom_field_values.task_id', 'tasks.id');

        match ($field->type) {
            CustomFieldType::Select => $value->join('custom_field_options as sort_options', 'sort_options.id', '=', 'custom_field_values.option_id')->select('sort_options.position'),
            CustomFieldType::Number => $value->select(DB::raw('cast(custom_field_values.value as '.match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => 'decimal(30, 10)',
                'pgsql' => 'double precision',
                default => 'real',
            }.')')),
            default => $value->select('custom_field_values.value'),
        };

        $sql = $value->toSql();
        $bindings = $value->getBindings();

        $collation = in_array($field->type, [CustomFieldType::Select, CustomFieldType::Number], true) ? '' : $this->caseInsensitiveCollation();

        return [["({$sql}) is null", $bindings], ["({$sql}){$collation} {$direction}", $bindings]];
    }
}
