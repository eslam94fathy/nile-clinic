<?php

namespace App\Modules\Payments\Enums;

/**
 * How money was received or returned. This records the method only: no card numbers or other
 * credentials are stored (FR-010).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Wallet = 'wallet';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
