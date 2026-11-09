<?php

namespace Tests\Feature;

use App\Enums\AmbientBackground;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MoveAmbientBackgroundMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_09_142736_move_ambient_background_to_the_interface_state.php');
    }

    /**
     * The shape of 2.13.3: the choice lives in a column of the user.
     */
    private function columnBack(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ambient_background', 16)->default('animated');
        });
    }

    public function test_the_choice_of_the_column_moves_to_the_interface_state_and_the_column_goes(): void
    {
        $this->columnBack();
        $chose = User::factory()->create();
        $default = User::factory()->create();
        $withState = User::factory()->create();
        $withState->rememberInterface(['appearance' => 'dark']);

        DB::table('users')->where('id', $chose->id)->update(['ambient_background' => 'off']);
        DB::table('users')->where('id', $withState->id)->update(['ambient_background' => 'still']);

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('users', 'ambient_background'));
        $this->assertSame(AmbientBackground::Off, $chose->fresh()->ambientBackground());
        $this->assertSame(AmbientBackground::Animated, $default->fresh()->ambientBackground());
        $this->assertArrayNotHasKey('ambient', $default->fresh()->interfaceState());

        $state = $withState->fresh()->interfaceState();
        $this->assertSame('still', $state['ambient']);
        $this->assertSame('dark', $state['appearance']);
        $this->assertGreaterThan(1, $state['rev']);
    }

    public function test_rolling_back_puts_the_choice_into_the_column_again(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->rememberInterface(['ambient' => 'still']);
        $other->rememberInterface(['appearance' => 'light']);

        $this->migration()->down();

        $this->assertSame('still', DB::table('users')->where('id', $user->id)->value('ambient_background'));
        $this->assertSame('animated', DB::table('users')->where('id', $other->id)->value('ambient_background'));
    }
}
