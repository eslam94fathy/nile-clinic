# Research: Payments

**Feature**: 009-payments | **Date**: 2026-11-05

Facts below were checked on 2026-11-05 against `main` at `9baa5ee` and the running stack: Laravel
Framework 13.38.0, PHP 8.5.12, MySQL 9.7.4, and the existing `Staff`, `Patients`, `Doctors`,
`Scheduling`, `Appointments`, `Queues`, and `Encounters` modules. Spec clarifications Q1–Q3 were
answered on 2026-11-05 (spec, Clarifications). Items marked *(approved — Dn)* were approved by the user on 2026-11-05
and are listed in the plan's Decisions table.

## R1 — New `Payments` module and URLs *(approved — D3)*

- **Decision**: A new module, `app/Modules/Payments/`:
  - `GET /fees`, `GET /doctors/{doctor}/fee`, `PUT /doctors/{doctor}/fee` — fees;
  - `GET /visits?date&doctor_id&charge_status` — a date's visits with their charge status;
  - `POST /visits/{visit}/charge` — charge a visit;
  - `GET /charges`, `GET /charges/{charge}` — find charges;
  - `POST /charges/{charge}/payments`, `POST /charges/{charge}/refunds` — money in and out;
  - `PUT /charges/{charge}/discount`, `POST /charges/{charge}/cancel`.
- **Rationale**: Same shapes as features 004–008 (`POST …/{action}`, nested resources, one record per
  parent uses a singular path). `{visit}` is the queue entry id. `/visits` does not clash with
  `/doctors/me/visits` (feature 008).
- **Module direction**: `Payments` depends on `Queues`, `Doctors`, and `Patients`, and not on
  `Encounters`, so it cannot reach medical content.

## R2 — Exact money *(approved — D1)*

- **Finding**: In PHP, `0.1 + 0.2 == 0.3` is false (measured). Laravel's `decimal:2` cast returns a string
  (measured: `'150.10'`, and a float `150.1` becomes `'150.10'`), so values read from the database are
  exact until something does arithmetic on them.
- **Decision**:
  - **Stored** as `DECIMAL(10,2)` (Constitution XI).
  - **Calculated** in whole piastres as integers, through a small `Payments\Support\Money` helper
    (`toPiastres(string): int`, `fromPiastres(int): string`) that parses the digits and never goes
    through a float. No arithmetic happens on strings or floats.
  - **Returned** as strings with two decimals (`"150.00"`), so a client's JSON parser cannot change them.
  - **Accepted** as a JSON number or string, validated with `numeric`, `decimal:0,2`, `gt:0`, and
    `max:100000`.
- **Measured validation**: Accepted `150`, `150.5`, `150.25`, `'150.25'`, `0.01`, `100000`, `99999.99`.
  Refused `100000.01`, `0`, `-5`, `1.999`, `'1.999'`, `'1e3'`, Arabic digits, `' 12.50'`, `true`, null.
- **Known limit**: The float `0.30000000000000004` was accepted, because JSON numbers are decoded before
  validation and PHP's string form of it is `0.3`. It is stored as `0.30`, which is what such a client
  meant. A client that wants no float at all can send strings.
- **Alternatives**: `bcmath` — not needed for add, subtract, and compare on integers. `brick/money` — a new
  dependency for one helper. Storing integer piastres — the Constitution requires `DECIMAL`.

## R3 — Charge figures and database rules *(approved — D2)*

- **Decision**: `charges` stores `fee`, `discount`, `amount_paid`, and `status`. `payments` and `refunds`
  are insert-only rows. Each change locks the charge row, inserts its row, and updates `amount_paid` and
  `status` in one transaction. `amount_due` and `balance` are calculated when read, never stored.
- **Rationale**: Filtering by status and showing balances in lists stay single indexed queries, without
  summing payments for every row.
- **Status rule** (FR-007): cancelled stays cancelled; otherwise balance 0 → `paid`; amount paid 0 →
  `unpaid`; else `partially_paid`. A whole-fee discount gives balance 0, so `paid`. A full refund gives
  `unpaid` again.
- **Database rules, verified on MySQL 9.7.4** with a scratch table in `clinic_testing`, since dropped:
  - `CHECK (discount >= 0 AND amount_paid >= 0 AND discount + amount_paid <= fee)` refused paid above the
    fee, discount plus paid above the fee, and negative paid (error 3819). Overpayment is impossible even
    if the application has a bug (Constitution XI).
  - A generated `active_flag = IF(status <> 'cancelled', 1, NULL)` with `UNIQUE (queue_entry_id,
    active_flag)` refused a second active charge on one visit (error 1062) and allowed two cancelled ones.
- **Reconciliation**: A test asserts `amount_paid` equals payments less refunds after every action, so the
  stored figure cannot drift unnoticed.
- **Alternatives**: Summing rows on each read — slower lists, and status filtering becomes a subquery.

## R4 — Undoing a check-in *(approved — D4)*

- **Finding**: `QueueService::undo` deletes the queue entry. A charge referencing it with `RESTRICT` would
  turn the undo into a server error. Spec FR-014 asks for a validation refusal while a charge is active,
  and for a cancelled charge to stay after the visit is gone.
