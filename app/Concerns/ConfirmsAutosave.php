<?php

namespace App\Concerns;

use Flux\Flux;

/**
 * Fields that save on their own (names on blur, colours, checkboxes, roles) confirm it with a short toast,
 * since there is no button whose click would show that something happened.
 */
trait ConfirmsAutosave
{
    public const AUTOSAVE_TOAST_MS = 2000;

    protected function confirmSaved(): void
    {
        Flux::toast(variant: 'success', text: __('Saved.'), duration: self::AUTOSAVE_TOAST_MS);
    }
}
