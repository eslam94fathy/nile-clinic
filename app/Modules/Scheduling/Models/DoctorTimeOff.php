<?php

namespace App\Modules\Scheduling\Models;

use Database\Factories\DoctorTimeOffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A doctor's time off (data-model.md): whole days when the minutes are null, otherwise part of
 * one day. Persistence mapping only; workflows live in TimeOffService.
 */
class DoctorTimeOff extends Model
{
    /** @use HasFactory<DoctorTimeOffFactory> */
    use HasFactory;

    protected $table = 'doctor_time_off';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doctor_id',
        'start_date',
        'end_date',
        'start_minute',
        'end_minute',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'start_minute' => 'integer',
            'end_minute' => 'integer',
        ];
    }

    public function isWholeDay(): bool
    {
        return $this->start_minute === null;
    }

    protected static function newFactory(): DoctorTimeOffFactory
    {
        return DoctorTimeOffFactory::new();
    }
}
