<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;

/**
 * Profile pictures for signed-in people. The URL carries a version, so browsers may keep the
 * picture for good; like attachments, it is delivered in a sandbox.
 */
class AvatarController extends Controller
{
    public function __invoke(User $user): Response
    {
        $avatar = $user->avatar()->firstOrFail();

        return response($avatar->contents(), 200, [
            'Content-Type' => $avatar->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
