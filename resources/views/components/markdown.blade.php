@props(['text' => ''])

<div {{ $attributes->class('markdown') }}>{{ \App\Services\MarkdownService::render($text) }}</div>
