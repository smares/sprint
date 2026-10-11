<?php

namespace App\Enums;

/**
 * The ways to look at a project's tasks, in the order the switcher and the sidebar offer them.
 */
enum ProjectView: string
{
    case List = 'list';
    case Board = 'board';
    case Calendar = 'calendar';
    case Timeline = 'timeline';
    case Statistics = 'statistics';

    public function label(): string
    {
        return match ($this) {
            self::List => __('List'),
            self::Board => __('Board'),
            self::Calendar => __('Calendar'),
            self::Timeline => __('Timeline'),
            self::Statistics => __('Statistics'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::List => 'list-bullet',
            self::Board => 'view-columns',
            self::Calendar => 'calendar-days',
            self::Timeline => 'arrow-turn-down-right',
            self::Statistics => 'presentation-chart-line',
        };
    }

    public function routeName(): string
    {
        return match ($this) {
            self::List => 'projects.show',
            default => 'projects.'.$this->value,
        };
    }

    /**
     * The view a route shows, if it is one of them.
     */
    public static function forRoute(?string $routeName): ?self
    {
        foreach (self::cases() as $view) {
            if ($view->routeName() === $routeName) {
                return $view;
            }
        }

        return null;
    }
}
