<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Keeps what a person arranged in the interface (appearance, sidebar, collapsed columns) on the server, so that a second
 * device shows the same. A request carries only what changed on that device; a column is sent as its own change.
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
            'board_collapsed.*.*' => ['boolean'],
            'mine_board_collapsed' => ['sometimes', 'array', 'max:'.self::MAX_COLUMNS],
            'mine_board_collapsed.*' => ['boolean'],
        ]);

        foreach (array_keys($changes['board_collapsed'] ?? []) as $project) {
            abort_unless(ctype_digit((string) $project), 422);
        }

        foreach (array_keys($changes['mine_board_collapsed'] ?? []) as $column) {
            abort_unless(ctype_digit((string) $column), 422);
        }

        $request->user()->rememberInterface($changes);

        return response()->json(status: 204);
    }
}
