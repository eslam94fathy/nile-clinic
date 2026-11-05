<?php

namespace App\Modules\Encounters\Enums;

/**
 * A medical record's status (data-model.md). Transition: draft → finalized (finalize). Finalized is
 * final; later corrections are addenda.
 */
enum EncounterStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
