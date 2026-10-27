<?php

namespace App\Modules\Staff\Enums;

/**
 * The fixed set of staff roles (FR-002); adding one requires an approved specification.
 */
enum Role: string
{
    case Administrator = 'administrator';
    case Receptionist = 'receptionist';
    case Doctor = 'doctor';
}
