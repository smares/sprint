<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\ProjectRole;
use App\TaskCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class CsvExportImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'ich@example.com', 'name' => 'Ich Selbst']);
        $this->project = Project::factory()->create(['name' => 'Büro & Co']);
        $this->project->setRole($this->user, ProjectRole::Editor);
        $this->actingAs($this->user);
    }

    private function exportRows(?Project $project = null): array
    {
        $out = fopen('php://temp', 'r+');
        app(TaskCsv::class)->write($out, $project ?? $this->project);
        rewind($out);
        $contents = stream_get_contents($out);

        return ['text' => $contents, ...app(TaskCsv::class)->parse($contents)];
    }

    private function plan(string $csv): array
    {
        $parsed = app(TaskCsv::class)->parse($csv);

        return app(TaskCsv::class)->plan($this->project, $parsed['rows']);
    }

    public function test_the_export_contains_every_task_with_its_details(): void
    {
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        $helper = User::factory()->create(['email' => 'anna@example.com']);
        $this->project->setRole($helper, ProjectRole::Editor);
        $priority = $this->project->customFields()->firstOrFail();
        $parent = Task::factory()->for($this->project)->create(['title' => 'Eltern', 'description' => 'Text, mit "Anführungszeichen"', 'assignee_id' => $this->user->id, 'due_date' => '2026-12-01', 'start_date' => '2026-11-20', 'position' => 0]);
        $parent->tags()->attach($tag);
        $parent->collaborators()->attach($helper);
        $parent->fieldValues()->create(['custom_field_id' => $priority->id, 'option_id' => $priority->options->first()->id]);
        Task::factory()->for($this->project)->done()->create(['title' => 'Kind', 'parent_id' => $parent->id, 'position' => 0]);
        Task::factory()->for($this->project)->create(['title' => 'Abschnitt', 'is_section' => true]);

        $export = $this->exportRows();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $export['text']);
        $this->assertCount(2, $export['rows']);
        [$first, $second] = $export['rows'];
        $this->assertSame('Eltern', $first['title']);
        $this->assertSame('Text, mit "Anführungszeichen"', $first['description']);
        $this->assertSame('Offen', $first['status']);
        $this->assertSame('ich@example.com', $first['assignee']);
        $this->assertSame('anna@example.com', $first['collaborators']);
        $this->assertSame('2026-11-20', $first['start_date']);
        $this->assertSame('2026-12-01', $first['due_date']);
        $this->assertSame('Bug', $first['tags']);
        $this->assertSame($priority->options->first()->name, $first['field:priorität']);
        $this->assertSame('Kind', $second['title']);
        $this->assertSame((string) $parent->id, $second['parent_id']);
        $this->assertSame('yes', $second['completed']);
        $this->assertSame('Erledigt', $second['status']);
    }

    public function test_subtasks_follow_their_parents_in_the_export(): void
    {
        $a = Task::factory()->for($this->project)->create(['title' => 'A', 'position' => 0]);
        $b = Task::factory()->for($this->project)->create(['title' => 'B', 'position' => 1]);
        Task::factory()->for($this->project)->create(['title' => 'A1', 'parent_id' => $a->id]);
        Task::factory()->for($this->project)->create(['title' => 'A1a', 'parent_id' => Task::where('title', 'A1')->value('id')]);

        $this->assertSame(['A', 'A1', 'A1a', 'B'], array_column($this->exportRows()['rows'], 'title'));
        $this->assertNotNull($b);
    }

    public function test_cells_that_look_like_formulas_are_defused_and_restored_on_import(): void
    {
        Task::factory()->for($this->project)->create(['title' => '=HYPERLINK("http://böse.example")', 'description' => '-5 Grad']);

        $export = $this->exportRows();

        $this->assertStringContainsString("'=HYPERLINK", $export['text']);
        $this->assertSame('=HYPERLINK("http://böse.example")', $export['rows'][0]['title']);
        $this->assertSame('-5 Grad', $export['rows'][0]['description']);
    }

    public function test_the_download_route_sends_a_csv_to_everyone_who_may_see_the_project(): void
    {
        Task::factory()->for($this->project)->create(['title' => 'Sichtbar']);
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        $response = $this->actingAs($viewer)->get(route('projects.export', $this->project));

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=buro-co-', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Sichtbar', $response->streamedContent());
    }

    public function test_the_excel_variant_uses_semicolons(): void
    {
        Task::factory()->for($this->project)->create(['title' => 'Eins']);

        $content = $this->get(route('projects.export', [$this->project, 'delimiter' => 'semicolon']))->streamedContent();

        $this->assertStringContainsString('id;parent_id;title;', $content);
    }

    public function test_strangers_and_guests_get_no_export(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('projects.export', $this->project))->assertForbidden();

        auth()->logout();
        $this->get(route('projects.export', $this->project))->assertRedirect(route('login'));
    }

    public function test_the_parser_copes_with_delimiters_encodings_and_blank_lines(): void
    {
        $csv = new TaskCsv;

        $semicolon = $csv->parse("Titel;Beschreibung\r\nEins;\"Zeile 1\nZeile 2\"\r\n\r\n;;\r\nZwei;ok\r\n");
        $this->assertSame(['Eins', 'Zwei'], array_column($semicolon['rows'], 'title'));
        $this->assertSame("Zeile 1\nZeile 2", $semicolon['rows'][0]['description']);

        $tabs = $csv->parse("Name\tNotes\nEins\tx\n");
        $this->assertSame('Eins', $tabs['rows'][0]['title']);
        $this->assertSame('x', $tabs['rows'][0]['description']);

        $latin = $csv->parse(mb_convert_encoding("title\nGrüße\n", 'Windows-1252', 'UTF-8'));
        $this->assertSame('Grüße', $latin['rows'][0]['title']);

        $bom = $csv->parse("\xEF\xBB\xBFtitle\nMit BOM\n");
        $this->assertSame('Mit BOM', $bom['rows'][0]['title']);
    }

    public function test_files_without_a_title_column_or_content_are_rejected(): void
    {
        $this->assertSame('no-title', (new TaskCsv)->parse("foo,bar\n1,2\n")['error']);
        $this->assertSame('empty', (new TaskCsv)->parse('')['error']);
    }

    public function test_an_asana_style_export_is_understood(): void
    {
        $this->project->setRole(User::factory()->create(['email' => 'anna@example.com']), ProjectRole::Editor);
        $csv = "Task ID,Created At,Completed At,Name,Assignee Email,Due Date,Tags,Notes,Parent task\n"
            ."100,2026-01-01,,Planung,anna@example.com,2026-12-24,\"Alpha, Beta\",Notizen,\n"
            ."101,2026-01-02,2026-02-01,Teilschritt,,,,,Planung\n";

        $plan = $this->plan($csv);

        $this->assertSame([], $plan['errors']);
        $this->assertCount(2, $plan['tasks']);
        $this->assertSame('2026-12-24', $plan['tasks'][0]['due_date']);
        $this->assertSame(['Alpha', 'Beta'], $plan['tasks'][0]['new_tags']);
        $this->assertSame($this->project->doneStatus()->id, $plan['tasks'][1]['status_id']);
        $this->assertSame('title:planung', $plan['tasks'][1]['parent_ref']);
    }

    public function test_the_plan_reports_bad_rows_and_ignored_values(): void
    {
        $plan = $this->plan("title,status,assignee,due_date,start_date\n,Offen,,,\nOK,Gibtsnicht,fremd@example.com,32.13.2026,\nZeit,,,2026-01-01,2026-02-01\n");

        $this->assertSame([['line' => 2, 'message' => 'no-title']], $plan['errors']);
        $this->assertCount(2, $plan['tasks']);
        $messages = array_column($plan['warnings'], 'message');
        $this->assertContains('unknown-status:Gibtsnicht', $messages);
        $this->assertContains('unknown-person:fremd@example.com', $messages);
        $this->assertContains('bad-date:32.13.2026', $messages);
        $this->assertContains('start-after-due', $messages);
    }

    public function test_dates_are_read_in_german_and_iso_notation(): void
    {
        $plan = $this->plan("title,due_date\nA,24.12.2026\nB,2026-12-25\nC,12/26/2026\n");

        $this->assertSame(['2026-12-24', '2026-12-25', '2026-12-26'], array_column($plan['tasks'], 'due_date'));
    }

    public function test_importing_creates_tasks_with_everything_resolved(): void
    {
        $anna = User::factory()->create(['email' => 'anna@example.com']);
        $this->project->setRole($anna, ProjectRole::Editor);
        $existing = Tag::factory()->for($this->project)->create(['name' => 'Bug']);
        Task::factory()->for($this->project)->create(['title' => 'Schon da', 'position' => 4]);
        $csv = "id,parent_id,title,description,status,assignee,collaborators,start_date,due_date,tags,field:Priorität\n"
            ."1,,Eltern,Beschreibung,In Arbeit,anna@example.com,ich@example.com,2026-12-01,2026-12-05,\"bug, Neu\",Hoch\n"
            ."2,1,Kind,,,,,,,,\n"
            ."3,2,Enkel,,,,,,,,\n"
            ."4,,Zweite,,,,,,,,\n";

        $plan = $this->plan($csv);
        $count = app(TaskCsv::class)->import($this->project, $this->user, $plan['tasks']);

        $this->assertSame(4, $count);
        $parent = Task::where('title', 'Eltern')->firstOrFail();
        $this->assertSame($this->user->id, $parent->creator_id);
        $this->assertSame('In Arbeit', $parent->status->name);
        $this->assertSame($anna->id, $parent->assignee_id);
        $this->assertSame([$this->user->id], $parent->collaborators()->pluck('users.id')->all());
        $this->assertSame('2026-12-05', $parent->due_date->toDateString());
        $this->assertEqualsCanonicalizing(['Bug', 'Neu'], $parent->tags()->pluck('name')->all());
        $this->assertSame(2, $this->project->tags()->count());
        $this->assertSame('Hoch', $parent->fieldValues()->firstOrFail()->option->name);
        $this->assertSame(5, $parent->position);
        $this->assertSame(6, Task::where('title', 'Zweite')->value('position'));

        $child = Task::where('title', 'Kind')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame($child->id, Task::where('title', 'Enkel')->value('parent_id'));
        $this->assertContains('hat die Aufgabe angelegt', $parent->activities()->get()->map->sentence()->all());
    }

    public function test_a_parent_that_comes_later_or_is_unknown_leaves_the_task_on_top_level(): void
    {
        $plan = $this->plan("id,parent_id,title\n1,2,Zu früh\n2,,Später\n3,99,Fremd\n");

        app(TaskCsv::class)->import($this->project, $this->user, $plan['tasks']);

        $this->assertNull(Task::where('title', 'Zu früh')->value('parent_id'));
        $this->assertNull(Task::where('title', 'Fremd')->value('parent_id'));
    }

    public function test_the_second_child_of_a_parent_comes_after_the_first(): void
    {
        $plan = $this->plan("id,parent_id,title\n1,,P\n2,1,Erstes\n3,1,Zweites\n");

        app(TaskCsv::class)->import($this->project, $this->user, $plan['tasks']);

        $parentId = Task::where('title', 'P')->value('id');
        $this->assertSame(['Erstes', 'Zweites'], Task::where('parent_id', $parentId)->orderBy('position')->pluck('title')->all());
    }

    public function test_an_export_can_be_imported_into_another_project(): void
    {
        $parent = Task::factory()->for($this->project)->create(['title' => 'Eltern', 'assignee_id' => $this->user->id, 'due_date' => '2026-12-01', 'position' => 0]);
        Task::factory()->for($this->project)->done()->create(['title' => 'Kind', 'parent_id' => $parent->id]);
        Task::factory()->for($this->project)->create(['title' => '=Formel', 'position' => 1]);
        $target = Project::factory()->create();
        $target->setRole($this->user, ProjectRole::Editor);

        $parsed = app(TaskCsv::class)->parse($this->exportRows()['text']);
        $plan = app(TaskCsv::class)->plan($target, $parsed['rows']);
        app(TaskCsv::class)->import($target, $this->user, $plan['tasks']);

        $this->assertSame(['Eltern', 'Kind', '=Formel'], $target->tasks()->orderBy('id')->pluck('title')->all());
        $copy = $target->tasks()->where('title', 'Kind')->firstOrFail();
        $this->assertTrue($copy->isDone());
        $this->assertSame($target->tasks()->where('title', 'Eltern')->value('id'), $copy->parent_id);
        $this->assertSame($this->user->id, $target->tasks()->where('title', 'Eltern')->value('assignee_id'));
    }

    public function test_too_long_files_are_cut_at_the_row_limit(): void
    {
        $rows = implode("\n", array_map(fn ($n) => "Aufgabe $n", range(1, TaskCsv::MAX_ROWS + 5)));

        $plan = $this->plan("title\n".$rows);

        $this->assertCount(TaskCsv::MAX_ROWS, $plan['tasks']);
        $this->assertContains('too-many-rows', array_column($plan['errors'], 'message'));
    }

    public function test_the_import_window_previews_and_then_imports(): void
    {
        $file = UploadedFile::fake()->createWithContent('tasks.csv', "title,status\nEins,Offen\nZwei,Unbekannt\n,Offen\n");

        $page = Livewire::test('project-import', ['project' => $this->project])->set('file', $file);

        $page->assertSee('2 Aufgaben bereit zum Import')->assertSee('Zeile 4: Kein Titel')->assertSee('Status „Unbekannt“ gibt es nicht');
        $this->assertSame(0, Task::count());

        $page->call('import')->assertSet('imported', 2)->assertSee('2 Aufgaben importiert');
        $this->assertSame(['Eins', 'Zwei'], Task::orderBy('id')->pluck('title')->all());
    }

    public function test_the_import_window_explains_unusable_files(): void
    {
        Livewire::test('project-import', ['project' => $this->project])
            ->set('file', UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2\n"))->assertSee('Keine Titel-Spalte gefunden')
            ->set('file', UploadedFile::fake()->createWithContent('leer.csv', ''))->assertSee('Die Datei ist leer');
    }

    public function test_oversized_files_are_refused(): void
    {
        $file = UploadedFile::fake()->create('big.csv', TaskCsv::MAX_KILOBYTES + 1, 'text/csv');

        Livewire::test('project-import', ['project' => $this->project])->set('file', $file)->assertHasErrors('file');
    }

    public function test_only_editors_may_import(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::actingAs($viewer)->test('project-import', ['project' => $this->project])->assertForbidden();

        $this->actingAs($this->user);
        $this->project->update(['archived_at' => now()]);
        Livewire::test('project-import', ['project' => $this->project])->assertForbidden();
    }

    public function test_the_list_offers_export_to_viewers_and_import_to_editors(): void
    {
        $viewer = User::factory()->create();
        $this->project->setRole($viewer, ProjectRole::Viewer);

        Livewire::test('pages::projects.show', ['project' => $this->project])->assertSee('Als CSV exportieren')->assertSee('CSV importieren');
        Livewire::actingAs($viewer)->test('pages::projects.show', ['project' => $this->project])->assertSee('Als CSV exportieren')->assertDontSee('CSV importieren');
    }
}
