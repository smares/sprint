<?php

namespace App\Mcp;

use App\Enums\ActivityType;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * A file that agents sent, whether inside a tool call or through an upload link: stored like an upload in the app.
 */
class AttachmentUpload
{
    /**
     * @param  string|resource  $contents  The file as a string or a seekable stream
     */
    public static function store(Task $task, User $by, string $name, mixed $contents): Attachment
    {
        $name = Attachment::cleanName($name);

        // The history and the notifications name the person who is acting
        Auth::setUser($by);

        $attachment = $task->attachContents($name, $contents, $by);
        $task->logActivity(ActivityType::AttachmentsAdded, ['names' => [$name]]);

        return $attachment;
    }

    /**
     * @return array{id: int, task_id: int, name: string, size: int, mime_type: string|null}
     */
    public static function describe(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'task_id' => $attachment->task_id,
            'name' => $attachment->name,
            'size' => $attachment->size,
            'mime_type' => $attachment->mime_type,
        ];
    }
}
