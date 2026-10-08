<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * The link from the mail to a new address. It is signed and expires; it only works for the address it was
 * sent to, so asking for another change makes older links useless. No login needed: it may be opened on a phone.
 */
class ConfirmEmailController extends Controller
{
    public function __invoke(User $user, string $hash): RedirectResponse
    {
        $target = auth()->id() === $user->id ? 'profile' : 'login';
        $pending = $user->pending_email;

        if ($pending === null || ! hash_equals(sha1($pending), $hash)) {
            return to_route($target)->with('warning', __('This link is no longer valid. Change the address in your profile again to get a new one.'));
        }

        if (User::query()->where('email', $pending)->whereKeyNot($user->getKey())->exists()) {
            $user->forceFill(['pending_email' => null])->save();

            return to_route($target)->with('warning', __('The address :email is used by another account in the meantime.', ['email' => $pending]));
        }

        $user->confirmPendingEmail();

        return to_route($target)->with('status', __('Your email address is now :email.', ['email' => $user->email]));
    }
}
