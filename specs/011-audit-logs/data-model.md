# Data Model: Audit Logs

**Feature**: 011-audit-logs | **Date**: 2026-11-05

One new table in one new migration; no applied migration is edited. `created_at` is a UTC instant, as in features 005–010.
Date filters are the clinic's wall-clock date (`Africa/Cairo`).

## `audit_entries`

Migration: `database/migrations/<date>_create_audit_entries_table.php`. InnoDB, `utf8mb4`. Its `down` drops the table. Rows are only ever inserted.

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | `bigint unsigned`, auto-increment | No | Primary key; also the search order and cursor |
| `action` | `varchar(40)` | No | An `AuditAction` value; see below |
| `outcome` | `varchar(10)` | No | `succeeded` or `refused` |
| `actor_type` | `varchar(10)` | No | `staff`, `system`, or `unknown` |
| `actor_id` | FK `users.id`, `restrict` | Yes | Set only when `actor_type` is `staff` |
| `actor_role` | `varchar(20)` | Yes | The role at that moment; never updated afterwards |
| `subject_type` | `varchar(20)` | Yes | An `AuditSubject` value; null for an unknown sign-in and a search |
| `subject_id` | `bigint unsigned` | Yes | **No foreign key**, because entries outlive removed records (research R10) |
| `patient_id` | FK `patients.id`, `restrict` | Yes | Set when the action concerns a patient |
| `network_address` | `varchar(45)` | Yes | IPv4 or IPv6; null for `system` |
| `details` | `json` | Yes | See below |
| `created_at` | `timestamp` | No | When it happened; no `updated_at` |

**Indexes**: `actor_id`, `patient_id`, (`subject_type`, `subject_id`), `action`, `created_at`.

### `details`

Holds names, allowed values, and internal identifiers only (FR-006, FR-008). Each key is optional.

```json
{
  "fields": ["discount", "discount_reason", "status"],
  "values": {
    "discount": { "from": "0.00", "to": "200.00" },
    "status": { "from": "unpaid", "to": "paid" }
  },
  "filters": { "patient_id": 12, "from": "2026-11-01" }
}
```

- `fields`: names of the fields changed, without `id`, `*_at`, `*_by`, or derived columns (research R5). The entry already says who and when.
- `values`: only the allow-listed fields in research R5. `from` is null on creation.
- `filters`: only on `audit.searched`, the validated filters of that search (FR-011).

Passwords, tokens, reasons, clinical text, patient details, and search text never enter this column, by any path (FR-008).

## Enums

`AuditOutcome`: `succeeded`, `refused`.

`AuditActorType`: `staff`, `system`, `unknown`.

`AuditSubject`: `staff_member`, `patient`, `specialty`, `doctor`, `time_off`, `appointment`, `visit`, `encounter`, `charge`.

`AuditAction` has 44 cases:

| Source | Action | Subject | `patient_id` | Details |
|--------|--------|---------|--------------|---------|
| Auth (002) | `auth.sign_in` | `staff_member` (null if unknown) | | |
| | `auth.sign_out` | `staff_member` | | |
| | `auth.password_changed` | `staff_member` | | `fields` |
| Staff (002) | `staff.created` | `staff_member` | | `fields`, `values` |
| | `staff.updated`, `staff.deactivated`, `staff.reactivated` | `staff_member` | | `fields`, `values` |
| | `staff.password_reset` | `staff_member` | | `fields` |
| Patients (003) | `patient.registered`, `patient.updated` | `patient` | Yes | `fields` |
| | `patient.opened` | `patient` | Yes | |
| Doctors (004) | `specialty.created`, `specialty.updated`, `specialty.deactivated`, `specialty.reactivated` | `specialty` | | `fields`, `values` |
| | `doctor.created`, `doctor.updated` | `doctor` | | `fields` |
| Schedules (005) | `schedule.set`, `schedule.cleared` | `doctor` | | `fields` on set |
| | `time_off.added`, `time_off.removed` | `time_off` | | `fields` on add |
| Appointments (006) | `appointment.booked`, `appointment.rescheduled`, `appointment.cancelled`, `appointment.no_show` | `appointment` | Yes | `fields`, `values` |
| Visits (007) | `visit.checked_in`, `visit.walk_in_added`, `visit.left`, `visit.check_in_undone`, `visit.called`, `visit.finished` | `visit` | Yes | `fields`, `values` |
| Medical records (008) | `encounter.started`, `encounter.draft_saved`, `encounter.finalized`, `encounter.addendum_added` | `encounter` | Yes | `fields`, `values` on `status` |
| | `encounter.opened` | `encounter` | Yes | Outcome `succeeded` or `refused` |
| | `encounter.history_viewed` | `patient` | Yes | Outcome `succeeded` or `refused` |
| Money (009) | `fee.set` | `doctor` | | `fee` from and to |
| | `charge.created`, `discount.set`, `charge.cancelled` | `charge` | Yes | `fields`, `values` |
| | `payment.recorded`, `refund.recorded` | `charge` | Yes | `amount_paid`, `status` from and to |
| Audit (011) | `audit.searched` | null | | `filters` |

`auth.sign_in` is the only other action with the outcome `refused`.

## Rules

- **Append only**: `AuditEntryRepository` offers `append` and `search`, with no update or delete (FR-014). No route writes (research R1).
- **No entry for no change**: `staff.updated`, `staff.deactivated`, `staff.reactivated`, `patient.updated`, `specialty.*` except `created`, `doctor.updated`, `schedule.set`, `schedule.cleared`, `fee.set`, and
  `encounter.draft_saved` write nothing when nothing changed (FR-007). Others always write, since they always do something, including `discount.set`.
- **Same transaction**: Each change's entry is written inside the service's transaction, and rolls back with it (FR-007, research R3).
- **Openings**: If the entry fails, the record is not returned (FR-007).
- **Refusals**: If the entry fails, the error is reported and the response stays the same (FR-005, research R7).

## Validation (`GET /audit-entries`)

| Parameter | Rule |
|-----------|------|
| `from`, `to` | Optional clinic dates, `Y-m-d`; `to` not before `from` when both are sent; no maximum length |
| `staff_id`, `patient_id` | Optional positive integers; unknown ids return an empty page, not an error |
| `subject_type` | Optional `AuditSubject` value; required with `subject_id` |
| `subject_id` | Optional positive integer; required with `subject_type` |
| `action` | Optional `AuditAction` value |
| `outcome` | Optional `AuditOutcome` value |
| `per_page` | Optional integer 1–100; default 15 |
| `cursor` | Optional opaque string from an earlier response; one that does not decode to a position is refused, rather than failing with a server error |

Authorization runs first, so non-administrators get `403` whatever they send.
