<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One working period of a weekly schedule (data-model.md): weekday 0 = Sunday, times in minutes
 * since midnight (R3).
 */
class SchedulePeriod extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'weekday',
        'start_minute',
        'end_minute',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'start_minute' => 'integer',
            'end_minute' => 'integer',
        ];
    }
}
