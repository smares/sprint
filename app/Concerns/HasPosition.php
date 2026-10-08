<?php

namespace App\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Models kept in a manual order by a `position` column among their siblings (statuses of a project,
 * fields, options, subtasks). The model says who its siblings are; moving writes only changed rows.
 */
trait HasPosition
{
    /**
     * The models that share one order with this one, including it.
     */
    abstract protected function positionSiblings(): Builder;

    /**
     * The first free position at the end of the given siblings.
     */
    public static function nextPositionIn(Builder $siblings): int
    {
        return ($siblings->max('position') ?? -1) + 1;
    }

    /**
     * Put this model at the given place (0 = first) among its siblings.
     */
    public function moveTo(int $position): void
    {
        DB::transaction(function () use ($position) {
            $current = $this->positionSiblings()->reorder()->orderBy('position')->orderBy('id')->pluck('position', 'id')->all();
            $orderedIds = array_values(array_diff(array_keys($current), [$this->getKey()]));

            array_splice($orderedIds, max(0, min($position, count($orderedIds))), 0, [$this->getKey()]);

            static::writePositions($orderedIds, $current);
        });
    }

    /**
     * Number the given ids in their order, writing only those whose position changes.
     *
     * @param  list<int>  $orderedIds
     * @param  array<int, int|string|null>  $current  Position by id as it is now.
     */
    public static function writePositions(array $orderedIds, array $current = []): void
    {
        foreach ($orderedIds as $index => $id) {
            $was = $current[$id] ?? null;

            if ($was === null || (int) $was !== $index) {
                static::query()->whereKey($id)->update(['position' => $index]);
            }
        }
    }
}
