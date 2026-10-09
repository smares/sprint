<x-mail::message>
# Hallo {{ $name }},

@if ($createdBy)
{{ $createdBy }} hat dich als Nutzer in {{ config('app.name') }} angelegt.
@else
Für dich wurde ein Konto in {{ config('app.name') }} angelegt.
@endif

Du meldest dich mit dieser E-Mail-Adresse an: **{{ $email }}**. Das Passwort bekommst du von der Person, die dich angelegt hat; auf der Anmeldeseite kannst du dir auch selbst ein neues per E-Mail schicken lassen.

<x-mail::button :url="$url">
Zur Anmeldung
</x-mail::button>
</x-mail::message>
