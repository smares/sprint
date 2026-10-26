<x-mail::message>
# Hello {{ $name }},

{{ $who ?? 'Someone' }} assigned the task “{{ $title }}” in the project “{{ $project }}” to you.

<x-mail::button :url="$url">
Open task
</x-mail::button>

You are receiving this email because you are assigned to this task. [Unsubscribe from this task]({{ $unsubscribeUrl }})
</x-mail::message>
