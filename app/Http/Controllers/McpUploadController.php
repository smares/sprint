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

/**
 * Takes the file for a link that the MCP tool add-attachment handed out: the link is signed (task, person, file name,
 * expiry), works once, and the person must still be allowed to change the task.
 */
class McpUploadController extends Controller
{
    public function __invoke(Request $request, int $task): JsonResponse
    {
        $user = User::query()->find($request->integer('user'));
        abort_unless($user instanceof User && $user->isActive(), 403, 'This link is no longer valid.');

        $task = Task::query()
            ->withoutSections()
            ->whereHas('project', fn (Builder $projects) => $projects->visibleTo($user))
            ->find($task);
        abort_if($task === null, 404, 'Task not found.');
        abort_unless(Gate::forUser($user)->allows('edit', $task->project), 403, 'You may not change tasks in this project.');

        $limit = Attachment::MAX_KILOBYTES * 1024;
        abort_if((int) $request->header('Content-Length') > $limit, 413, "The file is larger than {$limit} bytes.");

        $nonce = (string) $request->query('nonce');
        abort_unless(Cache::add("mcp-upload:{$nonce}", true, now()->addMinutes(15)), 409, 'This link has been used already.');

        $path = (string) tempnam(sys_get_temp_dir(), 'sprint-upload-');

        try {
            $size = $this->receive($request, $path, $limit);
            abort_if($size === 0, 422, 'The file is empty.');

            Auth::setUser($user);

            return response()->json(AttachmentUpload::describe(AttachmentUpload::store($task, $path, (string) $request->query('name'))), 201);
        } finally {
            @unlink($path);
        }
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
            $chunk = (string) fread($input, 65536);
            $size += strlen($chunk);

            if ($size > $limit) {
                fclose($output);
                abort(413, "The file is larger than {$limit} bytes.");
            }

            fwrite($output, $chunk);
        }

        fclose($output);

        return $size;
    }
}
