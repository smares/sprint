<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Locale;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin', 'locale', 'deactivated_at', 'digest_enabled'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['digest_enabled' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'deactivated_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'digest_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->locale ??= config('app.locale');
        });
    }

    /**
     * Mails and notifications to this person go out in their language.
     */
    public function preferredLocale(): string
    {
        return Locale::isSupported($this->locale) ? $this->locale : config('app.locale');
    }

    /**
     * People who can still sign in and be assigned work.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Whether this is the only administrator who can still sign in.
     */
    public function isLastActiveAdmin(): bool
    {
        return $this->is_admin
            && $this->isActive()
            && ! self::query()->active()->where('is_admin', true)->whereKeyNot($this->getKey())->exists();
    }

    /**
     * Block the sign-in and end all sessions; tasks, comments and history stay as they are.
     */
    public function deactivate(): void
    {
        $this->forceFill(['deactivated_at' => now(), 'remember_token' => null])->save();
        $this->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $this->getKey())->delete();
        }
    }

    /**
     * For people locked out: drop the authenticator app and all passkeys so that the password is enough again.
     */
    public function resetSecondFactors(): void
    {
        $this->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->passkeys()->delete();
    }

    public function reactivate(): void
    {
        $this->forceFill(['deactivated_at' => null])->save();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->withPivot('role')->withTimestamps();
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
