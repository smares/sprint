<?php

namespace App\Services;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Asana's rich text (a small XML dialect: <strong>, <em>, <a>, lists, headings, code …) turned into Markdown, and the
 * links to other Asana tasks in it turned into mentions of the tasks that were imported.
 */
class AsanaTextService
{
    /** Task addresses: /0/<project>/<task>, /0/<project>/<task>/f and /1/<workspace>/project/<project>/task/<task>. */
    private const string TASK_URL = 'https://app\.asana\.com/(?:0/\w+/(\d+)(?:/f)?|1/\d+/(?:[a-z_]+/\d+/)*task/(\d+)(?:/[a-z]+)?)';

    /**
     * Markdown for a description or comment. Asana's `notes` (plain text) stand in when the rich text is missing or is
     * not well-formed XML.
     *
     * @param  Closure(string): string  $mention  Text for a mention of the Asana user with this id.
     */
    public function toMarkdown(?string $html, string $plain, Closure $mention): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return trim($plain);
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML('<?xml version="1.0" encoding="UTF-8"?>'.(str_starts_with($html, '<body') ? $html : "<body>{$html}</body>"), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            return trim($plain);
        }

        $markdown = $this->render($document->documentElement, $mention);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    /**
     * Links to Asana tasks that were imported become mentions (`@[Title](task:12)`); others stay as they are.
     *
     * @param  Closure(string): ?array{id: int, title: string}  $resolve  The imported task for an Asana task id, if any.
     */
    public function linkTasks(string $markdown, Closure $resolve): string
    {
        $linked = preg_replace_callback('~\[([^\]\n]*)\]\((<?)('.self::TASK_URL.')>?\)~', function (array $match) use ($resolve): string {
            $task = $resolve($match[4] !== '' ? $match[4] : $match[5]);

            return $task === null ? $match[0] : $this->mention($match[1] !== '' && $match[1] !== $match[3] ? $match[1] : $task['title'], $task['id']);
        }, $markdown);

        return (string) preg_replace_callback('~(?<![(<\w])'.self::TASK_URL.'~', function (array $match) use ($resolve): string {
            $task = $resolve($match[1] !== '' ? $match[1] : $match[2]);

            return $task === null ? $match[0] : $this->mention($task['title'], $task['id']);
        }, (string) $linked);
    }

    /**
     * Whether the text has anything that linkTasks() could replace.
     */
    public function hasTaskLinks(string $markdown): bool
    {
        return preg_match('~'.self::TASK_URL.'~', $markdown) === 1;
    }

    private function mention(string $title, int $taskId): string
    {
        return '@['.str_replace([']', "\n"], ['', ' '], mb_substr($title, 0, 255)).'](task:'.$taskId.')';
    }

    /**
     * @param  Closure(string): string  $mention
     */
    private function render(DOMNode $node, Closure $mention): string
    {
        if ($node instanceof DOMText) {
            return $this->text($node->nodeValue ?? '');
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $inner = fn (): string => $this->children($node, $mention);

        return match (strtolower($node->nodeName)) {
            'strong', 'b' => $this->wrap($inner(), '**'),
            'em', 'i' => $this->wrap($inner(), '*'),
            's', 'strike', 'del' => $this->wrap($inner(), '~~'),
            'code' => $this->wrap($node->textContent, str_contains($node->textContent, '`') ? '``' : '`'),
            'pre' => "\n\n```\n".trim($node->textContent, "\n")."\n```\n\n",
            'a' => $this->anchor($node, $inner(), $mention),
            'h1' => "\n\n# ".trim($inner())."\n\n",
            'h2' => "\n\n## ".trim($inner())."\n\n",
            'h3', 'h4', 'h5', 'h6' => "\n\n### ".trim($inner())."\n\n",
            'ul', 'ol' => $this->list($node, $mention),
            'blockquote' => "\n\n".preg_replace('/^/m', '> ', trim($inner()))."\n\n",
            'hr' => "\n\n---\n\n",
            'br' => "  \n",
            'img' => '',
            default => $inner(),
        };
    }

    /**
     * @param  Closure(string): string  $mention
     */
    private function children(DOMNode $node, Closure $mention): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= $this->render($child, $mention);
        }

        return $text;
    }

    /**
     * A line break inside a paragraph stays one, an empty line stays a paragraph break.
     */
    private function text(string $text): string
    {
        return (string) preg_replace('/(?<!\n)\n(?!\n)/', "  \n", (string) preg_replace("/\n{2,}/", "\n\n", $text));
    }

    /**
     * The marker around text, kept next to the words (spaces stay outside, as Markdown needs).
     */
    private function wrap(string $text, string $marker): string
    {
        if (trim($text) === '') {
            return $text;
        }

        preg_match('/^(\s*)(.*?)(\s*)$/s', $text, $parts);

        return $parts[1].$marker.$parts[2].$marker.$parts[3];
    }

    /**
     * @param  Closure(string): string  $mention
     */
    private function anchor(DOMElement $node, string $label, Closure $mention): string
    {
        $href = trim($node->getAttribute('href'));
        $gid = $node->getAttribute('data-asana-gid');
        $type = $node->getAttribute('data-asana-type');
        $label = trim($label);

        if ($gid !== '' && ($type === 'user' || ($type === '' && $href === '' && $label === ''))) {
            return $mention($gid);
        }

        if ($href === '' && $gid !== '' && $type === 'task') {
            $href = 'https://app.asana.com/0/0/'.$gid;
        }

        if ($href === '') {
            return $label;
        }

        return '['.($label !== '' ? $label : $href).']('.strtr($href, [' ' => '%20', '(' => '%28', ')' => '%29']).')';
    }

    /**
     * @param  Closure(string): string  $mention
     */
    private function list(DOMElement $list, Closure $mention): string
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $lines = [];
        $number = 0;

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $marker = $ordered ? (++$number).'. ' : '- ';
            $content = (string) preg_replace("/\n{2,}/", "\n", trim($this->children($item, $mention)));
            $lines[] = $marker.str_replace("\n", "\n".str_repeat(' ', strlen($marker)), $content);
        }

        return "\n\n".implode("\n", $lines)."\n\n";
    }
}
