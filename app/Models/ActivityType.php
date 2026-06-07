<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityType extends Model
{
    protected $fillable = [
        'user_id', 'name', 'points', 'icon', 'sort_order', 'archived',
    ];

    protected $casts = [
        'points'     => 'integer',
        'sort_order' => 'integer',
        'archived'   => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'type_id');
    }

    /**
     * Seed a starter set of activities for a brand-new user.
     */
    public static function seedDefaultsFor(User $user): void
    {
        $defaults = [
            ['Morning Walk', 5,  '🚶'],
            ['Workout',      10, '💪'],
            ['Healthy Diet', 5,  '🥗'],
            ['No Junk Food', 5,  '🚫'],
            ['Reading',      3,  '📖'],
            ['Learning',     3,  '🧠'],
            ['Meditation',   3,  '🧘'],
            ['Water Goal',   2,  '💧'],
        ];

        foreach ($defaults as $i => [$name, $points, $icon]) {
            $user->activityTypes()->create([
                'name'       => $name,
                'points'     => $points,
                'icon'       => $icon,
                'sort_order' => $i,
            ]);
        }
    }
}
