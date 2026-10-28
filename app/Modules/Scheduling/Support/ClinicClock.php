<?php

namespace App\Modules\Scheduling\Support;

use Carbon\CarbonImmutable;

/**
 * Today and now on the clinic's wall clock. The only reader of app.clinic_timezone (research
 * R2); the application itself stays on UTC. Follows travelTo() in tests.
 */
final class ClinicClock
{
    public static function timezone(): string
    {
        return config('app.clinic_timezone');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * Today's date at the clinic, as Y-m-d.
     */
    public static function today(): string
    {
        return self::now()->toDateString();
    }
}
