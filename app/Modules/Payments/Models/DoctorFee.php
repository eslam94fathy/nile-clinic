<?php

namespace App\Modules\Payments\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Staff\Models\User;
use Database\Factories\DoctorFeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A doctor's current consultation fee (data-model.md). Past amounts live on the charges made at them.
 */
class DoctorFee extends Model
{
    /** @use HasFactory<DoctorFeeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'doctor_id',
        'amount',
        'set_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    protected static function newFactory(): DoctorFeeFactory
    {
        return DoctorFeeFactory::new();
    }
}
