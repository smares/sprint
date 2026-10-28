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
        abort_unless($this->tokenStillAllowsWriting($grant['token'] ?? null), 403, 'The access token of this link is gone or read-only.');

        $task = Task::query()
            ->withoutSections()
            ->whereHas('project', fn (Builder $projects) => $projects->visibleTo($user))
            ->find($grant['task']);
        abort_if($task === null, 404, 'Task not found.');
        abort_unless(Gate::forUser($user)->allows('edit', $task->project), 403, 'You may not change tasks in this project.');

        abort_if(str_starts_with(strtolower((string) $request->header('Content-Type')), 'multipart/'), 415, 'Send the raw file as the request body (curl -T file URL), not multipart/form-data.');

        $limit = Attachment::MAX_KILOBYTES * 1024;
        abort_if((int) $request->header('Content-Length') > $limit, 413, "The file is larger than {$limit} bytes.");

        $lock = Cache::lock(self::grantKey($key).':lock', 300);
        abort_unless($lock->get(), 409, 'An upload with this link is already running.');

        $path = (string) tempnam(sys_get_temp_dir(), 'sprint-upload-');

        try {
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
     * Links that were issued to a token only work while that token exists and may write (links from tests or sessions carry none).
     */
    private function tokenStillAllowsWriting(mixed $tokenId): bool
    {
        if ($tokenId === null) {
            return true;
        }

        $token = PersonalAccessToken::query()->find($tokenId);

        return $token instanceof PersonalAccessToken && $token->can('write') && ($token->expires_at === null || $token->expires_at->isFuture());
    }

    /**
     * Copies the request body to a file and stops as soon as it is bigger than allowed.
     */
    private function receive(Request $request, string $path, int $limit): int
    {
        $input = $request->getContent(true);
        $output = fopen($path, 'wb');
        $size = 0;

        while (is_resource($input) && ! feof($input)) {
            $chunk = fread($input, 65536);

            if ($chunk === false) {
                break;
            }

            $size += strlen($chunk);

            if ($size > $limit) {
                fclose($output);
                abort(413, "The file is larger than {$limit} bytes.");
            }

            if (fwrite($output, $chunk) !== strlen($chunk)) {
                fclose($output);
                abort(507, 'The file could not be stored (disk full?).');
            }
        }

        fclose($output);

        return $size;
    }
}
