<?php

namespace Tests\Unit;

use App\Services\AsanaTextService;
use PHPUnit\Framework\TestCase;

class AsanaTextServiceTest extends TestCase
{
    private function markdown(string $html, string $plain = 'plain'): string
    {
        return (new AsanaTextService)->toMarkdown($html, $plain, fn (string $gid) => "@[Person {$gid}](user:{$gid})");
    }

    public function test_emphasis_links_and_code_become_markdown(): void
    {
        $this->assertSame(
            'Das ist **wichtig** und *schräg*, ~~alt~~, `code` und [Asana](https://asana.com).',
            $this->markdown('<body>Das ist <strong>wichtig</strong> und <em>schräg</em>, <s>alt</s>, <code>code</code> und <a href="https://asana.com">Asana</a>.</body>'),
        );
    }

    public function test_spaces_stay_outside_the_emphasis_markers(): void
    {
        $this->assertSame('ein **Wort** hier', $this->markdown('<body>ein<strong> Wort </strong>hier</body>'));
    }

    public function test_lists_headings_quotes_and_code_blocks_keep_their_structure(): void
    {
        $html = '<body><h1>Titel</h1>Text davor<ul><li>eins<ul><li>innen</li></ul></li><li>zwei</li></ul><ol><li>a</li><li>b</li></ol><blockquote>zitiert</blockquote><pre>x = 1</pre></body>';

        $this->assertSame(
            "# Titel\n\nText davor\n\n- eins\n  - innen\n- zwei\n\n1. a\n2. b\n\n> zitiert\n\n```\nx = 1\n```",
            $this->markdown($html),
        );
    }

    public function test_a_sub_list_that_asana_writes_as_a_list_in_the_list_stays_with_its_item(): void
    {
        $html = '<body>Folgende Fehler gibt es:<ul><li>500</li><ul><li><a href="https://example.com/a">https://example.com/a</a></li></ul><li>Timeout</li><ul><li>eins</li><li>zwei</li><ul><li>tiefer</li></ul></ul></ul></body>';

        $this->assertSame(
            "Folgende Fehler gibt es:\n\n- 500\n  - [https://example.com/a](https://example.com/a)\n- Timeout\n  - eins\n  - zwei\n    - tiefer",
            $this->markdown($html),
        );
    }

    public function test_a_sub_list_under_a_numbered_item_is_indented_by_its_number_and_a_leading_one_stands_alone(): void
    {
        $this->assertSame("1. a\n   - x\n2. b", $this->markdown('<body><ol><li>a</li><ul><li>x</li></ul><li>b</li></ol></body>'));
        $this->assertSame("- x\n- a", $this->markdown('<body><ul><ul><li>x</li></ul><li>a</li></ul></body>'));
    }

    public function test_only_the_lists_with_a_sub_list_that_asana_writes_in_the_list_are_given_for_the_repair(): void
    {
        $service = new AsanaTextService;
        $mention = fn (string $gid): string => '@'.$gid;

        $this->assertSame(
            [['old' => "1. eins\n2. zwei", 'new' => "1. eins\n   - x\n2. zwei"]],
            $service->subListRepairs('<body>Text<ul><li>a</li></ul>Mitte<ol><li>eins</li><ul><li>x</li></ul><li>zwei</li></ol>Ende</body>', $mention),
        );
        $this->assertSame(
            [['old' => '- a', 'new' => "- a\n  - b\n    - c"], ['old' => '- d', 'new' => "- d\n  - e"]],
            $service->subListRepairs('<body><ul><li>a</li><ul><li>b</li><ul><li>c</li></ul></ul></ul><ul><li>d</li><ul><li>e</li></ul></ul></body>', $mention),
        );

        // A list that has its sub-list in the item (valid) was never lost, and there is nothing to repair without rich text
        $this->assertSame([], $service->subListRepairs('<body><ul><li>a<ul><li>b</li></ul></li></ul></body>', $mention));
        $this->assertSame([], $service->subListRepairs(null, $mention));
        $this->assertSame([], $service->subListRepairs('<body>kein <b>XML</body>', $mention));
    }

