<x-mail::message>
# Good morning {{ $name }},

@foreach (['overdue' => 'Overdue', 'today' => 'Due today', 'upcoming' => 'Coming up'] as $key => $heading)
@if ($tasks[$key]->isNotEmpty())
**{{ $heading }}**

@foreach ($tasks[$key]->take($limit) as $task)
- [{{ \App\Services\MarkdownService::escape($task->title) }}]({{ route('tasks.show', $task) }}) · {{ \App\Services\MarkdownService::escape($task->project->name) }} · {{ $task->due_date->isoFormat('L') }}
@endforeach
@if ($tasks[$key]->count() > $limit)
- … and {{ $tasks[$key]->count() - $limit }} more
@endif

@endif
@endforeach
<x-mail::button :url="$url">
Open my tasks
</x-mail::button>

You receive this summary on weekdays when you are assigned to or involved in tasks. You can turn it off in your profile.
</x-mail::message>
