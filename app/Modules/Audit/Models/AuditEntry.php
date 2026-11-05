<?php

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Staff\Models\User;
use Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded action (data-model.md). Rows are only ever inserted (FR-014). Holds names, allowed
 * values, and internal identifiers only: never clinical text, patient details, or credentials (FR-008).
 */
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action',
        'outcome',
        'actor_type',
        'actor_id',
        'actor_role',
        'subject_type',
        'subject_id',
        'patient_id',
        'network_address',
        'details',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'outcome' => AuditOutcome::class,
            'actor_type' => AuditActorType::class,
            'subject_type' => AuditSubject::class,
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function newFactory(): AuditEntryFactory
    {
        return AuditEntryFactory::new();
    }
}
