<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    /** A real 2 × 1 PNG; the browser would send a cropped WebP or JPEG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAADUlEQVR4nGP4zwAE/wEHAAH/4iOeWQAAAABJRU5ErkJggg==';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Anna Beispiel']);
        $this->actingAs($this->user);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('avatar.png', base64_decode(self::PNG));
    }

    public function test_without_a_picture_the_initials_are_shown(): void
    {
        $this->assertNull($this->user->avatarUrl());

        $this->get(route('profile'))->assertOk()->assertSee('Bild wählen')->assertDontSee('Bild entfernen')->assertDontSee('/avatars/');
        $this->get(route('avatars.show', $this->user))->assertNotFound();
    }

    public function test_a_picture_is_stored_in_the_database_and_delivered_safely(): void
    {
        Livewire::test('pages::profile')->set('avatarUpload', $this->png())->assertHasNoErrors()->assertRedirect(route('profile'));

        $this->user->refresh();
        $this->assertNotNull($this->user->avatar_updated_at);
        $this->assertSame('image/png', $this->user->avatar->mime_type);

        $response = $this->get($this->user->avatarUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));

        $this->get(route('profile'))->assertSee($this->user->avatarUrl())->assertSee('Bild entfernen');
    }

    public function test_a_new_picture_replaces_the_old_one_under_a_new_address(): void
    {
        $this->freezeSecond();
        $this->user->setAvatar('old', 'image/png');
        $oldUrl = $this->user->avatarUrl();

        $this->travel(1)->minute();
        Livewire::test('pages::profile')->set('avatarUpload', $this->png());

        $this->user->refresh();
        $this->assertNotSame($oldUrl, $this->user->avatarUrl());
        $this->assertSame(1, UserAvatar::count());
        $this->assertSame(base64_decode(self::PNG), $this->user->avatar->contents());
    }

    public function test_only_small_raster_images_are_accepted(): void
    {
        $svg = UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $text = UploadedFile::fake()->createWithContent('avatar.png', 'not a picture');
        $huge = UploadedFile::fake()->create('avatar.png', UserAvatar::MAX_KILOBYTES + 1, 'image/png');

        foreach ([$svg, $text, $huge] as $file) {
            Livewire::test('pages::profile')->set('avatarUpload', $file)->assertHasErrors('avatarUpload');
        }

        $this->assertSame(0, UserAvatar::count());
        $this->assertNull($this->user->fresh()->avatar_updated_at);
    }

    public function test_the_picture_can_be_removed(): void
    {
        $this->user->setAvatar(base64_decode(self::PNG), 'image/png');
        $url = $this->user->avatarUrl();

        Livewire::test('pages::profile')->call('removeAvatar');

        $this->assertNull($this->user->fresh()->avatarUrl());
        $this->assertSame(0, UserAvatar::count());
        $this->get($url)->assertNotFound();
    }

    public function test_pictures_are_only_for_signed_in_people(): void
    {
        $this->user->setAvatar(base64_decode(self::PNG), 'image/png');

        auth()->logout();

        $this->get($this->user->avatarUrl())->assertRedirect(route('login'));
    }

    public function test_the_picture_appears_next_to_comments_on_the_board_and_in_the_menu(): void
    {
        $this->user->setAvatar(base64_decode(self::PNG), 'image/png');
        $project = Project::factory()->create();
        $project->setRole($this->user, ProjectRole::Editor);
        $task = Task::factory()->for($project)->create(['assignee_id' => $this->user->id]);
        Comment::factory()->for($task)->for($this->user)->create();
        $url = e($this->user->avatarUrl());

        $this->get(route('tasks.show', $task))->assertOk()->assertSee($url, false);
        $this->get(route('projects.board', $project))->assertOk()->assertSee($url, false);
        $this->get(route('projects.index'))->assertOk()->assertSee($url, false);
    }

    public function test_administrators_can_remove_a_picture(): void
    {
        $this->user->setAvatar(base64_decode(self::PNG), 'image/png');
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test('pages::admin.users')->assertSee('Profilbild entfernen')->call('removeAvatar', $this->user->id);

        $this->assertNull($this->user->fresh()->avatarUrl());
    }

    public function test_deleting_a_person_deletes_the_picture(): void
    {
        $this->user->setAvatar(base64_decode(self::PNG), 'image/png');

        $this->user->delete();

        $this->assertSame(0, UserAvatar::count());
    }
}
