<?php

namespace App;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

/**
 * Renders user-written Markdown, including `@[Name](user:1)` / `@[Title](task:2)` mentions, to safe HTML.
 */
class Markdown
{
    private const MENTION_PATTERN = '/@\[([^\]\n]{1,255})\]\((user|task):(\d+)\)/u';

    private const PLACEHOLDER_START = "\u{E000}";

    private const PLACEHOLDER_END = "\u{E001}";

    public static function render(?string $text): HtmlString
    {
        $text = trim((string) $text);

        if ($text === '') {
            return new HtmlString('');
        }

        $mentions = [];

        $withPlaceholders = preg_replace_callback(self::MENTION_PATTERN, function (array $match) use (&$mentions) {
            $mentions[] = ['name' => $match[1], 'type' => $match[2], 'id' => (int) $match[3]];

            return self::PLACEHOLDER_START.(count($mentions) - 1).self::PLACEHOLDER_END;
        }, $text);

        $html = Str::markdown($withPlaceholders, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
                'open_in_new_window' => true,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [new ExternalLinkExtension]);

        return new HtmlString(self::insertMentions($html, $mentions));
    }

    /**
     * Ids of all people mentioned in the text.
     *
     * @return list<int>
     */
    public static function mentionedUserIds(?string $text): array
    {
        preg_match_all(self::MENTION_PATTERN, (string) $text, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->filter(fn (array $match) => $match[2] === 'user')
            ->map(fn (array $match) => (int) $match[3])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Mention tokens replaced by plain `@Name` / `Title` text, e.g. for emails.
     */
    public static function plainText(?string $text): string
    {
        return (string) preg_replace(self::MENTION_PATTERN, '@$1', (string) $text);
    }

    /**
     * @param  list<array{name: string, type: string, id: int}>  $mentions
     */
    private static function insertMentions(string $html, array $mentions): string
    {
        if ($mentions === []) {
            return $html;
        }

        $users = User::whereIn('id', collect($mentions)->where('type', 'user')->pluck('id'))->pluck('name', 'id');
        $tasks = Task::whereIn('id', collect($mentions)->where('type', 'task')->pluck('id'))->pluck('title', 'id');

        return (string) preg_replace_callback(
            '/'.self::PLACEHOLDER_START.'(\d+)'.self::PLACEHOLDER_END.'/u',
            function (array $match) use ($mentions, $users, $tasks) {
                $mention = $mentions[(int) $match[1]];

                if ($mention['type'] === 'user') {
                    $name = $users->get($mention['id']);

                    return $name === null
                        ? '<span class="mention mention-missing">@'.e($mention['name']).'</span>'
                        : '<span class="mention mention-user">@'.e($name).'</span>';
                }

                $title = $tasks->get($mention['id']);

                return $title === null
                    ? '<span class="mention mention-missing">'.e($mention['name']).'</span>'
                    : '<a class="mention mention-task" href="'.e(route('tasks.show', $mention['id'])).'">'.e($title).'</a>';
            },
            $html,
        );
    }
}