    public function test_line_breaks_inside_a_paragraph_stay_and_empty_lines_stay_paragraphs(): void
    {
        $this->assertSame("erste  \nzweite\n\ndritte", $this->markdown("<body>erste\nzweite\n\n\ndritte</body>"));
    }

    public function test_mentions_of_people_use_the_given_text_and_tasks_link_to_asana(): void
    {
        $html = '<body>Hallo <A data-asana-gid="42" data-asana-type="user"/> siehe <A data-asana-gid="7" data-asana-type="task">Aufgabe</A></body>';

        $this->assertSame('Hallo @[Person 42](user:42) siehe [Aufgabe](https://app.asana.com/0/0/7)', $this->markdown($html));
    }

    public function test_the_plain_text_stands_in_when_the_rich_text_is_missing_or_broken(): void
    {
        $this->assertSame('nur Text', $this->markdown('', "  nur Text \n"));
        $this->assertSame('Klartext', $this->markdown('<body>a&nbsp;b <strong>offen</body>', 'Klartext'));
    }

    public function test_images_and_unknown_markup_do_not_leave_traces(): void
    {
        $this->assertSame('vor nach', $this->markdown('<body>vor <img src="https://x.test/a.png"/><u>nach</u></body>'));
    }

    public function test_links_to_imported_tasks_become_mentions_and_others_stay(): void
    {
        $known = ['100' => ['id' => 5, 'title' => 'Konzept'], '200' => ['id' => 6, 'title' => 'Umsetzung']];
        $resolve = fn (string $gid): ?array => $known[$gid] ?? null;
        $text = 'Siehe [das Konzept](https://app.asana.com/0/1/100) und https://app.asana.com/0/1/200/f, '
            .'neu: https://app.asana.com/1/9/project/3/task/100?focus=true, fremd: [x](https://app.asana.com/0/1/999) '
            .'und https://example.com/0/1/100';

        $this->assertSame(
            'Siehe @[das Konzept](task:5) und @[Umsetzung](task:6), neu: @[Konzept](task:5)?focus=true, fremd: [x](https://app.asana.com/0/1/999) und https://example.com/0/1/100',
            (new AsanaTextService)->linkTasks($text, $resolve),
        );
    }

    public function test_a_link_whose_text_is_its_address_is_named_after_the_task(): void
    {
        $url = 'https://app.asana.com/0/1/100';

        $this->assertSame('@[Konzept](task:5)', (new AsanaTextService)->linkTasks("[{$url}]({$url})", fn () => ['id' => 5, 'title' => 'Konzept']));
    }

    public function test_it_tells_whether_a_text_has_task_links(): void
    {
        $service = new AsanaTextService;

        $this->assertTrue($service->hasTaskLinks('x https://app.asana.com/0/1/2 y'));
        $this->assertFalse($service->hasTaskLinks('x https://app.asana.com/0/1 y'));
    }

    public function test_text_typed_in_asana_is_not_read_as_markdown_or_html(): void
    {
        // "<div>" would be dropped as HTML, the others would turn into formatting
        $this->assertSame('Nimm \<div> hier', $this->markdown('<body>Nimm &lt;div&gt; hier</body>'));
        $this->assertSame('\\*Hinweis\\* und snake_case und \\_Kursiv\\_', $this->markdown('<body>*Hinweis* und snake_case und _Kursiv_</body>'));
        $this->assertSame("\\# keine Überschrift\n\n\\- kein Punkt\n\n1\\. keine Liste", $this->markdown("<body># keine Überschrift\n\n- kein Punkt\n\n1. keine Liste</body>"));
        $this->assertSame('\\[kein\\](Link)', $this->markdown('<body>[kein](Link)</body>'));
    }

    public function test_the_plain_text_is_taken_literally_too(): void
    {
        $this->assertSame('\\*Hinweis\\* mit \\<b>', $this->markdown('', '*Hinweis* mit <b>'));
    }
}
