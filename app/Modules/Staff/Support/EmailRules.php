<?php

namespace App\Modules\Staff\Support;

use Illuminate\Support\Str;

/**
 * The one definition of a staff email (FR-001, research R7, R9).
 *
 * Printable ASCII only: the email column's collation would otherwise match look-alike letters
 * and ignore control characters, letting variants dodge the per-email sign-in limit.
 */
class EmailRules
{
    /**
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['string', 'regex:/^[\x21-\x7E]+$/D', 'email', 'max:255'];
    }

    /**
     * Emails are stored and matched trimmed and lower-cased.
     */
    public static function normalize(mixed $email): mixed
    {
        return is_string($email) ? Str::lower(trim($email)) : $email;
    }
}
