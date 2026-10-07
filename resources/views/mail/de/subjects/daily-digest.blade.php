@php
    $parts = array_filter([
        $tasks['overdue']->isNotEmpty() ? $tasks['overdue']->count().' überfällig' : null,
        $tasks['today']->isNotEmpty() ? $tasks['today']->count().' heute fällig' : null,
        $tasks['upcoming']->isNotEmpty() ? $tasks['upcoming']->count().' demnächst' : null,
    ]);
@endphp
Deine Aufgaben: {{ implode(', ', $parts) }}
