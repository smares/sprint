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
}
