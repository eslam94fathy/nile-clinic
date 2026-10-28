<?php

namespace App\Support;

/**
 * The one definition of how names are cleaned and normalized for search, shared by Patients
 * (feature 003, research R3) and Doctors (feature 004, research R4).
 *
 * The database collation ignores Latin case but treats أ/ا, ة/ه, and ى/ي as different letters,
 * so search runs on a normalized copy of the name instead.
 */
final class ArabicName
{
    /**
     * Letter variants treated as the same letter in search (FR-012).
     */
    public const VARIANTS = [
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي',
    ];

    /**
     * Trim and collapse runs of whitespace to one space; letters are kept as entered.
     */
    public static function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * The search form of a name: cleaned, lower-cased, and with Arabic letter variants unified.
     */
    public static function normalize(string $name): string
    {
        return strtr(mb_strtolower(self::clean($name)), self::VARIANTS);
    }
}
