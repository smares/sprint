@php
    $parts = array_filter([
        $tasks['overdue']->isNotEmpty() ? $tasks['overdue']->count().' overdue' : null,
        $tasks['today']->isNotEmpty() ? $tasks['today']->count().' due today' : null,
        $tasks['upcoming']->isNotEmpty() ? $tasks['upcoming']->count().' coming up' : null,
    ]);
@endphp
Your tasks: {{ implode(', ', $parts) }}
