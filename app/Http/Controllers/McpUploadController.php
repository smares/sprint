<?php

namespace App\Http\Controllers;

use App\Mcp\AttachmentUpload;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Takes the file for a link that the MCP tool add-attachment handed out. The link holds only a random key; the person, the task, the
 * file name and the token are kept on the server (see AddAttachment), so nothing in the request can change them. The link works once
 * (a failed upload leaves it usable), and the person must still be allowed to change the task and the token must still exist.
 */
class McpUploadController extends Controller
{
    public static function grantKey(string $key): string
    {
        return "mcp-upload:{$key}";
    }

    public function __invoke(Request $request, string $key): JsonResponse
    {
        $grant = Cache::get(self::grantKey($key));
        abort_unless(is_array($grant), 404, 'This link is invalid, expired or already used.');

        $user = User::query()->find($grant['user']);
        abort_unless($user instanceof User && $user->isActive(), 403, 'This link is no longer valid.');
        abort_unless($this->tokenStillAllowsWriting($grant['token'] ?? null), 403, 'The access token of this link is gone, expired or read-only.');

        $task = Task::query()
            ->withoutSections()
            ->whereHas('project', fn (Builder $projects) => $projects->visibleTo($user))
            ->find($grant['task']);
        abort_if($task === null, 404, 'Task not found.');
        abort_unless(Gate::forUser($user)->allows('edit', $task->project), 403, 'You may not change tasks in this project.');

        // PHP reads these two kinds of bodies itself (and in a different way on 8.3 and 8.4), so only a raw body reaches the file unchanged
        abort_if(Str::of((string) $request->header('Content-Type'))->lower()->startsWith(['multipart/', 'application/x-www-form-urlencoded']), 415, 'Send the raw file as the request body: curl -T file -H "Content-Type: application/octet-stream" URL (not -F, not --data).');

        $limit = Attachment::MAX_KILOBYTES * 1024;
        abort_if((int) $request->header('Content-Length') > $limit, 413, "The file is larger than {$limit} bytes.");

        $lock = Cache::lock(self::grantKey($key).':lock', 300);
        abort_unless($lock->get(), 409, 'An upload with this link is already running.');

        $path = '';

        try {
            $path = (string) tempnam(sys_get_temp_dir(), 'sprint-upload-');
            abort_unless(Cache::has(self::grantKey($key)), 404, 'This link is invalid, expired or already used.');

            $size = $this->receive($request, $path, $limit);
            abort_if($size === 0, 422, 'The file is empty.');

            Auth::setUser($user);
            $attachment = AttachmentUpload::store($task, $path, (string) $grant['name']);
            Cache::forget(self::grantKey($key));

            return response()->json(AttachmentUpload::describe($attachment), 201);
        } finally {
            @unlink($path);
            $lock->release();
        }
    }

    /**
     * Links only work while the token they were issued to exists, has not expired and may write.
     */
    private function tokenStillAllowsWriting(mixed $tokenId): bool
    {
        $token = $tokenId === null ? null : PersonalAccessToken::query()->find($tokenId);

        return $token instanceof PersonalAccessToken && $token->can('write') && ($token->expires_at === null || $token->expires_at->isFuture());
    }

    /**
     * Copies the request body to a file and stops as soon as it is bigger than allowed.
     */
    private function receive(Request $request, string $path, int $limit): int
    {
        $output = fopen($path, 'wb');
        $copied = stream_copy_to_stream($request->getContent(true), $output, $limit + 1);

        abort_if(! fclose($output) || $copied === false, 507, 'The file could not be stored (disk full?).');
        abort_if($copied > $limit, 413, "The file is larger than {$limit} bytes.");

        return $copied;
    }
}
