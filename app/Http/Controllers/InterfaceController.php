<?php

namespace App\Http\Controllers;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Keeps what a person arranged in the interface (appearance, sidebar, collapsed columns) on the server, so that a second
 * device shows the same. A request carries only what changed on that device; a column is sent as its own change.
 */
class InterfaceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $changes = $request->validate([
            'appearance' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'sidebar_collapsed' => ['sometimes', 'boolean'],
            'board_collapsed' => ['sometimes', 'array', 'max:'.User::INTERFACE_MAX_BOARDS, $this->hasIdsAsKeys(allowZero: false)],
            'board_collapsed.*' => ['array', 'max:'.User::INTERFACE_MAX_COLUMNS, $this->hasIdsAsKeys(allowZero: true)],
            'board_collapsed.*.*' => ['boolean'],
            'mine_board_collapsed' => ['sometimes', 'array', 'max:'.User::INTERFACE_MAX_COLUMNS, $this->hasIdsAsKeys(allowZero: true)],
            'mine_board_collapsed.*' => ['boolean'],
        ]);

        return response()->json(['rev' => $request->user()->rememberInterface($changes)]);
    }

    /**
     * Projects and columns are named by their ids (column 0 is "Unsorted" in "My tasks", there is no project 0).
     */
    private function hasIdsAsKeys(bool $allowZero): Closure
    {
        $pattern = $allowZero ? '/^(0|[1-9]\d{0,17})$/' : '/^[1-9]\d{0,17}$/';

        return function (string $attribute, mixed $value, Closure $fail) use ($pattern): void {
            // The rule 'array' reports a scalar; this one still runs after it
            if (! is_array($value)) {
                return;
            }

            foreach (array_keys($value) as $key) {
                if (preg_match($pattern, (string) $key) !== 1) {
                    $fail(__('validation.array'));

                    return;
                }
            }
        };
    }
}
