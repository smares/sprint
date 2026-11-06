<x-mail::message>
# Hello {{ $name }},

{{ $who ?? 'Someone' }} {{ $completed ? 'completed' : 'reopened' }} {{ $count }} tasks:

@foreach ($tasks as $task)
- [{{ $task['title'] }}]({{ route('tasks.show', $task['id']) }}) · {{ $task['project'] }}
@endforeach
@if ($more > 0)

… and {{ $more }} more
@endif

<x-mail::button :url="$url">
Open my tasks
</x-mail::button>

You are receiving this email because you are assigned to or involved in these tasks. On the page of a task you can unsubscribe from it individually.
</x-mail::message>
