<?php

namespace App\Modules\Appointments\Enums;

/**
 * An appointment's status (research R5). Transitions: scheduled → scheduled (reschedule, not
 * started); scheduled → cancelled (not started); scheduled → no_show (started); scheduled →
 * checked_in (check-in, feature 007); checked_in → scheduled (undo); checked_in → completed
 * (finish). Cancelled, no_show, and completed are final.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The statuses in which an appointment keeps its slot (feature 007, research R2).
     *
     * @return list<string>
     */
    public static function holdingSlot(): array
    {
        return [self::Scheduled->value, self::CheckedIn->value, self::Completed->value];
    }
}
