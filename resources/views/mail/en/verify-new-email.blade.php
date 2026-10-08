<x-mail::message>
# Hello {{ $name }},

you want to change your email address in {{ config('app.name') }} to **{{ $email }}**. Please confirm that this address is yours; the link is valid for {{ $minutes }} minutes.

<x-mail::button :url="$url">
Confirm address
</x-mail::button>

Until then, your previous address stays in use. If this was not you, you can ignore this email.
</x-mail::message>
