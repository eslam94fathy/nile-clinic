<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\ArabicName;
use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A doctor profile linked to one staff account (data-model.md). Persistence mapping only;
 * workflows live in DoctorService.
 */
class Doctor extends Model
{
    /** @use HasFactory<DoctorFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'registration_number',
    ];

    protected function title(): Attribute
    {
        return Attribute::make(set: fn (string $value) => ArabicName::clean($value));
    }

    /**
     * The staff account that supplies the doctor's name and active status (FR-011).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Specialty, $this>
     */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'doctor_specialty');
    }

    /**
     * Active only while the account is active and has the doctor role (FR-012, R2). Derived, never
     * stored, so account changes made through the Staff module take effect at once.
     */
    public function isActive(): bool
    {
        return $this->user->is_active && $this->user->role === Role::Doctor;
    }

    protected static function newFactory(): DoctorFactory
    {
        return DoctorFactory::new();
    }
}
