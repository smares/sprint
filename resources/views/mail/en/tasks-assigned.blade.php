<x-mail::message>
# Hello {{ $name }},

{{ $who ?? 'Someone' }} assigned {{ $count }} tasks to you:

@foreach ($tasks as $task)
- [{{ $task['title'] }}]({{ route('tasks.show', $task['id']) }}) · {{ $task['project'] }}
@endforeach
@if ($more > 0)

… and {{ $more }} more
@endif

<x-mail::button :url="$url">
Open my tasks
</x-mail::button>

You are receiving this email because these tasks are assigned to you. You can unsubscribe from each of them on its page.
</x-mail::message>
