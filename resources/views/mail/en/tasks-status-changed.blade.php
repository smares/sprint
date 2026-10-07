<x-mail::message>
# Hello {{ $name }},

{{ $who ?? 'Someone' }} changed the status of {{ $count }} tasks:

@foreach ($changes as $change)
- [{{ $change['title'] }}]({{ route('tasks.show', $change['id']) }}) · {{ $change['project'] }} · **{{ $change['from'] }}** → **{{ $change['to'] }}**
@endforeach
@if ($more > 0)

… and {{ $more }} more
@endif

<x-mail::button :url="$url">
Open my tasks
</x-mail::button>

You are receiving this email because you are assigned to or involved in these tasks. You can unsubscribe from each of them on its page.
</x-mail::message>
