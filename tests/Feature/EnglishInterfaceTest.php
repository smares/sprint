<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\LocaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page, rendered for an English reader, must not show German texts. Data is created in English, too.
 */
class EnglishInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private const GERMAN = '/[äöüÄÖÜß]|\b(Aufgabe|Aufgaben|Projekt|Projekte|Speichern|Abbrechen|Löschen|Neue|Neuer|Neues|Fällig|Zuständig|Beschreibung|Hinzufügen|Entfernen|Anlegen|Bitte|Keine|Noch|Mitglieder|Felder|Einstellungen|Erledigt|Offen|Anmelden|Passwort|Sprache|Suche|Alle|Zeitleiste|Kalender|Hinweis|Ansicht|Ansichten|Filter zurücksetzen|Zurücksetzen|Fertig|Beteiligte|Kommentar|Kommentare|Anhänge|Datei|Dateien|nicht|oder|und|für|mit|ist|wird|werden|dein|deine|Gespeichert|Sicherheit|Zugang)\b/u';

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        LocaleService::apply('en');
        $this->withHeader('Accept-Language', 'en');

        $this->user = User::factory()->admin()->create(['name' => 'Alice Archer', 'email' => 'alice@example.com', 'locale' => 'en']);
        $this->project = Project::factory()->create(['name' => 'Website relaunch', 'description' => 'Everything for the launch']);
        $this->project->setRole($this->user, ProjectRole::Admin);
        $tag = Tag::factory()->for($this->project)->create(['name' => 'Urgent work']);
        $parent = Task::factory()->for($this->project)->create(['title' => 'Write the offer', 'description' => 'Check the numbers', 'assignee_id' => $this->user->id, 'due_date' => now()->addDays(3), 'start_date' => now()->addDay(), 'position' => 0, 'repeat_unit' => 'week', 'repeat_interval' => 1, 'repeat_mode' => 'schedule']);
        $parent->tags()->attach($tag);
        Task::factory()->for($this->project)->create(['title' => 'Send the invoice', 'parent_id' => $parent->id, 'position' => 0]);
        Task::factory()->for($this->project)->create(['title' => 'Plan the meeting', 'due_date' => now()->subDay(), 'position' => 1]);
        Comment::factory()->create(['task_id' => $parent->id, 'user_id' => $this->user->id, 'body' => 'Looks good']);
        $team = Team::factory()->create(['name' => 'Core team']);
        $team->users()->attach($this->user);
        $this->project->update(['description' => 'Everything for the launch']);
        $this->actingAs($this->user);
    }

    private function assertEnglish(string $url, array $mustSee = []): void
    {
        $html = $this->get($url)->assertOk()->getContent();
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);
        $text = html_entity_decode(strip_tags(preg_replace('/<[^>]+(?:title|aria-label|placeholder|alt)="([^"]*)"[^>]*>/i', ' $1 ', $html)), ENT_QUOTES);
        $text = preg_replace('/\s+/', ' ', $text);

        preg_match_all(self::GERMAN, $text, $matches);
        $this->assertSame([], array_values(array_unique($matches[0])), "German words on {$url}");

        foreach ($mustSee as $expected) {
            $this->assertStringContainsString($expected, $text, "{$expected} missing on {$url}");
        }
    }

    public function test_the_project_pages_are_english(): void
    {
        $this->assertEnglish(route('projects.index'), ['Website relaunch']);
        $this->assertEnglish(route('projects.show', $this->project), ['Write the offer', 'Open']);
        $this->assertEnglish(route('projects.show', $this->project).'?status=all');
        $this->assertEnglish(route('projects.board', $this->project));
        $this->assertEnglish(route('projects.calendar', $this->project));
        $this->assertEnglish(route('projects.timeline', $this->project));
        $this->assertEnglish(route('projects.fields', $this->project), ['Priority']);
        $this->assertEnglish(route('projects.members', $this->project), ['Alice Archer']);
    }

    public function test_the_task_page_and_the_panel_are_english(): void
    {
        $task = Task::where('title', 'Write the offer')->firstOrFail();

        $this->assertEnglish(route('tasks.show', $task), ['Write the offer', 'Looks good']);
        $this->assertEnglish(route('projects.show', $this->project).'?task='.$task->id);
    }

    public function test_the_account_pages_are_english(): void
    {
        $this->assertEnglish(route('profile'));
        $this->assertEnglish(route('profile').'?tab=security');
        $this->assertEnglish(route('profile').'?tab=api');
        $this->assertEnglish(route('inbox'));
        $this->assertEnglish(route('tasks.mine'), ['Write the offer']);
        $this->assertEnglish(route('search').'?q=offer');
        $this->assertEnglish(route('admin.users'), ['Alice Archer']);
        $this->assertEnglish(route('admin.teams'), ['Teams']);
    }

    public function test_the_login_page_is_english(): void
    {
        auth()->logout();

        $this->assertEnglish(route('login'), ['Sign in']);
    }

    public function test_the_page_title_is_translated_for_fixed_pages_and_not_for_names(): void
    {
        $this->get(route('profile'))->assertSee('<title>Profile', false);
        $this->get(route('projects.show', $this->project))->assertSee('<title>Website relaunch', false);
    }

    public function test_activity_sentences_and_labels_are_english(): void
    {
        $task = Task::where('title', 'Write the offer')->firstOrFail();
        $this->assertSame('weekly', $task->recurrenceLabel());

        $task->update(['status_id' => $this->project->doneStatus()->id, 'title' => 'Write the final offer']);

        $this->assertEnglish(route('tasks.show', $task), ['changed the status from', 'changed the title']);
    }

    public function test_new_projects_get_english_defaults_for_english_creators(): void
    {
        $project = Project::factory()->create();

        $this->assertSame(['Open', 'In progress', 'Done'], $project->statuses()->pluck('name')->all());
        $this->assertSame('Priority', $project->customFields()->firstOrFail()->name);
    }
}
