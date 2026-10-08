@props(['text' => ''])

<div {{ $attributes->class('markdown') }}>{{ \App\Services\Markdown::render($text) }}</div>
