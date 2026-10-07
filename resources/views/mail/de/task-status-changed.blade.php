<x-mail::message>
# Hallo {{ $name }},

{{ $who ?? 'Jemand' }} hat den Status der Aufgabe „{{ $title }}“ im Projekt „{{ $project }}“ geändert:

**{{ $old }}** → **{{ $new }}**

<x-mail::button :url="$url">
Aufgabe öffnen
</x-mail::button>

Du bekommst diese Mail, weil du zuständig oder beteiligt bist. [Für diese Aufgabe abbestellen]({{ $unsubscribeUrl }})
</x-mail::message>
