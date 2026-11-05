<?php

namespace App\Modules\Audit\Enums;

/**
 * The type of record an entry acted on. Referred to by type and internal identifier only, because
 * entries outlive removed records (research R10).
 */
enum AuditSubject: string
{
    case StaffMember = 'staff_member';
    case Patient = 'patient';
    case Specialty = 'specialty';
    case Doctor = 'doctor';
    case TimeOff = 'time_off';
    case Appointment = 'appointment';
    case Visit = 'visit';
    case Encounter = 'encounter';
    case Charge = 'charge';
}
