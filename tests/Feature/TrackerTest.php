<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ActivityType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_gets_seeded_with_default_activities(): void
    {
        $user = User::factory()->create();
        ActivityType::seedDefaultsFor($user);

        $this->assertSame(8, $user->activityTypes()->count());
    }

    public function test_tracker_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Quick add');
    }

    public function test_quick_add_logs_an_activity_and_updates_total(): void
    {
        $user = User::factory()->create();
        $type = $user->activityTypes()->create([
            'name' => 'Workout', 'points' => 10, 'icon' => '💪', 'sort_order' => 0,
        ]);

        Livewire::actingAs($user)
            ->test('pages::tracker')
            ->call('addType', $type->id)
            ->assertSet('selectedDate', now()->toDateString());

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'name'    => 'Workout',
            'points'  => 10,
        ]);
    }

    public function test_custom_activity_can_be_added_and_deleted(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('pages::tracker')
            ->set('customName', 'Yoga')
            ->set('customPoints', 7)
            ->call('addCustom');

        $log = ActivityLog::where('user_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Yoga', $log->name);
        $this->assertSame(7, $log->points);

        $component->call('deleteLog', $log->id);
        $this->assertDatabaseMissing('activity_logs', ['id' => $log->id]);
    }

    public function test_user_cannot_delete_another_users_log(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $log = ActivityLog::create([
            'user_id' => $owner->id, 'name' => 'Walk', 'points' => 5,
            'log_date' => now()->toDateString(),
        ]);

        Livewire::actingAs($attacker)
            ->test('pages::tracker')
            ->call('deleteLog', $log->id);

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);
    }

    public function test_export_returns_json_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        ActivityType::seedDefaultsFor($user);

        $this->actingAs($user)
            ->get(route('tracker.export'))
            ->assertOk()
            ->assertJsonStructure(['version', 'exported_at', 'user', 'types', 'logs']);
    }
}
