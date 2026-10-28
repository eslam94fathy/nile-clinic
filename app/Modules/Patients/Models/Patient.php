<?php

namespace App\Modules\Patients\Models;

use App\Modules\Patients\Enums\Sex;
use App\Modules\Patients\Support\ArabicName;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient (data-model.md). Persistence mapping only; workflows live in PatientService.
 */
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    /**
     * name_search is not fillable: only the full_name mutator sets it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'date_of_birth',
        'sex',
        'phone',
        'national_id',
        'address',
        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_phone',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'name_search',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'sex' => Sex::class,
        ];
    }

    /**
     * Setting the name also sets its search form, so the two columns cannot drift (research R3).
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => [
                'full_name' => ArabicName::clean($value),
                'name_search' => ArabicName::normalize($value),
            ],
        );
    }

    protected static function newFactory(): PatientFactory
    {
        return PatientFactory::new();
    }
}
