<x-mail::message>
# Hallo {{ $name }},

{{ $who ?? 'Jemand' }} hat dich @if ($where === 'comment')in einem Kommentar zur Aufgabe @else in der Beschreibung der Aufgabe @endif „{{ $title }}“ im Projekt „{{ $project }}“ erwähnt:

> {{ $excerpt }}

<x-mail::button :url="$url">
Aufgabe öffnen
</x-mail::button>

Du bekommst diese Mail, weil dich jemand mit @ erwähnt hat. [Für diese Aufgabe abbestellen]({{ $unsubscribeUrl }})
</x-mail::message>
