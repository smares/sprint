<x-mail::message>
# Hello {{ $name }},

{{ $who ?? 'Someone' }} changed the status of the task “{{ $title }}” in the project “{{ $project }}”:

**{{ $old }}** → **{{ $new }}**

<x-mail::button :url="$url">
Open task
</x-mail::button>

You are receiving this email because you are assigned to or involved in this task. [Unsubscribe from this task]({{ $unsubscribeUrl }})
</x-mail::message>