- **Decision**:
  - `Queues` declares `Repositories/Contracts/VisitChargeRepository::hasActiveCharge(int $queueEntryId):
    bool`, and `Payments` implements and binds it. This is the existing pattern from
    `Scheduling\…\BookedTimeRepository`, which `Appointments` implements, so the dependency stays one way.
  - `undo` calls it right after `lockWaiting`, and refuses with 422 `has_active_charge` on `entry`. New
    message in `queues::messages`, in both locales.
  - `charges.queue_entry_id` is nullable with `ON DELETE SET NULL`. **Verified**: with the visit deleted,
    three rows stayed and `queue_entry_id` became null, alongside the `CHECK` and generated unique index.
- **Concurrency**: `undo` holds the entry lock when it checks, and charging takes the same lock first, so
  the two run one after the other. The check is the transaction's first plain read, so it sees committed
  data (feature 007 R3).
- **Alternatives**: Refusing to charge `waiting` visits — no undo conflict, but patients often pay before
  they are seen. `Queues` reading the `charges` table directly — a dependency the wrong way.

## R5 — Charging a visit

- **Decision**: `DoctorFee` lookup and the existing-charge check happen inside a transaction, after locking
  the queue entry (`QueueRepository::lockById`, first). Unknown → 404 `visit_not_found`. `left` → 422
  `visit_left` on `visit`. Active charge exists → 422 `already_charged` on `visit`. No fee → 422 `no_fee` on
  `visit`. Otherwise insert with `fee` from `DoctorFee`, and `doctor_id`, `patient_id`, and `date` copied
  from the entry (FR-005).
- **Lock order**: Charging locks only the entry. Feature 007 locks queue day → entry, so there is no cycle.
- **Backstop**: The unique index cannot fire while the entry is locked, and is not mapped.

## R6 — Payments, refunds, discounts, cancelling

All lock the charge, then re-read its figures from the locked row. Cancelled → 422 `charge_cancelled` on
`charge`.

- **Payment**: amount above the balance → 422 `above_balance` on `amount`. Simultaneous payments run one
  after the other; the second sees the new balance.
- **Refund**: administrators only; amount above `amount_paid` → 422 `above_paid` on `amount`. `reason`
  required, up to 500 characters.
- **Discount**: administrators only; `amount` 0 or above, up to the fee less `amount_paid`, else 422
  `discount_too_large` on `amount`. Replaces any earlier discount; `reason` required. Sending 0 removes a
  discount, with the reason recorded.
- **Cancel**: `amount_paid` above 0 → 422 `money_held` on `charge`. `reason` required. Sets `active_flag` to
  null through the status, so the visit can be charged again.
- **Reason text**: Staff-entered free text, shown only when one charge is opened; never in lists or logs.
  Its description tells staff not to enter medical details.

## R7 — Fees

- **Decision**: `doctor_fees` has one row per doctor (`UNIQUE doctor_id`), holding the current amount, who
  set it, and when. `PUT` uses an upsert, so simultaneous first settings do not collide. Past amounts live
  on the charges made at them; there is no separate fee history (spec, Key Entities).
- **Reading**: `GET /doctors/{doctor}/fee` returns `amount: null` for a doctor with no fee, and 404 for an
  unknown doctor. `GET /fees` pages through all doctors by account name, each with their fee or null.

## R8 — Visit list

- **Decision**: `paginateVisits` reads `queue_entries` left-joined to `charges` on `queue_entry_id` and
  `active_flag = 1`, ordered by `doctor_id`, `order_minute`, `queue_number`. `charge_status=none` means no
  active charge.
- **Left visits**: A left visit cannot be newly charged, so it would only add noise under `none`. It is
  listed only if it has an active charge, which still needs settling.
- **Date**: One required `date`, since this answers "what happened at the desk that day". Past dates are
  allowed. `GET /charges` covers ranges of at most 92 days, reusing `ListAppointmentsRequest::validateRange`.
- **Indexes**: `queue_entries_date_index` serves the date; the unique index serves the join.

## R9 — Authorization

- **Decision**: Class-level policies, as in every module except feature 008's record rule:
  - `DoctorFeePolicy`: `viewAny` active administrator or receptionist; `set` active administrator.
  - `ChargePolicy`: `viewAny`, `create`, `pay`, `cancel` active administrator or receptionist; `discount`,
    `refund` active administrator.
- Doctors get 403 before any lookup (FR-020).

## R10 — Responses and privacy

- **Patient**: `{id, full_name, phone}`, the staff summary from feature 007 (FR-019).
- **Lists**: `ChargeSummary` leaves out reasons, payments, and refunds. No medical content exists to leak,
  since the module does not depend on `Encounters`.
- **Credentials**: A payment holds only its method (FR-010). No reference field exists, so a card number
  has nowhere to go.
- **Logs**: No new log lines; exceptions carry no patient data or amounts.

## R11 — Testing

- Feature tests per story with `travelTo`, as in features 007–008.
- A `concurrency` group with real PHP processes (feature 006 R9): many charges on one visit, payments that
  together exceed the balance, refunds that together exceed the amount paid, and a charge against an undo.
- SC-004: 100 payments of `0.07` on a `7.00` fee end at exactly `paid` with balance `0.00`.
- `Money` has a unit test, including `.5`, `5.`, and `100000`, which pass the validation rule.
- SC-006 is measured once with synthetic rows in `clinic_testing` and recorded in `verification.md`.
