<?php

namespace Tests\Unit;

use App\Enums\MineView;
use Tests\TestCase;

class MineViewTest extends TestCase
{
    public function test_it_knows_the_view_a_route_shows(): void
    {
        $this->assertSame(MineView::List, MineView::forRoute('tasks.mine'));
        $this->assertSame(MineView::Board, MineView::forRoute('tasks.mine.board'));
        $this->assertNull(MineView::forRoute('workload'));
        $this->assertNull(MineView::forRoute(null));
    }

    public function test_the_links_mark_only_the_open_view(): void
    {
        $links = MineView::links(MineView::Board);

        $this->assertSame(['list', 'board'], array_keys($links));
        $this->assertSame(route('tasks.mine.board'), $links['board']['href']);
        $this->assertTrue($links['board']['current']);
        $this->assertFalse($links['list']['current']);
        $this->assertFalse(collect(MineView::links(null))->contains('current', true));
    }
}
