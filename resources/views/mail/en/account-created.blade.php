<x-mail::message>
# Hello {{ $name }},

@if ($createdBy)
{{ $createdBy }} has added you as a user of {{ config('app.name') }}.
@else
An account was created for you in {{ config('app.name') }}.
@endif

You sign in with this email address: **{{ $email }}**. You get the password from the person who added you; on the login page you can also have a new one sent to you by email.

<x-mail::button :url="$url">
Go to sign in
</x-mail::button>
</x-mail::message>
