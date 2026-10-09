<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Notifications\EmailChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_is_called_sprint(): void
    {
        $this->assertSame('Sprint', config('app.name'));
    }

    public function test_page_titles_end_with_the_product_name(): void
    {
        $project = Project::factory()->create(['name' => 'Website']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('<title>Website – Sprint</title>', false);
    }

    public function test_pages_show_the_mark_and_link_the_icons(): void
    {
        foreach ([route('login'), route('password.request')] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
                ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
                ->assertSee('M32 18l14 14-14 14', false);
        }

        $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertOk()->assertSee('M32 18l14 14-14 14', false);
    }

    public function test_the_user_menu_shows_the_version(): void
    {
        $this->actingAs(User::factory()->create());

        config(['sprint.version' => 'dev']);
        $this->get(route('projects.index'))->assertOk()->assertSeeInOrder(['data-app-version', 'Sprint dev'], false);

        config(['sprint.version' => '0.1.0']);
        $this->get(route('projects.index'))->assertOk()->assertSeeInOrder(['data-app-version', 'Sprint v0.1.0'], false);
    }

    public function test_the_icon_files_exist(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        $this->assertStringContainsString('M32 18l14 14-14 14', (string) file_get_contents(public_path('favicon.svg')));
    }

    public function test_mails_carry_the_mark_as_an_embedded_image(): void
    {
        User::factory()->create(['name' => 'Anna'])->notify(new EmailChanged('alt@example.com'));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        $logo = collect($email->getAttachments())->sole(fn (DataPart $part) => $part->getFilename() === 'sprint-logo.png');
        $this->assertSame('inline', $logo->getDisposition());
        $this->assertSame('image/png', $logo->getMediaType().'/'.$logo->getMediaSubtype());

        $sent = quoted_printable_decode($messages->first()->toString());
        $this->assertStringContainsString('src="cid:'.$logo->getContentId().'"', $sent);
        $this->assertStringContainsString('Content-ID: <'.$logo->getContentId().'>', $sent);
        $this->assertStringNotContainsString('laravel.com', $sent);
    }

    public function test_names_and_titles_stay_text_in_mails(): void
    {
        User::factory()->create(['name' => '[Jetzt bestätigen](https://evil.example/x) **fett**', 'locale' => 'de'])
            ->notify(new EmailChanged('alt@example.com'));

        $sent = quoted_printable_decode(app('mailer')->getSymfonyTransport()->messages()->first()->toString());

        $this->assertStringNotContainsString('href="https://evil.example/x"', $sent);
        $this->assertStringNotContainsString('<strong>fett</strong>', $sent);
        $this->assertStringContainsString('[Jetzt bestätigen](https://evil.example/x) **fett**', $sent);
    }
}
