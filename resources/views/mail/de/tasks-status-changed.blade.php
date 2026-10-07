<x-mail::message>
# Hallo {{ $name }},

{{ $who ?? 'Jemand' }} hat den Status von {{ $count }} Aufgaben geändert:

@foreach ($changes as $change)
- [{{ $change['title'] }}]({{ route('tasks.show', $change['id']) }}) · {{ $change['project'] }} · **{{ $change['from'] }}** → **{{ $change['to'] }}**
@endforeach
@if ($more > 0)

… und {{ $more }} weitere
@endif

<x-mail::button :url="$url">
Meine Aufgaben öffnen
</x-mail::button>

Du bekommst diese Mail, weil du bei diesen Aufgaben zuständig oder beteiligt bist. Auf der Seite einer Aufgabe kannst du sie einzeln abbestellen.
</x-mail::message>
