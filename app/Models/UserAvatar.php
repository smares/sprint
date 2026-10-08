<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's profile picture. The browser crops and shrinks it to SIZE pixels before the upload,
 * so it stays small enough to live in the database (as Base64, see the migration).
 */
#[Fillable(['user_id', 'mime_type', 'data'])]
#[WithoutIncrementing]
class UserAvatar extends Model
{
    /** Edge length in pixels that the browser scales the picture to. */
    public const SIZE = 256;

    /** Largest accepted upload in kilobytes; a SIZE × SIZE picture needs a fraction of it. */
    public const MAX_KILOBYTES = 256;

    /** Formats the browser produces; anything else (SVG above all) is refused. */
    public const MIME_TYPES = ['image/webp', 'image/jpeg', 'image/png'];

    protected $primaryKey = 'user_id';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contents(): string
    {
        return (string) base64_decode($this->data, true);
    }
}
