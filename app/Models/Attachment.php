<?php

namespace App\Models;

use App\Services\TaskSearch;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

#[Fillable(['task_id', 'user_id', 'name', 'path', 'mime_type', 'size'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /** Maximum upload size in kilobytes. */
    public const MAX_KILOBYTES = 20480;

    /** Image types that may be shown inline; everything else is always downloaded. */
    public const INLINE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Text types that may be previewed; they are always delivered as plain text. */
    public const TEXT_MIME_TYPES = ['text/plain', 'text/csv', 'text/markdown', 'application/json'];

    protected static function booted(): void
    {
        static::saved(fn (self $attachment) => app(TaskSearch::class)->index($attachment->task_id));

        static::deleted(function (self $attachment) {
            Storage::disk()->delete($attachment->path);
            app(TaskSearch::class)->index($attachment->task_id);
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

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
        return preg_replace('/\.0(?= )/', '', Number::fileSize($this->size, precision: 1));
    }
}
