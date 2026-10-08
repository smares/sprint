<x-mail::message>
# Hallo {{ $name }},

du möchtest deine E-Mail-Adresse in {{ config('app.name') }} auf **{{ $email }}** ändern. Bestätige bitte, dass diese Adresse dir gehört; der Link gilt {{ $minutes }} Minuten.

<x-mail::button :url="$url">
Adresse bestätigen
</x-mail::button>

Bis dahin bleibt deine bisherige Adresse gültig. Wenn du das nicht warst, kannst du diese Mail ignorieren.
</x-mail::message>
