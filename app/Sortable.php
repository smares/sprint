<?php

namespace App;

/**
 * Settings shared by every list that can be reordered by dragging (wire:sort).
 */
class Sortable
{
    /**
     * On touch screens a card or row is only picked up after holding it a moment and when the finger barely moved, so
     * scrolling through a list does not move things by accident. With a mouse dragging starts at once.
     */
    public const string CONFIG = '{ delay: 250, delayOnTouchOnly: true, touchStartThreshold: 12 }';

    /**
     * The same, but a card cannot be reordered within its list, only dragged into another one (a board whose cards are
     * sorted by a criterion: the status can still be changed by dropping, the place follows from the sort order).
     */
    public const string CONFIG_MOVE_ONLY = '{ sort: false, delay: 250, delayOnTouchOnly: true, touchStartThreshold: 12 }';
}
