<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\DateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dates read DD.MM.YYYY in German and YYYY-MM-DD in every other language; a due date within a week is told in words
 * ("Tomorrow") with the full date as the title. The words count calendar days, so summer time never shifts them, and
 * "today" is the day of the person looking.
 */
class DateDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function inLanguage(string $code, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($code);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function viewer(?string $timezone = null): User
    {
        return User::factory()->make(['timezone' => $timezone]);
    }

    private function label(string $due, ?User $viewer = null): ?string
    {
        return DateService::relativeLabel(Carbon::parse($due, 'UTC'), $viewer);
    }

    public function test_german_writes_dates_day_first_and_other_languages_as_iso(): void
    {
        $day = Carbon::parse('2026-10-15');

        $this->assertSame('15.10.2026', $day->isoFormat('L'));
        $this->assertSame('15.10.2026 14:05', $day->copy()->setTime(14, 5)->isoFormat('L LT'));
        $this->assertSame('Do., 15.10.2026', DateService::fullLabel($day));
        $this->assertSame('12.10.2026 – 18.10.2026', DateService::weekRange($day));

        $this->inLanguage('en', function () use ($day): void {
            $this->assertSame('2026-10-15', $day->isoFormat('L'));
            $this->assertSame('2026-10-15 14:05', $day->copy()->setTime(14, 5)->isoFormat('L LT'));
            $this->assertSame('Thu, 2026-10-15', DateService::fullLabel($day));
            $this->assertSame('2026-10-12 – 2026-10-18', DateService::weekRange($day));
        });
    }

    public function test_machine_formats_stay_iso_in_german(): void
    {
        $this->assertSame('2026-10-15', DateService::parseIsoDate('2026-10-15')?->toDateString());
        $this->assertNull(DateService::parseIsoDate('15.10.2026'));
    }

    public function test_days_close_to_today_are_told_in_words(): void
    {
        $this->travelTo('2026-10-15 09:00');

        $this->assertSame('Vorgestern', $this->label('2026-10-13'));
        $this->assertSame('Gestern', $this->label('2026-10-14'));
        $this->assertSame('Heute', $this->label('2026-10-15'));
        $this->assertSame('Morgen', $this->label('2026-10-16'));
        $this->assertSame('Übermorgen', $this->label('2026-10-17'));
        $this->assertSame('In 3 Tagen', $this->label('2026-10-18'));
        $this->assertSame('Vor 3 Tagen', $this->label('2026-10-12'));
        $this->assertSame('In 7 Tagen', $this->label('2026-10-22'));
        $this->assertSame('Vor 7 Tagen', $this->label('2026-10-08'));
    }

    public function test_days_further_away_have_no_words(): void
    {
        $this->travelTo('2026-10-15 09:00');

        $this->assertNull($this->label('2026-10-23'));
        $this->assertNull($this->label('2026-10-07'));
        $this->assertNull($this->label('2027-10-15'));
    }

    public function test_the_words_follow_the_language(): void
    {
        $this->travelTo('2026-10-15 09:00');

        $this->inLanguage('en', function (): void {
            $this->assertSame('The day before yesterday', $this->label('2026-10-13'));
            $this->assertSame('Yesterday', $this->label('2026-10-14'));
            $this->assertSame('Today', $this->label('2026-10-15'));
            $this->assertSame('Tomorrow', $this->label('2026-10-16'));
            $this->assertSame('The day after tomorrow', $this->label('2026-10-17'));
            $this->assertSame('In 3 days', $this->label('2026-10-18'));
            $this->assertSame('3 days ago', $this->label('2026-10-12'));
            $this->assertSame('In 7 days', $this->label('2026-10-22'));
            $this->assertNull($this->label('2026-10-23'));
        });
    }

    public function test_a_week_stays_a_week_when_clocks_change_in_between(): void
    {
        $berlin = $this->viewer('Europe/Berlin');

        // Summer time starts on 2027-03-28 and ends on 2026-10-25: a week then has 167 or 169 hours
        $this->travelTo(Carbon::parse('2027-03-27 12:00', 'Europe/Berlin'));
        $this->assertSame('In 7 Tagen', $this->label('2027-04-03', $berlin));
        $this->assertSame('In 3 Tagen', $this->label('2027-03-30', $berlin));
        $this->assertSame('Morgen', $this->label('2027-03-28', $berlin));

        $this->travelTo(Carbon::parse('2026-10-24 12:00', 'Europe/Berlin'));
        $this->assertSame('In 7 Tagen', $this->label('2026-10-31', $berlin));
        $this->assertSame('Übermorgen', $this->label('2026-10-26', $berlin));

        $this->travelTo(Carbon::parse('2027-04-03 00:30', 'Europe/Berlin'));
        $this->assertSame('Vor 7 Tagen', $this->label('2027-03-27', $berlin));

        $this->travelTo(Carbon::parse('2026-10-31 23:30', 'Europe/Berlin'));
        $this->assertSame('Vor 7 Tagen', $this->label('2026-10-24', $berlin));
        $this->assertSame('Gestern', $this->label('2026-10-30', $berlin));
    }

    public function test_today_is_the_day_of_the_person_not_of_the_server(): void
    {
        // The application's own zone is part of the picture ("Morgen" below), so it must not come from the environment
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        // 23:30 on the 14th in UTC is already 12:30 on the 15th in Auckland, and still the 14th in New York
        $this->travelTo(Carbon::parse('2026-10-14 23:30', 'UTC'));
        $auckland = $this->viewer('Pacific/Auckland');
        $newYork = $this->viewer('America/New_York');

        $this->assertSame('Heute', $this->label('2026-10-15', $auckland));
        $this->assertSame('Gestern', $this->label('2026-10-14', $auckland));
        $this->assertSame('Morgen', $this->label('2026-10-15', $this->viewer('UTC')));
        $this->assertSame('Heute', $this->label('2026-10-14', $newYork));
        $this->assertSame('Morgen', $this->label('2026-10-15'));
        $this->assertSame('In 7 Tagen', $this->label('2026-10-22', $auckland));
        $this->assertNull($this->label('2026-10-22', $this->viewer('Pacific/Pago_Pago')));
    }

    public function test_the_words_for_a_date_field_cover_a_week_either_way(): void
    {
        $this->travelTo('2026-10-15 09:00');

        $labels = DateService::relativeLabelsByDate();

        $this->assertCount(15, $labels);
        $this->assertSame('Heute', $labels['2026-10-15']);
        $this->assertSame('Morgen', $labels['2026-10-16']);
        $this->assertSame('Vor 7 Tagen', $labels['2026-10-08']);
        $this->assertSame('In 7 Tagen', $labels['2026-10-22']);
        $this->assertArrayNotHasKey('2026-10-23', $labels);
    }

    public function test_the_due_chip_shows_the_word_with_the_full_date_as_title(): void
    {
        $this->travelTo('2026-10-15 09:00');
        $task = Task::factory()->create(['due_date' => '2026-10-16']);

        $this->actingAs($this->viewer())->blade('<x-task-due :task="$task" />', ['task' => $task])
            ->assertSeeText('Morgen')
            ->assertSeeHtml('title="Fr., 16.10.2026"')
            ->assertSeeHtml('<span class="sr-only"> (Fr., 16.10.2026)</span>');

        $this->inLanguage('en', fn () => $this->blade('<x-task-due :task="$task" />', ['task' => $task])
            ->assertSeeText('Tomorrow')
            ->assertSeeHtml('title="Fri, 2026-10-16"'));
    }

    public function test_the_due_chip_shows_the_date_beyond_a_week(): void
    {
        $this->travelTo('2026-10-15 09:00');
        $task = Task::factory()->create(['due_date' => '2026-11-03']);

        $this->blade('<x-task-due :task="$task" />', ['task' => $task])
            ->assertSeeText('03.11.2026')
            ->assertSeeHtml('title="Di., 03.11.2026"')
            ->assertDontSeeHtml('sr-only');

        $this->inLanguage('en', fn () => $this->blade('<x-task-due :task="$task" />', ['task' => $task])
            ->assertSeeText('2026-11-03')
            ->assertSeeHtml('title="Tue, 2026-11-03"'));
    }

    public function test_the_due_chip_colours_by_the_day_of_the_person(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 23:30', 'UTC'));
        $task = Task::factory()->create(['due_date' => '2026-10-14']);

        $this->actingAs($this->viewer('Pacific/Auckland'))->blade('<x-task-due :task="$task" />', ['task' => $task])
            ->assertSeeHtml('data-due="red"')
            ->assertSeeText('Gestern');

        $this->actingAs($this->viewer('America/New_York'))->blade('<x-task-due :task="$task" />', ['task' => $task])
            ->assertSeeHtml('data-due="amber"')
            ->assertSeeText('Heute');
    }

    public function test_overdue_can_be_asked_for_another_day(): void
    {
        $this->travelTo('2026-10-15 09:00');
        $task = Task::factory()->make(['due_date' => '2026-10-15']);

        $this->assertFalse($task->isOverdue());
        $this->assertTrue($task->isOverdue(Carbon::parse('2026-10-16')));
        $this->assertFalse($task->isOverdue(Carbon::parse('2026-10-14')));
    }

    public function test_the_date_fields_of_a_task_tell_the_word_for_a_picked_date(): void
    {
        $this->travelTo('2026-10-15 09:00');
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $project->setRole($user, ProjectRole::Editor);
        $task = Task::factory()->for($project)->create(['due_date' => '2026-10-16']);

        $html = Livewire::actingAs($user)->test('pages::tasks.show', ['task' => $task])->html();

        $this->assertStringContainsString('x-text="words[$wire.dueDate]"', $html);
        $this->assertStringContainsString('x-text="words[$wire.startDate]"', $html);
        $this->assertStringContainsString('2026-10-16\\u0022:\\u0022Morgen', $html);
    }
}
