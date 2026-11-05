# Data Model: Reports

**Feature**: 010-reports | **Date**: 2026-11-05

This feature adds **no tables, no columns, and no models**. It reads the tables of features 002–009 and adds three
indexes in one new migration ([research R3](research.md)); no applied migration is edited. Dates are the clinic's
wall-clock date (`Africa/Cairo`); `*_at` columns are UTC instants, as in features 005–009.

## New indexes

Migration: `database/migrations/<date>_add_report_indexes.php`. Its `down` drops the three indexes.

| Table | Index | Column | Serves |
|-------|-------|--------|--------|
| `payments` | `payments_created_at_index` | `created_at` | Money collected |
| `refunds` | `refunds_created_at_index` | `created_at` | Money collected |
| `patients` | `patients_created_at_index` | `created_at` | New patients count |

## What each report reads

Only the columns listed are selected. Any other column, including all clinical text, patient details, and reasons, is never read.

| Table | Columns | Filter on | Report |
|-------|---------|-----------|--------|
| `payments` | `amount`, `method`, `received_by`, `created_at`, `charge_id` | `created_at` | Money collected |
| `refunds` | `amount`, `method`, `refunded_by`, `created_at`, `charge_id` | `created_at` | Money collected |
| `charges` | `doctor_id` | `doctor_id`, joined only for the filter | Money collected |
| `charges` | `doctor_id`, `status`, `fee`, `discount`, `amount_paid` | `date`, `doctor_id` | Billing |
| `queue_entries` + `charges` | `doctor_id`; `charges.id` | `date`, `doctor_id`, `status = seen`, `active_flag = 1` on the join | Billing (uncharged) |
| `appointments` | `doctor_id`, `status` | `date`, `doctor_id` | Activity |
| `queue_entries` | `doctor_id`, `status`, `appointment_id` | `date`, `doctor_id` | Activity |
| `patients` | count only | `created_at` | Activity |
| `queue_entries` | `doctor_id`, `arrived_at`, `called_at`, `finished_at` | `date`, `doctor_id` | Times |
| `queue_entries` + `encounters` | `doctor_id`; `encounters.status` | `date`, `doctor_id`, `status = seen` | Times (records) |
| `doctors` + `users` | `doctors.id`, `doctors.title`, `users.name` | `doctors.id` | All per-doctor rows |
| `users` | `id`, `name` | `id` | Money collected (staff) |

## Calculated figures

All are calculated when requested; none is stored. Money is summed exactly, in whole piastres ([research R6](research.md)).

### Money collected

Filtered by the clinic day each row was recorded, and by the charge's doctor if a doctor filter is sent.

| Figure | Definition |
|--------|------------|
| `received` | Sum of payment amounts |
| `refunded` | Sum of refund amounts |
| `net` | `received - refunded`; can be negative |

Each is given in total, by method, by staff member within each method, and by day. A staff member's figures are the payments they
received and the refunds they recorded. Only methods and days with money movement are listed.

### Billing

Filtered by the charge's date. Figures show charges as they stand now.

| Figure | Definition |
|--------|------------|
| `active_charges` | Count of charges not `cancelled` |
| `fees`, `discounts`, `amount_paid` | Sums over active charges |
| `amount_due` | `fees - discounts` |
| `balance` | `amount_due - amount_paid`; never negative, because of `charges_money_check` |
| `cancelled_charges` | Count of charges `cancelled`; not in any money figure |
| `uncharged_seen_visits` | Visits `seen` on a date in the period, with no active charge |

### Activity

| Figure | Definition |
|--------|------------|
| `appointments.{status}` | Count for each of `scheduled`, `cancelled`, `no_show`, `checked_in`, `completed` |
| `no_show_rate` | Percentage to one decimal: `no_show / (no_show + checked_in + completed)`; `null` if the divisor is 0 |
| `visits.{status}` | Count for each of `waiting`, `with_doctor`, `seen`, `left` |
| `visits.booked`, `visits.walk_in` | Count with and without an appointment |
| `new_patients` | Patients registered in the period. Overall only, and left out when a doctor filter is sent |

### Times and records

| Figure | Definition |
|--------|------------|
| `wait.average_minutes` | Average of `called_at - arrived_at`, nearest whole minute; `null` with no visits |
| `wait.visits` | Visits with `called_at` |
| `consultation.average_minutes` | Average of `finished_at - called_at`, nearest whole minute; `null` with no visits |
| `consultation.visits` | Visits with both `called_at` and `finished_at` |
| `records.finalized`, `records.draft`, `records.none` | Seen visits by the state of their medical record; they add up to the number of seen visits |

Overall averages are calculated from all the visits, not from the doctors' averages.

## State and history

Reports have no states and hold no history. Money collected for a past period never changes, because payments and refunds are insert-only.
Billing and activity for a past period can change, as their records change.
