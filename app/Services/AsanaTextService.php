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
    /** Whether a list that stands in a list belongs to the item before it; off only in the converter of withoutSubLists(). */
    private bool $subLists = true;

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
            return trim($this->literal($plain));
        }

        $root = $this->parse($html);

        if (! $root instanceof DOMElement) {
            return trim($this->literal($plain));
        }

        $markdown = $this->render($root, $mention);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    /**
     * The lists that stand in a text (not in a quote or in code), in the order of the text: each as imports made it before sub-lists
     * were kept (`old`) and as it is made now (`new`), and whether the old form has mentions (which read differently when a task or
     * person was renamed since). The two forms are equal for a list without sub-lists. A text that was imported earlier is repaired by
     * the lists alone, so it does not matter what was done to the rest of it since.
     *
     * @param  Closure(string): string  $mention
     * @return list<array{old: string, new: string, mentions: bool}>
     */
    public function listForms(?string $html, Closure $mention): array
    {
        $root = $this->parse(trim((string) $html));

        if (! $root instanceof DOMElement) {
            return [];
        }

        $legacy = $this->withoutSubLists();
        $forms = [];

        foreach ($this->topLevelLists($root) as $list) {
            $mentioned = false;
            $old = trim($legacy->list($list, function (string $gid) use ($mention, &$mentioned): string {
                $mentioned = true;

                return $mention($gid);
            }), "\n");
            $forms[] = ['old' => $old, 'new' => trim($this->list($list, $mention), "\n"), 'mentions' => $mentioned];
        }

        return $forms;
    }

    /**
     * The lists that are not part of another list, at any depth of the other elements; text in code and quotes is left out.
     *
     * @return list<DOMElement>
     */
    private function topLevelLists(DOMNode $node): array
    {
        $found = [];

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, ['ul', 'ol'], true)) {
                $found[] = $child;
            } elseif (! in_array($name, ['pre', 'code', 'blockquote'], true)) {
                $found = [...$found, ...$this->topLevelLists($child)];
            }
        }

        return $found;
    }

    /**
     * The root element of Asana's rich text; null when there is none or it is not well-formed XML.
     */
    private function parse(string $html): ?DOMElement
    {
        if ($html === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML('<?xml version="1.0" encoding="UTF-8"?>'.(str_starts_with($html, '<body') ? $html : "<body>{$html}</body>"), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && $document->documentElement instanceof DOMElement ? $document->documentElement : null;
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
            return $this->text($this->literal($node->nodeValue ?? ''));
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
     * Text from Asana as it was typed: characters that Markdown would take for formatting (or HTML, which is dropped)
     * are escaped, so "*note*", "# not a heading" or "<div>" show up as written.
     */
    private function literal(string $text): string
    {
        $text = (string) preg_replace('/[<*`\[\]~]/', '\\\\$0', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $text);
        $text = (string) preg_replace('/^(\s*)(#{1,6}(?=\s)|>|[-+](?=\s))/m', '$1\\\\$2', $text);

        return (string) preg_replace('/^(\s*\d+)([.)])(?=\s)/m', '$1\\\\$2', $text);
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
     * A list; one that Asana writes as a list of its own right inside the list (after the item it belongs to) is that item's sub-list.
     *
     * @param  Closure(string): string  $mention
     */
    private function list(DOMElement $list, Closure $mention): string
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $lines = [];
        $number = 0;
        $indent = 0;

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $name = strtolower($item->nodeName);

            if ($name === 'ul' || $name === 'ol') {
                if (! $this->subLists) {
                    continue;
                }

                $nested = trim($this->list($item, $mention));

                if ($nested === '') {
                    continue;
                }

                if ($lines === []) {
                    $lines[] = $nested;
                } else {
                    $lines[array_key_last($lines)] .= "\n".str_repeat(' ', $indent).str_replace("\n", "\n".str_repeat(' ', $indent), $nested);
                }

                continue;
            }

            if ($name !== 'li') {
                continue;
            }

            $marker = $ordered ? (++$number).'. ' : '- ';
            $indent = strlen($marker);
            $content = (string) preg_replace("/\n{2,}/", "\n", trim($this->children($item, $mention)));
            $lines[] = $marker.str_replace("\n", "\n".str_repeat(' ', $indent), $content);
        }

        return "\n\n".implode("\n", $lines)."\n\n";
    }

    /**
     * A converter that works as the import did before sub-lists were kept, to find what older imports wrote.
     */
    private function withoutSubLists(): static
    {
        $legacy = clone $this;
        $legacy->subLists = false;

        return $legacy;
    }
}
