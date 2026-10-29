<?php

namespace App\Enums;

/**
 * The ways to look at one's own tasks, in the order the switcher and the sidebar offer them.
 */
enum MineView: string
{
    case List = 'list';
    case Board = 'board';

    public function label(): string
    {
        return match ($this) {
            self::List => __('List'),
            self::Board => __('Board'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::List => 'list-bullet',
            self::Board => 'view-columns',
        };
    }

    public function routeName(): string
    {
        return match ($this) {
            self::List => 'tasks.mine',
            self::Board => 'tasks.mine.board',
        };
    }

    /**
     * The view a route shows, if it is one of them.
     */
    public static function forRoute(?string $routeName): ?self
    {
        return collect(self::cases())->first(fn (self $view) => $view->routeName() === $routeName);
    }

    /**
     * The links of the view switcher and the sidebar, the open view marked.
     *
     * @return array<string, array{href: string, icon: string, label: string, current: bool}>
     */
    public static function links(?self $current): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $view) => [$view->value => [
            'href' => route($view->routeName()),
            'icon' => $view->icon(),
            'label' => $view->label(),
            'current' => $view === $current,
        ]])->all();
    }
}
