<x-mail::message>
# Guten Morgen {{ $name }},

@foreach (['overdue' => 'Überfällig', 'today' => 'Heute fällig', 'upcoming' => 'In den nächsten Tagen'] as $key => $heading)
@if ($tasks[$key]->isNotEmpty())
**{{ $heading }}**

@foreach ($tasks[$key]->take($limit) as $task)
- [{{ $task->title }}]({{ route('tasks.show', $task) }}) · {{ $task->project->name }} · {{ $task->due_date->isoFormat('L') }}
@endforeach
@if ($tasks[$key]->count() > $limit)
- … und {{ $tasks[$key]->count() - $limit }} weitere
@endif

@endif
@endforeach
<x-mail::button :url="$url">
Meine Aufgaben öffnen
</x-mail::button>

Du bekommst diese Zusammenfassung werktags, wenn du zuständig oder beteiligt bist. Du kannst sie in deinem Profil abstellen.
</x-mail::message>
