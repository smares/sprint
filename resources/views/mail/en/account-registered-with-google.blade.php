<x-mail::message>
# Hello {{ $name }},

{{ $registeredName }} ({{ $registeredEmail }}) has just registered in {{ config('app.name') }} with a Google account.

The new account is not an administrator and sees no project yet. Please have a look and give them the rights and projects they need.

<x-mail::button :url="$url">
Open the users
</x-mail::button>
</x-mail::message>
