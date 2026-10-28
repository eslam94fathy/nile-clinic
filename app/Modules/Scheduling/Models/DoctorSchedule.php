<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Doctors\Models\Doctor;
use Database\Factories\DoctorScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A doctor's weekly schedule (data-model.md). Persistence mapping only; workflows live in
 * ScheduleService.
 */
class DoctorSchedule extends Model
{
    /** @use HasFactory<DoctorScheduleFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doctor_id',
        'appointment_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appointment_minutes' => 'integer',
        ];
    }

    /**
     * @return HasMany<SchedulePeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SchedulePeriod::class)->orderBy('weekday')->orderBy('start_minute');
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    protected static function newFactory(): DoctorScheduleFactory
    {
        return DoctorScheduleFactory::new();
    }
}
