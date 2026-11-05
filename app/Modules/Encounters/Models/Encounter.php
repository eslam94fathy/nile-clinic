<?php

namespace App\Modules\Encounters\Models;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use Database\Factories\EncounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The medical record of one visit (data-model.md). Persistence mapping only; workflows live in
 * EncounterService. Clinical attributes are encrypted by their casts and hidden from serialization,
 * so only EncounterResource returns them (research R6).
 */
class Encounter extends Model
{
    /** @use HasFactory<EncounterFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'queue_entry_id',
        'doctor_id',
        'patient_id',
        'date',
        'status',
        'chief_complaint',
        'examination_findings',
        'diagnosis',
        'treatment_plan',
        'prescriptions',
        'finalized_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'chief_complaint',
        'examination_findings',
        'diagnosis',
        'treatment_plan',
        'prescriptions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => EncounterStatus::class,
            'chief_complaint' => 'encrypted',
            'examination_findings' => 'encrypted',
            'diagnosis' => 'encrypted',
            'treatment_plan' => 'encrypted',
            'prescriptions' => 'encrypted:array',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QueueEntry, $this>
     */
    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class);
    }

    /**
     * @return BelongsTo<Doctor, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return HasMany<EncounterAddendum, $this>
     */
    public function addenda(): HasMany
    {
        return $this->hasMany(EncounterAddendum::class)->orderBy('id');
    }

    protected static function newFactory(): EncounterFactory
    {
        return EncounterFactory::new();
    }
}
