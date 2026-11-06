<x-mail::message>
# Hallo {{ $name }},

{{ $who ?? 'Jemand' }} hat die Aufgabe „{{ $title }}“ im Projekt „{{ $project }}“ {{ $completed ? 'erledigt' : 'wieder geöffnet' }}.

<x-mail::button :url="$url">
Aufgabe öffnen
</x-mail::button>

Du bekommst diese Mail, weil du zuständig oder beteiligt bist. [Für diese Aufgabe abbestellen]({{ $unsubscribeUrl }})
</x-mail::message>
