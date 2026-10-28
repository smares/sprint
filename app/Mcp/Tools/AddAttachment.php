<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\McpUploadController;
use App\Mcp\AttachmentUpload;
use App\Mcp\ToolFailure;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Sanctum\PersonalAccessToken;

#[Name('add-attachment')]
#[Description('Attaches a file to a task as the token owner. Small files (up to 1 MB) go in content_base64. Without content_base64 the tool returns a one-time upload URL (valid for 10 minutes, keep it secret) for files up to 20 MB, which needs a client that can run commands (without a shell, use content_base64): send the file with an HTTP PUT whose body is the raw file, for example `curl -T file -H "Content-Type: application/octet-stream" "URL"` (not multipart, so no `curl -F` or `--data`). Files cannot be downloaded through this server.')]
class AddAttachment extends SprintTool
{
    private const int LINK_MINUTES = 10;

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Id of the task.')->required(),
            'name' => $schema->string()->description('File name as it is shown, with extension (at most 255 characters).')->required(),
            'content_base64' => $schema->string()->description('The file, Base64 encoded, up to '.Attachment::MAX_INLINE_BYTES.' bytes before encoding. Leave it out to get an upload URL for larger files.'),
        ];
    }

    protected function perform(Request $request, User $user): Response
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'content_base64' => ['nullable', 'string'],
        ]);

        $task = $this->visibleTask($user, $validated['task_id']);
        $this->requireEdit($user, $task->project);

        $name = Attachment::cleanName($validated['name']);

        if (blank($validated['content_base64'] ?? null)) {
            return $this->uploadLink($task->id, $user, $name);
        }

        return Response::json(AttachmentUpload::describe(AttachmentUpload::store($task, $user, $name, $this->decode($validated['content_base64']))));
    }

    private function decode(string $encoded): string
    {
        throw_if(strlen($encoded) > Attachment::MAX_INLINE_BYTES * 4 / 3 + 16, ToolFailure::class, 'The file is too large for content_base64 (up to '.Attachment::MAX_INLINE_BYTES.' bytes). Call the tool without content_base64 to get an upload URL.');

        $content = base64_decode($encoded, true);

        throw_if($content === false, ToolFailure::class, 'content_base64 is not valid Base64.');
        throw_if($content === '', ToolFailure::class, 'The file is empty.');
        throw_if(strlen($content) > Attachment::MAX_INLINE_BYTES, ToolFailure::class, 'The file is too large for content_base64 (up to '.Attachment::MAX_INLINE_BYTES.' bytes). Call the tool without content_base64 to get an upload URL.');

        return $content;
    }

    /**
     * The link carries nothing but a random key; who, which task and which file name are kept on the server.
     */
    private function uploadLink(int $taskId, User $user, string $name): Response
    {
        $token = $user->currentAccessToken();
        throw_unless($token instanceof PersonalAccessToken, ToolFailure::class, 'Upload links need an access token.');

        $expires = now()->addMinutes(self::LINK_MINUTES);
        $key = Str::random(40);

        Cache::put(McpUploadController::grantKey($key), [
            'task' => $taskId,
            'user' => $user->id,
            'token' => $token->getKey(),
            'name' => $name,
        ], $expires);

        return Response::json([
            'upload_url' => route('mcp.uploads.store', $key),
            'method' => 'PUT',
            'how' => 'Send the raw file as the request body: curl -T <file> -H "Content-Type: application/octet-stream" "<upload_url>". Do not use multipart/form-data (curl -F) or curl --data.',
            'expires_at' => $expires->toIso8601String(),
            'max_bytes' => Attachment::MAX_KILOBYTES * 1024,
            'single_use' => true,
        ]);
    }
}
