<?php

namespace App\Modules\Audit\Enums;

/**
 * Who acted: a staff member, the system (a command-line action), or nobody known (a sign-in for an
 * email matching no account) (FR-002, research R6).
 */
enum AuditActorType: string
{
    case Staff = 'staff';
    case System = 'system';
    case Unknown = 'unknown';
}
