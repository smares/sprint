<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\EmailChanged;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyNewEmail;
use App\Services\LocaleService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;

#[Fillable(['name', 'email', 'password', 'is_admin', 'locale', 'deactivated_at', 'digest_enabled', 'reminders_enabled', 'celebrations_enabled', 'absent_from', 'absent_until'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['digest_enabled' => true, 'reminders_enabled' => true, 'celebrations_enabled' => true];

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
            'reminders_enabled' => 'boolean',
            'celebrations_enabled' => 'boolean',
            'avatar_updated_at' => 'datetime',
            'absent_from' => 'date',
            'absent_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->locale ??= config('app.locale');
        });
    }

    /**
     * The link for a new password, in the person's language.
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }

    /**
     * The confirmation of a new address goes to that address, the notice about a change to the previous one.
     */
    public function routeNotificationForMail(Notification $notification): string
    {
        return match (true) {
            $notification instanceof VerifyNewEmail => (string) $this->pending_email,
            $notification instanceof EmailChanged => $notification->previousEmail,
            default => $this->email,
        };
    }

    /**
     * Keeps the new address aside and sends it a confirmation link; until then everything goes to the current one.
     */
    public function requestEmailChange(string $email): void
    {
        $this->forceFill(['pending_email' => $email])->save();
        $this->notify(new VerifyNewEmail);
    }

    /**
     * Takes over the confirmed address and tells the previous one.
     */
    public function confirmPendingEmail(): void
    {
        $previous = $this->email;

        $this->forceFill(['email' => $this->pending_email, 'pending_email' => null, 'email_verified_at' => now()])->save();
        $this->notify(new EmailChanged($previous));
    }

    /**
     * Mails and notifications to this person go out in their language.
     */
    public function preferredLocale(): string
    {
        return LocaleService::isSupported($this->locale) ? $this->locale : config('app.locale');
    }

    /**
     * People who can still sign in and be assigned work.
     *
     * @param  Builder<static>  $query
     */
    protected function scopeActive(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * The one planned absence from the profile, both days included. While it lasts no emails go out to this person
     * (the inbox still collects everything) and the interface shows "away until …" with a faded avatar.
     */
    public function isAbsent(): bool
    {
        $today = today()->toDateString();

        return $this->absent_from !== null && $this->absent_until !== null
            && $this->absent_from->toDateString() <= $today && $today <= $this->absent_until->toDateString();
    }

    /**
     * Whether an absence is entered that has not ended yet (it may still lie ahead).
     */
    public function hasPlannedAbsence(): bool
    {
        return $this->absent_until !== null && $this->absent_until->toDateString() >= today()->toDateString();
    }

    /**
     * "away until 2026-10-20" while the absence lasts, otherwise null.
     */
    public function absenceNote(): ?string
    {
        return $this->isAbsent() ? __('away until :date', ['date' => $this->absent_until->isoFormat('L')]) : null;
    }

    /**
     * The name with what tells others about the person: "Anna (deactivated)" or "Anna (away until 2026-10-20)".
     */
    public function labelledName(): string
    {
        $note = $this->isActive() ? $this->absenceNote() : __('deactivated');

        return $note === null ? $this->name : "{$this->name} ({$note})";
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
        $this->forceFill(['deactivated_at' => now()])->save();
        $this->signOutEverywhere();
    }

    /**
     * End all sessions, "stay signed in" cookies and API tokens, except the given session (the person's current one).
     */
    public function signOutEverywhere(?string $exceptSessionId = null): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();
        $this->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $this->getKey())
                ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
                ->delete();
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

    /**
     * @return HasOne<UserAvatar, $this>
     */
    public function avatar(): HasOne
    {
        return $this->hasOne(UserAvatar::class);
    }

    /**
     * Address of the profile picture, versioned so that browsers may keep it; null without one.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_updated_at === null
            ? null
            : route('avatars.show', ['user' => $this->getKey(), 'v' => $this->avatar_updated_at->getTimestamp()]);
    }

    public function setAvatar(string $contents, string $mimeType): void
    {
        $this->avatar()->updateOrCreate([], ['mime_type' => $mimeType, 'data' => base64_encode($contents)]);
        $this->forceFill(['avatar_updated_at' => now()])->save();
    }

    public function removeAvatar(): void
    {
        $this->avatar()->delete();
        $this->forceFill(['avatar_updated_at' => null])->save();
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')->withPivot('role')->withTimestamps();
    }

    /**
     * Projects starred on the projects page, in the person's own order.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function favoriteProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_favorites')->withPivot('position')->orderByPivot('position');
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    /**
     * What others see of this person in "who else is here" (presence channels).
     *
     * @return array{id: int, name: string, initials: string, avatar: string|null}
     */
    public function presenceData(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'initials' => $this->initials(), 'avatar' => $this->avatarUrl()];
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
