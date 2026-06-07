<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class HeatmapYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_a_past_years_heatmap(): void
    {
        $user = User::factory()->create();
        $currentYear = (int) now()->year;
        $pastYear = $currentYear - 1;

        // A log from January of last year — older than the old 53-week window,
        // so it was previously invisible.
        ActivityLog::create([
            'user_id' => $user->id, 'type_id' => null, 'name' => 'Old Walk',
            'points' => 7, 'log_date' => sprintf('%d-01-15', $pastYear),
        ]);
        // A current-year log.
        ActivityLog::create([
            'user_id' => $user->id, 'type_id' => null, 'name' => 'New Walk',
            'points' => 3, 'log_date' => now()->toDateString(),
        ]);

        $component = Livewire::actingAs($user)->test('pages::tracker');

        // Defaults to the current year and shows the current-year total.
        $component->assertSet('year', $currentYear)
            ->assertSee('3 '.__('pts'))
            ->assertSee((string) $currentYear);

        // The past year is selectable, and switching to it surfaces the old data.
        $component->set('year', $pastYear)
            ->assertSee('7 '.__('pts'))
            ->assertSee((string) $pastYear);
    }

    public function test_change_year_respects_available_bounds(): void
    {
        $user = User::factory()->create();
        $currentYear = (int) now()->year;
        $pastYear = $currentYear - 1;

        ActivityLog::create([
            'user_id' => $user->id, 'type_id' => null, 'name' => 'Old',
            'points' => 5, 'log_date' => sprintf('%d-03-01', $pastYear),
        ]);

        $component = Livewire::actingAs($user)->test('pages::tracker');

        // Cannot go past the current (latest) year.
        $component->call('changeYear', 1)->assertSet('year', $currentYear);

        // Can step back to the earliest year with data, but no further.
        $component->call('changeYear', -1)->assertSet('year', $pastYear);
        $component->call('changeYear', -1)->assertSet('year', $pastYear);
    }
}
