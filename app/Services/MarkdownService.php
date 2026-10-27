<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders user-written Markdown, including `@[Name](user:1)` / `@[Title](task:2)` mentions and
 * `![Name](attachment:3)` images of attachments, to safe HTML.
 */
class MarkdownService
{
    private const string MENTION_PATTERN = '/@\[([^\]\n]{1,255})\]\((user|task):(\d+)\)/u';

    private const string IMAGE_PATTERN = '/!\[([^\]\n]{0,255})\]\(attachment:(\d+)\)/u';

    private const string PLACEHOLDER_START = "\u{E000}";

    private const string PLACEHOLDER_END = "\u{E001}";

    /** Texts from this length on (in bytes) are kept after converting; raise the version when the converter's settings change. */
    private const int CACHE_FROM_BYTES = 2000;

    private const int CACHE_VERSION = 1;

    private static ?GithubFlavoredMarkdownConverter $converter = null;

    /**
     * Text that is shown as it is inside Markdown (a name or title in a mail): links, emphasis and
     * headings typed into it stay plain characters. HTML is escaped separately by Blade.
     */
    public static function escape(?string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~])/', '\\\\$1', (string) $text);
    }

    /**
     * @param  bool  $cache  Whether a long text may be kept by its content; a preview is typed anew each time, so it is not
     */
    public static function render(?string $text, bool $cache = true): HtmlString
    {
        $text = trim((string) $text);

        if ($text === '') {
            return new HtmlString('');
        }

        // The placeholders are private-use characters; typed into a text they would point at mentions that do not exist
        $text = str_replace([self::PLACEHOLDER_START, self::PLACEHOLDER_END], '', $text);
        $mentions = [];

        $withPlaceholders = preg_replace_callback(self::MENTION_PATTERN, function (array $match) use (&$mentions): string {
            $mentions[] = ['name' => $match[1], 'type' => $match[2], 'id' => (int) $match[3]];

            return self::PLACEHOLDER_START.(count($mentions) - 1).self::PLACEHOLDER_END;
        }, $text);

        $withPlaceholders = preg_replace_callback(self::IMAGE_PATTERN, function (array $match) use (&$mentions): string {
            $mentions[] = ['name' => $match[1], 'type' => 'image', 'id' => (int) $match[2]];

            return self::PLACEHOLDER_START.(count($mentions) - 1).self::PLACEHOLDER_END;
        }, $withPlaceholders);

        $html = self::linkedTasksAsMentions(self::withoutPlaceholdersInTags(self::convert($withPlaceholders, $cache)), $mentions);

        return new HtmlString(self::insertMentions($html, $mentions));
    }

    /**
     * The HTML for a text (mentions still as placeholders). Long texts, up to 65,000 characters now, take noticeable time
     * to convert and the task page renders them on every request, so those are kept for a day, by their content.
     */
    private static function convert(string $text, bool $cache): string
    {
        if (! $cache || strlen($text) < self::CACHE_FROM_BYTES) {
            return (string) self::converter()->convert($text);
        }

        return Cache::remember('markdown:'.self::CACHE_VERSION.':'.hash('xxh128', $text), now()->addDay(), fn (): string => (string) self::converter()->convert($text));
    }

    /**
     * Setting up CommonMark is the expensive part, so one converter serves all texts of a request.
     */
    private static function converter(): GithubFlavoredMarkdownConverter
    {
        return self::$converter ??= tap(new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
                'open_in_new_window' => true,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]), fn (GithubFlavoredMarkdownConverter $converter) => $converter->getEnvironment()->addExtension(new ExternalLinkExtension));
    }

    /**
     * The id of the task an address of this installation leads to: the page of the task (/tasks/7) or any page that opens it in
     * the flyout (?task=7, as the address bar of a list shows it). Null for every other address.
     */
    public static function taskIdFromUrl(string $url): ?int
    {
        $parts = parse_url(trim($url));

        if (! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        // The address of this installation: host and port (an instance on the same host with another port is another one)
        $ours = collect([(string) config('app.url'), url('/')])->map(fn (string $own) => parse_url($own))->contains(
            fn ($own) => is_array($own) && isset($own['host']) && strtolower($own['host']) === strtolower($parts['host']) && ($own['port'] ?? null) === ($parts['port'] ?? null),
        );

        if (! $ours) {
            return null;
        }

        if (preg_match('#^/tasks/(\\d{1,18})/?$#', $parts['path'] ?? '', $match)) {
            return (int) $match[1];
        }

        parse_str($parts['query'] ?? '', $query);

        return isset($query['task']) && is_string($query['task']) && preg_match('/^\\d{1,18}$/', $query['task']) ? (int) $query['task'] : null;
    }

    /**
     * A placeholder written as an HTML entity (&#xE000;) is decoded by the converter, also inside an attribute such as the title of a
     * link; it would be replaced there by markup with quotes, which breaks out of the attribute. Placeholders belong in the text only.
     */
    private static function withoutPlaceholdersInTags(string $html): string
    {
        return (string) preg_replace_callback('/<[^>]*>/', fn (array $tag): string => str_replace([self::PLACEHOLDER_START, self::PLACEHOLDER_END], '', $tag[0]), $html);
    }

    /**
     * A link whose text is the address itself (a pasted address, `<…>`, `[…](…)` with the same address twice) to a task of this
     * installation becomes a mention of the task, shown with its title like `@[Title](task:7)`. A link with words of its own stays
     * as it is, and so does one to a task that is gone or that the reader may not see (see insertMentions).
     *
     * @param  list<array{name: string, type: string, id: int, url?: string}>  $mentions
     */
    private static function linkedTasksAsMentions(string $html, array &$mentions): string
    {
        if (! str_contains($html, '<a ')) {
            return $html;
        }

        return (string) preg_replace_callback('#<a\s[^>]*?href="([^"]*)"[^>]*>([^<]*)</a>#', function (array $match) use (&$mentions): string {
            $url = html_entity_decode($match[1]);
            $id = $url === html_entity_decode($match[2]) ? self::taskIdFromUrl($url) : null;

            if ($id === null) {
                return $match[0];
            }

            $mentions[] = ['name' => '', 'type' => 'task', 'id' => $id, 'url' => $url];

            return self::PLACEHOLDER_START.(count($mentions) - 1).self::PLACEHOLDER_END;
        }, $html);
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
        $text = (string) preg_replace(self::IMAGE_PATTERN, '[$1]', (string) $text);

        return (string) preg_replace(self::MENTION_PATTERN, '@$1', $text);
    }

    /**
     * @param  list<array{name: string, type: string, id: int, url?: string}>  $mentions
     */
    private static function insertMentions(string $html, array $mentions): string
    {
        if ($mentions === []) {
            return $html;
        }

        $users = User::whereIn('id', collect($mentions)->where('type', 'user')->pluck('id'))->pluck('name', 'id');
        $tasks = Task::with('project')
            ->whereIn('id', collect($mentions)->where('type', 'task')->pluck('id'))
            ->get()
            ->filter(fn (Task $task) => Gate::allows('view', $task->project))
            ->pluck('title', 'id');
        $images = Attachment::with('task.project')
            ->whereIn('id', collect($mentions)->where('type', 'image')->pluck('id'))
            ->get()
            ->filter(fn (Attachment $attachment) => $attachment->previewKind() === 'image' && Gate::allows('view', $attachment->task->project))
            ->keyBy('id');

        return (string) preg_replace_callback(
            '/'.self::PLACEHOLDER_START.'(\d+)'.self::PLACEHOLDER_END.'/u',
            function (array $match) use ($mentions, $users, $tasks, $images): string {
                // A placeholder written as an HTML entity (&#xE000;) is decoded by the converter after the strip above
                $mention = $mentions[(int) $match[1]] ?? null;

                if ($mention === null) {
                    return '';
                }

                if ($mention['type'] === 'image') {
                    $image = $images->get($mention['id']);

                    return $image === null
                        ? '<span class="mention mention-missing">'.e(__('Image removed')).'</span>'
                        : '<img class="attachment-image" src="'.e(route('attachments.show', [$image, 'inline' => 1])).'" alt="'.e($image->name).'" loading="lazy" data-preview-url="'.e(route('attachments.show', [$image, 'inline' => 1])).'" data-download-url="'.e(route('attachments.show', $image)).'" data-name="'.e($image->name).'">';
                }

                if ($mention['type'] === 'user') {
                    $name = $users->get($mention['id']);

                    return $name === null
                        ? '<span class="mention mention-missing">@'.e($mention['name']).'</span>'
                        : '<span class="mention mention-user">@'.e($name).'</span>';
                }

                $title = $tasks->get($mention['id']);

                if ($title === null && isset($mention['url'])) {
                    return '<a href="'.e($mention['url']).'">'.e($mention['url']).'</a>';
                }

                return $title === null
                    ? '<span class="mention mention-missing">'.e($mention['name']).'</span>'
                    : '<a class="mention mention-task" href="'.e(route('tasks.show', $mention['id'])).'">'.e($title).'</a>';
            },
            $html,
        );
    }
}
