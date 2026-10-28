<?php

namespace App\Models;

use App\Services\RealtimeService;
use App\Services\TaskSearchService;
use Database\Factories\AttachmentFactory;
use finfo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

#[Fillable(['task_id', 'user_id', 'name', 'path', 'mime_type', 'size'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /** Maximum upload size in kilobytes. */
    public const MAX_KILOBYTES = 20480;

    /** The largest file an agent may send inside a tool call (Base64 inflates it by a third); bigger files go through an upload link. */
    public const MAX_INLINE_BYTES = 1048576;

    /** Image types that may be shown inline; everything else is always downloaded. */
    public const INLINE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Text types that may be previewed; they are always delivered as plain text. */
    public const TEXT_MIME_TYPES = ['text/plain', 'text/csv', 'text/markdown', 'application/json'];

    protected static function booted(): void
    {
        static::saved(function (self $attachment) {
            app(TaskSearchService::class)->index($attachment->task_id);
            app(RealtimeService::class)->taskContentChanged($attachment->task_id, 'attachment');
        });

        static::deleted(function (self $attachment) {
            Storage::disk()->delete($attachment->path);
            app(TaskSearchService::class)->index($attachment->task_id);
            app(RealtimeService::class)->taskContentChanged($attachment->task_id, 'attachment');
        });
    }

    /**
     * Put bytes or a stream on the default disk under the task's folder: the type is read from the whole content, and the file gets a
     * random name plus the extension of that type ("x.php" must not be stored as a script). The caller makes the row, and removes the
     * file again if that fails.
     *
     * @param  string|resource  $contents  A string, or a seekable stream positioned at its start (best a file such as tmpfile(), whose type is read from the file)
     * @return array{path: string, mime_type: string, size: int}
     */
    public static function put(Task $task, mixed $contents): array
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $uri = is_string($contents) ? null : (stream_get_meta_data($contents)['uri'] ?? null);

        $mimeType = match (true) {
            is_string($contents) => $finfo->buffer($contents),
            is_string($uri) && is_file($uri) => $finfo->file($uri),
            default => $finfo->buffer((string) fread($contents, 1048576)),
        } ?: 'application/octet-stream';

        if (! is_string($contents)) {
            rewind($contents);
        }

        $extension = self::extensionFor($mimeType);
        $path = "attachments/{$task->project_id}/{$task->getKey()}/".Str::random(40).($extension === '' ? '' : ".{$extension}");

        throw_unless(Storage::disk()->put($path, $contents, ['mimetype' => $mimeType]), RuntimeException::class, 'The file could not be stored.');

        return ['path' => $path, 'mime_type' => $mimeType, 'size' => is_string($contents) ? strlen($contents) : (int) fstat($contents)['size']];
    }

    /**
     * The usual extension of a type, empty when there is none.
     */
    public static function extensionFor(string $mimeType): string
    {
        return MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? '';
    }

    /**
     * A file name as it may be shown and offered for download: no folders, no control characters, at most 255 characters.
     */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));

        return in_array($name, ['', '.', '..'], true) ? 'file' : mb_substr($name, 0, 255);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isInlineImage(): bool
    {
        return in_array($this->mime_type, self::INLINE_MIME_TYPES, true);
    }

    /**
     * How the file can be previewed in the browser: `image`, `pdf`, `text` or null (download only).
     */
    public function previewKind(): ?string
    {
        return match (true) {
            $this->isInlineImage() => 'image',
            $this->mime_type === 'application/pdf' => 'pdf',
            in_array($this->mime_type, self::TEXT_MIME_TYPES, true) => 'text',
            default => null,
        };
    }

    public function humanSize(): string
    {
        return self::formatSize($this->size);
    }

    /**
     * A size for people ("1,4 MB", "20 KB"), the same wherever files are listed.
     */
    public static function formatSize(int $bytes): string
    {
        return preg_replace('/[.,]0(?= )/', '', Number::fileSize($bytes, precision: 1));
    }
}
