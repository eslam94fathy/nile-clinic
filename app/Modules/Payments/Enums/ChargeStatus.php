<?php

namespace App\Modules\Payments\Enums;

/**
 * A charge's status (data-model.md). It always follows from the charge's figures, except `cancelled`,
 * which is final (research R3).
 */
enum ChargeStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
