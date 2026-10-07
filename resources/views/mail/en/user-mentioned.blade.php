<x-mail::message>
# Hello {{ $name }},

{{ $who ?? 'Someone' }} mentioned you @if ($where === 'comment')in a comment on the task @else in the description of the task @endif “{{ $title }}” in the project “{{ $project }}”:

> {{ $excerpt }}

<x-mail::button :url="$url">
Open task
</x-mail::button>

You are receiving this email because someone mentioned you with @. [Unsubscribe from this task]({{ $unsubscribeUrl }})
</x-mail::message>
