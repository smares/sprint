<x-mail::message>
# Hello {{ $name }},

A new password was requested for your account. Use the button to set it; the link is valid for {{ $minutes }} minutes.

<x-mail::button :url="$url">
Set a new password
</x-mail::button>

If this was not you, you can ignore this email; your password stays as it is.
</x-mail::message>
