<?php

// Scheduling module messages: __('scheduling::messages.<key>').

return [
    'period_end_before_start' => 'On :day, a period must end after it starts.',
    'period_shorter_than_appointment' => 'On :day, a period must be at least one appointment long.',
    'periods_overlap' => 'On :day, periods must not overlap or touch.',
    'too_many_periods' => 'On :day, there can be at most :max periods.',
    'range_too_long' => 'The date range can be at most :days days.',
    'beyond_horizon' => 'Slots can be requested at most :days days ahead.',
    'time_off_times_single_day' => 'Times can only be given for time off on a single day.',
    'time_off_ended' => 'This time off has already ended and cannot be removed.',
    'time_off_not_found' => 'No time off was found.',
];
