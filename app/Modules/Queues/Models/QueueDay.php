<?php

namespace App\Modules\Queues\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One doctor's queue on one clinic day (data-model.md). Locked for every change to that queue, and
 * holds the last queue number given (research R3). Persistence mapping only.
 */
class QueueDay extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'doctor_id',
        'date',
        'last_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'last_number' => 'integer',
        ];
    }
}
