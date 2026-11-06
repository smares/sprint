<x-mail::message>
# Hallo {{ $name }},

{{ $who ?? 'Jemand' }} hat {{ $count }} Aufgaben {{ $completed ? 'erledigt' : 'wieder geöffnet' }}:

@foreach ($tasks as $task)
- [{{ $task['title'] }}]({{ route('tasks.show', $task['id']) }}) · {{ $task['project'] }}
@endforeach
@if ($more > 0)

… und {{ $more }} weitere
@endif

<x-mail::button :url="$url">
Meine Aufgaben öffnen
</x-mail::button>

Du bekommst diese Mail, weil du bei diesen Aufgaben zuständig oder beteiligt bist. Auf der Seite einer Aufgabe kannst du sie einzeln abbestellen.
</x-mail::message>
