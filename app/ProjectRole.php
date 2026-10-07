<?php

namespace App;

enum ProjectRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Ansehen',
            self::Editor => 'Bearbeiten',
            self::Admin => 'Verwalten',
        };
    }

    /**
     * Roles in ascending order of what they may do.
     */
    public function level(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Editor => 2,
            self::Admin => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }
}
