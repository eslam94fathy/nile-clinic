<?php

namespace App\Modules\Audit\Enums;

/**
 * How a recorded action ended (FR-002).
 */
enum AuditOutcome: string
{
    case Succeeded = 'succeeded';
    case Refused = 'refused';
}
