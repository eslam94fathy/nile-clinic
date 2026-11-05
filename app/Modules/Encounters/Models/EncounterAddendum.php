<?php

namespace App\Modules\Encounters\Models;

use Database\Factories\EncounterAddendumFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated correction on one finalized record (data-model.md). Rows are only ever inserted, so there
 * is no updated_at. The text is encrypted by its cast and hidden from serialization (research R6).
 */
class EncounterAddendum extends Model
{
    /** @use HasFactory<EncounterAddendumFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'encounter_addenda';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'encounter_id',
        'text',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'text',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'text' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    protected static function newFactory(): EncounterAddendumFactory
    {
        return EncounterAddendumFactory::new();
    }
}
