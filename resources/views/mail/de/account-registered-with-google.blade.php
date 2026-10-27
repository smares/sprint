<x-mail::message>
# Hallo {{ $name }},

{{ $registeredName }} ({{ $registeredEmail }}) hat sich soeben mit einem Google-Konto in {{ config('app.name') }} registriert.

Das neue Konto ist kein Administrator und sieht noch kein Projekt. Bitte schau kurz hinein und vergib die nötigen Rechte und Projekte.

<x-mail::button :url="$url">
Zu den Personen
</x-mail::button>
</x-mail::message>
