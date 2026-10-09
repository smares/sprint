<x-mail::message>
# Hello {{ $name }},

The email address of your account in {{ config('app.name') }} has been changed to **{{ $email }}**. Notifications now go there, and you sign in with the new address.

If this was not you, please contact an administrator right away.
</x-mail::message>
