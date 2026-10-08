<x-mail::message>
# Hallo {{ $name }},

die E-Mail-Adresse deines Kontos in {{ config('app.name') }} wurde auf **{{ $email }}** geändert. Benachrichtigungen gehen ab jetzt dorthin, und du meldest dich mit der neuen Adresse an.

Wenn du das nicht warst, wende dich bitte sofort an einen Administrator.
</x-mail::message>
