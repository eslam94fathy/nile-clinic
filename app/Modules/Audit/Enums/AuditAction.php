<?php

namespace App\Modules\Audit\Enums;

/**
 * Every action the trail records (FR-003–FR-005, FR-011; data-model.md). A new sensitive action adds
 * a case here, a call inside its transaction, and a test method in AuditActionCoverageTest.
 */
enum AuditAction: string
{
    case AuthSignIn = 'auth.sign_in';
    case AuthSignOut = 'auth.sign_out';
    case AuthPasswordChanged = 'auth.password_changed';

    case StaffCreated = 'staff.created';
    case StaffUpdated = 'staff.updated';
    case StaffDeactivated = 'staff.deactivated';
    case StaffReactivated = 'staff.reactivated';
    case StaffPasswordReset = 'staff.password_reset';

    case PatientRegistered = 'patient.registered';
    case PatientUpdated = 'patient.updated';
    case PatientOpened = 'patient.opened';

    case SpecialtyCreated = 'specialty.created';
    case SpecialtyUpdated = 'specialty.updated';
    case SpecialtyDeactivated = 'specialty.deactivated';
    case SpecialtyReactivated = 'specialty.reactivated';
    case DoctorCreated = 'doctor.created';
    case DoctorUpdated = 'doctor.updated';

    case ScheduleSet = 'schedule.set';
    case ScheduleCleared = 'schedule.cleared';
    case TimeOffAdded = 'time_off.added';
    case TimeOffRemoved = 'time_off.removed';

    case AppointmentBooked = 'appointment.booked';
    case AppointmentRescheduled = 'appointment.rescheduled';
    case AppointmentCancelled = 'appointment.cancelled';
    case AppointmentNoShow = 'appointment.no_show';

    case VisitCheckedIn = 'visit.checked_in';
    case VisitWalkInAdded = 'visit.walk_in_added';
    case VisitLeft = 'visit.left';
    case VisitCheckInUndone = 'visit.check_in_undone';
    case VisitCalled = 'visit.called';
    case VisitFinished = 'visit.finished';

    case EncounterStarted = 'encounter.started';
    case EncounterDraftSaved = 'encounter.draft_saved';
    case EncounterFinalized = 'encounter.finalized';
    case EncounterAddendumAdded = 'encounter.addendum_added';
    case EncounterOpened = 'encounter.opened';
    case EncounterHistoryViewed = 'encounter.history_viewed';

    case FeeSet = 'fee.set';
    case ChargeCreated = 'charge.created';
    case PaymentRecorded = 'payment.recorded';
    case RefundRecorded = 'refund.recorded';
    case DiscountSet = 'discount.set';
    case ChargeCancelled = 'charge.cancelled';

    case AuditSearched = 'audit.searched';
}
