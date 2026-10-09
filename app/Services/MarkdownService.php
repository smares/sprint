<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
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

    private static ?GithubFlavoredMarkdownConverter $converter = null;

    /**
     * Text that is shown as it is inside Markdown (a name or title in a mail): links, emphasis and
     * headings typed into it stay plain characters. HTML is escaped separately by Blade.
     */
    public static function escape(?string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~])/', '\\\\$1', (string) $text);
    }

    public static function render(?string $text): HtmlString
    {
        $text = trim((string) $text);

        if ($text === '') {
            return new HtmlString('');
        }

        $mentions = [];

        $withPlaceholders = preg_replace_callback(self::MENTION_PATTERN, function (array $match) use (&$mentions): string {
            $mentions[] = ['name' => $match[1], 'type' => $match[2], 'id' => (int) $match[3]];

            return self::PLACEHOLDER_START.(count($mentions) - 1).self::PLACEHOLDER_END;
        }, $text);

        $withPlaceholders = preg_replace_callback(self::IMAGE_PATTERN, function (array $match) use (&$mentions): string {
            $mentions[] = ['name' => $match[1], 'type' => 'image', 'id' => (int) $match[2]];

            return self::PLACEHOLDER_START.(count($mentions) - 1).self::PLACEHOLDER_END;
        }, $withPlaceholders);

        $html = (string) self::converter()->convert($withPlaceholders);

        return new HtmlString(self::insertMentions($html, $mentions));
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
     * @param  list<array{name: string, type: string, id: int}>  $mentions
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
                $mention = $mentions[(int) $match[1]];

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

                return $title === null
                    ? '<span class="mention mention-missing">'.e($mention['name']).'</span>'
                    : '<a class="mention mention-task" href="'.e(route('tasks.show', $mention['id'])).'">'.e($title).'</a>';
            },
            $html,
        );
    }
}
