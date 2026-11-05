<?php

namespace App\Modules\Queues\Enums;

/**
 * A queue entry's status (data-model.md). Transitions: waiting → with_doctor (call next);
 * with_doctor → seen (finish); waiting → left (leave). Seen and left are final.
 */
enum QueueStatus: string
{
    case Waiting = 'waiting';
    case WithDoctor = 'with_doctor';
    case Seen = 'seen';
    case Left = 'left';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
