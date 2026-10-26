<x-mail::message>
# Hallo {{ $name }},

{{ $who ?? 'Jemand' }} hat dir die Aufgabe „{{ $title }}“ im Projekt „{{ $project }}“ zugewiesen.

<x-mail::button :url="$url">
Aufgabe öffnen
</x-mail::button>

Du bekommst diese Mail, weil dir die Aufgabe zugewiesen wurde. [Für diese Aufgabe abbestellen]({{ $unsubscribeUrl }})
</x-mail::message>
