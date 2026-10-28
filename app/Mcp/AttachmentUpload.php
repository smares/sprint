<?php

namespace App\Mcp;

use App\Enums\ActivityType;
use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\UploadedFile;

/**
 * A file that agents sent, whether inside a tool call or through an upload link: stored like an upload in the app.
 */
class AttachmentUpload
{
    /**
     * @param  string  $path  The file as it arrived; the caller removes it.
     */
    public static function store(Task $task, string $path, string $name): Attachment
    {
        $name = Attachment::cleanName($name);
        $attachment = $task->attachUpload(new UploadedFile($path, $name, null, null, true));
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
