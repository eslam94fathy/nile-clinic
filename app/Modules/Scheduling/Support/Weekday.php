<?php

namespace App\Modules\Scheduling\Support;

/**
 * Days of the week, numbered as PHP and Carbon number them (Sunday = 0). The API uses the
 * lower-case English names, which are never translated (research R4).
 */
enum Weekday: int
{
    case Sunday = 0;
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;

    /**
     * The API identifier, e.g. "sunday".
     */
    public function name(): string
    {
        return strtolower($this->name);
    }

    public static function fromName(string $name): self
    {
        return constant(self::class.'::'.ucfirst($name));
    }

    /**
     * @return list<string> sunday … saturday
     */
    public static function names(): array
    {
        return array_map(fn (self $day) => $day->name(), self::cases());
    }
}
