<?php

namespace App\Modules\Appointments\Enums;

/**
 * An appointment's status (research R5). Transitions: scheduled → scheduled (reschedule, not
 * started); scheduled → cancelled (not started); scheduled → no_show (started). Cancelled and
 * no_show are final.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
