<x-mail::message>
# Hallo {{ $name }},

für dein Konto wurde ein neues Passwort angefordert. Über die Schaltfläche legst du es fest; der Link gilt {{ $minutes }} Minuten.

<x-mail::button :url="$url">
Neues Passwort festlegen
</x-mail::button>

Wenn du das nicht warst, kannst du diese Mail ignorieren; dein Passwort bleibt dann unverändert.
</x-mail::message>
