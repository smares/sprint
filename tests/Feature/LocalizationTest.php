<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCommented;
use App\Notifications\TaskStatusChanged;
use App\Notifications\UserMentioned;
use App\Services\InboxTextService;
use App\Services\LocaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function inLocale(string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function test_english_is_the_default_of_the_application(): void
    {
        $this->assertStringContainsString("env('APP_LOCALE', 'en')", File::get(config_path('app.php')));
        $this->assertStringContainsString("\nAPP_LOCALE=en\n", File::get(base_path('.env.example')));

        DB::table('users')->insert(['name' => 'Raw', 'email' => 'raw@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('en', DB::table('users')->where('email', 'raw@example.com')->value('locale'));
    }

    public function test_the_supported_languages_are_listed(): void
    {
        $this->assertSame(['de', 'en'], LocaleService::codes());
        $this->assertSame('Deutsch', LocaleService::available()['de']);
        $this->assertTrue(LocaleService::isSupported('en'));
        $this->assertFalse(LocaleService::isSupported('fr'));
        $this->assertFalse(LocaleService::isSupported(null));
    }

    public function test_new_people_get_the_default_language_unless_told_otherwise(): void
    {
        $this->assertSame('de', User::factory()->create()->fresh()->locale);
        $this->assertSame('en', User::factory()->create(['locale' => 'en'])->fresh()->locale);
    }

    public function test_the_best_language_is_picked_from_the_browser_header(): void
    {
        $this->assertSame('en', LocaleService::fromHeader('en-US,en;q=0.9,de;q=0.8'));
        $this->assertSame('de', LocaleService::fromHeader('de-DE,de;q=0.9,en;q=0.8'));
        $this->assertSame('de', LocaleService::fromHeader('fr-FR,fr;q=0.9,de;q=0.7,en;q=0.5'));
        $this->assertSame('en', LocaleService::fromHeader('fr;q=0.9, en;q=0.4'));
        $this->assertNull(LocaleService::fromHeader('fr-FR,fr;q=0.9'));
        $this->assertNull(LocaleService::fromHeader(''));
        $this->assertNull(LocaleService::fromHeader(null));
    }

    public function test_visitors_see_the_language_of_their_browser_or_their_choice(): void
    {
        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get(route('login'))->assertOk()->assertSee('lang="en"', false);
        config(['app.locale' => 'en']);
        $this->withHeader('Accept-Language', 'fr-FR')->get(route('login'))->assertOk()->assertSee('lang="en"', false);

        $this->withHeader('Accept-Language', 'de')->post(route('locale.update'), ['locale' => 'en'])->assertRedirect();
        $this->withHeader('Accept-Language', 'de')->get(route('login'))->assertSee('lang="en"', false);
    }

    public function test_the_language_choice_is_validated(): void
    {
        $this->post(route('locale.update'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
        $this->post(route('locale.update'), [])->assertSessionHasErrors('locale');
        $this->assertNull(session('locale'));
    }

    public function test_signed_in_people_get_their_profile_language_and_changing_it_is_saved(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->withHeader('Accept-Language', 'de')->get(route('projects.index'))->assertSee('lang="en"', false);

        $this->post(route('locale.update'), ['locale' => 'de'])->assertRedirect();
        $this->assertSame('de', $user->fresh()->locale);
    }

    public function test_the_profile_changes_the_language_for_interface_and_mails(): void
    {
        $user = User::factory()->create(['locale' => 'de']);
        $this->actingAs($user);

        Livewire::test('pages::profile')->assertSet('locale', 'de')->set('locale', 'en')->assertRedirect(route('profile'));
        $this->assertSame('en', $user->fresh()->locale);

        Livewire::test('pages::profile')->set('locale', 'fr')->assertHasErrors('locale');
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_the_interface_follows_the_language(): void
    {
        LocaleService::apply('en');
        $this->assertSame('No results found', __('No results found'));

        LocaleService::apply('de');
        $this->assertSame('Nichts gefunden', __('No results found'));
    }

    public function test_validation_messages_follow_the_language(): void
    {
        LocaleService::apply('de');
        $this->assertSame('Das Feld Name ist erforderlich.', Validator::make([], ['name' => ['required']])->messages()->first('name'));
        $this->assertSame('Das Feld E-Mail muss eine gültige E-Mail-Adresse sein.', Validator::make(['email' => 'x'], ['email' => ['email']])->messages()->first('email'));

        LocaleService::apply('en');
        $this->assertSame('The name field is required.', Validator::make([], ['name' => ['required']])->messages()->first('name'));
        $this->assertSame('The provided password is incorrect.', __('auth.password'));
    }

    public function test_auth_and_pagination_texts_exist_in_both_languages(): void
    {
        foreach (['auth.failed', 'auth.throttle', 'passwords.reset', 'passwords.user', 'pagination.next'] as $key) {
            $this->assertNotSame($key, __($key, [], 'de'), "{$key} missing in de");
            $this->assertNotSame($key, __($key, [], 'en'), "{$key} missing in en");
        }
    }

    public function test_every_validation_rule_has_a_german_message(): void
    {
        $english = array_keys(require base_path('lang/en/validation.php'));
        $german = array_keys(require base_path('lang/de/validation.php'));

        $this->assertSame([], array_values(array_diff($english, $german)));
    }

    public function test_mails_are_written_in_the_language_of_the_recipient(): void
    {
        $author = User::factory()->create(['name' => 'Otto']);
        $project = Project::factory()->create(['name' => 'Website']);
        $task = Task::factory()->for($project)->create(['title' => 'Angebot']);
        $comment = Comment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id, 'body' => 'Bitte prüfen']);
        $german = User::factory()->create(['name' => 'Anna', 'locale' => 'de']);
        $english = User::factory()->create(['name' => 'Anna', 'locale' => 'en']);

        $mailDe = $this->inLocale('de', fn () => (new TaskCommented($comment))->toMail($german));
        $mailEn = $this->inLocale('en', fn () => (new TaskCommented($comment))->toMail($english));

        $this->assertSame('Neuer Kommentar: Angebot', $mailDe->subject);
        $this->assertSame('New comment: Angebot', $mailEn->subject);
        $this->assertStringContainsString('hat die Aufgabe', (string) $mailDe->render());
        $this->assertStringContainsString('commented on the task', (string) $mailEn->render());
        $this->assertStringContainsString('Open task', (string) $mailEn->render());
    }

    public function test_queued_notifications_use_the_recipients_language(): void
    {
        $author = User::factory()->create(['name' => 'Otto']);
        $english = User::factory()->create(['locale' => 'en']);
        $project = Project::factory()->create();
        $project->setRole($english, ProjectRole::Editor);
        $task = Task::factory()->for($project)->create(['assignee_id' => $english->id, 'title' => 'Angebot']);
        $this->actingAs($author);

        Notification::fake();
        $task->update(['status_id' => $project->doneStatus()->id]);

        Notification::assertSentTo($english, TaskStatusChanged::class, fn ($notification, $channels, $notifiable) => $notification->locale === 'en' || $notifiable->preferredLocale() === 'en');
        $this->assertSame('en', $english->preferredLocale());
    }

    public function test_a_language_without_mail_templates_falls_back_to_english(): void
    {
        $author = User::factory()->create();
        $task = Task::factory()->create(['title' => 'Angebot']);
        $comment = Comment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id]);
        $reader = User::factory()->create();

        $mail = $this->inLocale('fr', fn () => (new TaskCommented($comment))->toMail($reader));

        $this->assertSame('New comment: Angebot', $mail->subject);
    }

    public function test_titles_with_special_characters_are_not_escaped_in_subjects(): void
    {
        $author = User::factory()->create();
        $task = Task::factory()->create(['title' => 'Q&A <b>"Fragen"</b>']);
        $comment = Comment::factory()->create(['task_id' => $task->id, 'user_id' => $author->id]);

        $mail = (new TaskCommented($comment))->toMail(User::factory()->create());

        $this->assertSame('Neuer Kommentar: Q&A <b>"Fragen"</b>', $mail->subject);
    }

    public function test_the_inbox_sentence_follows_the_language_and_old_entries_stay_as_they_were(): void
    {
        $recipient = User::factory()->create();
        $task = Task::factory()->create();

        $recipient->notifications()->create(['id' => 'a', 'type' => TaskCommented::class, 'data' => ['task_id' => $task->id, 'kind' => 'commented', 'by' => 'Otto']]);
        $recipient->notifications()->create(['id' => 'b', 'type' => TaskStatusChanged::class, 'data' => ['task_id' => $task->id, 'kind' => 'status_changed', 'by' => null, 'from' => 'Offen', 'to' => 'Erledigt']]);
        $recipient->notifications()->create(['id' => 'c', 'type' => UserMentioned::class, 'data' => ['task_id' => $task->id, 'kind' => 'mentioned', 'by' => 'Otto', 'where' => 'description']]);
        $recipient->notifications()->create(['id' => 'd', 'type' => TaskCommented::class, 'data' => ['task_id' => $task->id, 'summary' => 'Otto hat kommentiert']]);
        $sentences = fn () => $recipient->notifications()->orderBy('id')->get()->map(fn (DatabaseNotification $n) => InboxTextService::sentence($n))->all();

        LocaleService::apply('de');
        $this->assertSame(['Otto hat kommentiert', 'Jemand hat den Status von „Offen“ auf „Erledigt“ geändert', 'Otto hat dich in der Beschreibung erwähnt', 'Otto hat kommentiert'], $sentences());

        LocaleService::apply('en');
        $this->assertSame(['Otto commented', 'Someone changed the status from “Offen” to “Erledigt”', 'Otto mentioned you in the description', 'Otto hat kommentiert'], $sentences());
    }

    public function test_the_command_and_the_admin_form_set_the_language(): void
    {
        $this->artisan('user:create', ['name' => 'Eva', 'email' => 'eva@example.com', '--locale' => 'en'])->assertSuccessful();
        $this->assertSame('en', User::where('email', 'eva@example.com')->value('locale'));

        $this->artisan('user:create', ['name' => 'Zoe', 'email' => 'zoe@example.com', '--locale' => 'xx'])->assertFailed();
        $this->assertNull(User::where('email', 'zoe@example.com')->first());

        $this->artisan('user:create', ['name' => 'Max', 'email' => 'max@example.com'])->assertSuccessful();
        $this->assertSame('de', User::where('email', 'max@example.com')->value('locale'));

        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test('pages::admin.users')->set('name', 'Lea')->set('email', 'lea@example.com')->set('locale', 'en')->call('create')->assertHasNoErrors();
        $this->assertSame('en', User::where('email', 'lea@example.com')->value('locale'));
        Livewire::actingAs($admin)->test('pages::admin.users')->set('name', 'Kai')->set('email', 'kai@example.com')->set('locale', 'xx')->call('create')->assertHasErrors('locale');
    }
}
