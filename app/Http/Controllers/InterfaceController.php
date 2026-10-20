<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Keeps what a person arranged in the interface (appearance, sidebar, collapsed columns) on the server, so that a second
 * device shows the same. A request carries only what changed on that device.
 */
class InterfaceController extends Controller
{
    /** Boards and columns a person can have collapsed at once; more is no use and only grows the row. */
    private const int MAX_BOARDS = 200;

    private const int MAX_COLUMNS = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $changes = $request->validate([
            'appearance' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'sidebar_collapsed' => ['sometimes', 'boolean'],
            'board_collapsed' => ['sometimes', 'array', 'max:'.self::MAX_BOARDS],
            'board_collapsed.*' => ['array', 'max:'.self::MAX_COLUMNS],
            'board_collapsed.*.*' => ['integer', 'min:1'],
            'mine_board_collapsed' => ['sometimes', 'array', 'max:'.self::MAX_COLUMNS],
            'mine_board_collapsed.*' => ['integer', 'min:1'],
        ]);

        if (isset($changes['board_collapsed'])) {
            $changes['board_collapsed'] = collect($changes['board_collapsed'])
                ->mapWithKeys(fn (array $columns, $project) => [(string) (int) $project => array_values(array_unique(array_map(intval(...), $columns)))])
                ->all();
        }

        if (isset($changes['mine_board_collapsed'])) {
            $changes['mine_board_collapsed'] = array_values(array_unique(array_map(intval(...), $changes['mine_board_collapsed'])));
        }

        $request->user()->rememberInterface($changes);

        return response()->json(status: 204);
    }
}
