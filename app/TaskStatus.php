<?php

namespace App;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'Offen',
            self::InProgress => 'In Arbeit',
            self::Done => 'Erledigt',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Todo => 'zinc',
            self::InProgress => 'blue',
            self::Done => 'green',
        };
    }
}
