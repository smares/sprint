<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The account of a person at a sign-in provider (Google), found by the provider's id.
 *
 * @property string $provider
 * @property string $provider_id
 * @property string $email
 */
#[Fillable(['user_id', 'provider', 'provider_id', 'email'])]
class SocialAccount extends Model
{
    public const string GOOGLE = 'google';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
