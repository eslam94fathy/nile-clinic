<?php

namespace App\Modules\Doctors\Models;

use App\Support\ArabicName;
use Database\Factories\SpecialtyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A specialty in the clinic's catalog (data-model.md). Persistence mapping only; workflows live in
 * SpecialtyService.
 */
class Specialty extends Model
{
    /** @use HasFactory<SpecialtyFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name_en',
        'name_ar',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Stored trimmed with repeated spaces collapsed, so uniqueness ignores extra spaces (R5).
     */
    protected function nameEn(): Attribute
    {
        return Attribute::make(set: fn (string $value) => ArabicName::clean($value));
    }

    /**
     * Stored trimmed with repeated spaces collapsed, so uniqueness ignores extra spaces (R5).
     */
    protected function nameAr(): Attribute
    {
        return Attribute::make(set: fn (string $value) => ArabicName::clean($value));
    }

    /**
     * @return BelongsToMany<Doctor, $this>
     */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_specialty');
    }

    protected static function newFactory(): SpecialtyFactory
    {
        return SpecialtyFactory::new();
    }
}
