@props(['text' => ''])

<div {{ $attributes->class('markdown') }}>{{ \App\Markdown::render($text) }}</div>
