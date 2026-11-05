# Feature Specification: Payments

**Feature Branch**: `009-payments`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "Payments" (from `docs/roadmap.md`, feature 009: "fees and payments stored as decimals; historical prices preserved on each record")

## Context

Patients now book, arrive, and see a doctor, but the clinic has no record of **what a visit costs or
whether it was paid**. This feature lets administrators set each doctor's consultation fee, and lets
reception charge a visit and record the money received. Staff can then see what is still owed.

A charge belongs to **one visit**, meaning one queue entry from feature 007, so booked patients and
walk-ins are treated the same way. It keeps the fee as it was when charged, so later price changes
never alter past records (Constitution XI).

This feature **records** money handled at the clinic; it does not move money. There is no card
processing, online payment, or patient-facing screen (Constitution II). Financial summaries belong to
"Reports" (010), and a history of who changed what belongs to "Audit logs". Patients come from feature
003, doctors from 004, visits from 007, and staff roles from 002. This feature holds no medical
content and reads none from feature 008.

## Clarifications

### Session 2026-11-05

- Q: What can a visit be charged for? → A: Only the doctor's consultation fee, which administrators can
  discount. Other priced services are out of scope (FR-003, US4).
- Q: When is a visit charged, and does payment affect the queue? → A: Reception charges a visit when it
  is needed, and payment never affects the queue. A list shows visits not yet charged (FR-004, FR-016).
- Q: How is money received corrected, and by whom? → A: Payments cannot be changed. Only administrators
  record refunds, each with a reason (FR-015, US5).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An administrator sets a doctor's consultation fee (Priority: P1)

The clinic decides what each doctor charges. An administrator sets or changes a doctor's fee, and
reception can look it up to answer "how much is the visit?".

**Why this priority**: A visit cannot be charged until a price exists.

**Independent Test**: Set a doctor's fee, read it back as a receptionist, change it, and confirm the
new amount is shown.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator and a doctor, **When** they set the fee, **Then** the amount is
   stored exactly to two decimal places, with who set it and when.
2. **Given** a doctor with a fee, **When** an administrator changes it, **Then** the new fee applies to
   later charges, and existing charges keep the amount they were charged at.
3. **Given** an amount that is zero, negative, above 100,000.00, or has more than two decimal places,
   **When** it is submitted, **Then** it is refused with a validation message and nothing changes.
4. **Given** a signed-in receptionist or administrator, **When** they read a doctor's fee or the list of
   fees, **Then** it is returned; a doctor with no fee shows none; an unknown doctor is reported as not
   found.
5. **Given** a signed-in receptionist or doctor, **When** they try to set a fee, **Then** it is refused
   as forbidden; **Given** a signed-in doctor, **When** they try to read fees, **Then** it is refused as
   forbidden.

---

### User Story 2 - Reception charges a visit and records the payment (Priority: P1)

A patient comes to the desk. The receptionist charges the visit at the doctor's fee and records what
the patient paid, in full or in part, and how.

**Why this priority**: This is the purpose of the feature. Together with US1 it is the minimum usable
slice.

**Independent Test**: Charge a visit, record part of the fee, confirm the balance, record the rest, and
confirm the charge shows as paid.

**Acceptance Scenarios**:

1. **Given** a signed-in receptionist or administrator, a visit, and a doctor with a fee, **When** they
   charge the visit, **Then** one charge is created with the fee at that moment, the patient, the
   doctor, the visit's date, the staff member, and status `unpaid`.
2. **Given** a doctor with no fee, **When** their visit is charged, **Then** it is refused with a
   validation message and nothing is created.
3. **Given** a visit marked as left, or an unknown visit, **When** it is charged, **Then** it is refused
   and nothing is created.
4. **Given** a visit that already has an active charge, or two charge requests for one visit at the same
   moment, **When** they are processed, **Then** the visit ends up with exactly one active charge and
   the extra request is refused with a validation message.
5. **Given** a charge, **When** a payment is recorded with an amount and a method (`cash`, `card`, or
   `wallet`), **Then** it is stored with the staff member and time, and the balance and status are
   updated: `partially_paid` while something is owed, `paid` when nothing is.
6. **Given** a charge, **When** a payment is above the balance, is zero or negative, or has more than two
   decimal places, **Then** it is refused with a validation message and nothing changes.
7. **Given** two payments at the same moment that together exceed the balance, **When** both are
   processed, **Then** the total received never exceeds what is owed, and the other is refused.
8. **Given** a signed-in doctor, **When** they use any charge or payment action, **Then** it is refused
   as forbidden.

---

### User Story 3 - Reception finds charges and what is owed (Priority: P2)

Reception wants to see which of today's visits have not been charged, what a patient owes, and what was
received.

**Why this priority**: Forgotten charges and unpaid balances are lost income, but a clinic can start by
charging at the desk.

**Independent Test**: Create visits with no charge, an unpaid charge, and a paid charge. Filter by each
status and confirm the expected visit is returned.

**Acceptance Scenarios**:

1. **Given** visits on a date, **When** reception lists visits for that date, optionally by doctor and
   charge status (`none`, `unpaid`, `partially_paid`, `paid`), **Then** each shows the doctor, the
   patient summary (patient number, full name, phone), its charge status, and its balance.
2. **Given** charges, **When** reception lists them for a date range of at most 92 days, optionally by
   patient, doctor, and status, **Then** they are returned newest first, in pages of a bounded size.
3. **Given** one charge, **When** it is opened, **Then** it shows the fee charged, discount, amount due,
   amount paid, balance, status, and every payment and refund with its method, amount, time, and staff
   member.
4. **Given** any of these responses, **Then** they never include medical content, or the patient's
   national ID, address, or emergency contact.

---

### User Story 4 - An administrator gives a discount (Priority: P3)

The clinic reduces or waives a fee for a patient in need or for a staff member's relative.

**Why this priority**: Discounts happen, but less often than the main flow.

**Independent Test**: Discount a charge, confirm the amount due and balance fall, then discount it to
zero and confirm it shows as paid.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator and a charge, **When** they set a discount with a reason, **Then**
   the amount due falls, the original fee stays visible, and the reason, staff member, and time are
   recorded. A later discount replaces the earlier one.
2. **Given** a discount larger than the fee less what has been paid, or with no reason, **When** it is
   submitted, **Then** it is refused with a validation message.
3. **Given** a discount of the whole fee, **When** it is set, **Then** nothing is owed and the charge
   shows as `paid`.
4. **Given** a signed-in receptionist or doctor, **When** they try to set a discount, **Then** it is
   refused as forbidden.

---

### User Story 5 - Correcting mistakes (Priority: P3)

A patient paid too much, a visit was charged by mistake, or a payment was recorded against the wrong
visit.

**Why this priority**: Financial records must stay correct, but corrections are rare.

**Independent Test**: Refund a payment as an administrator, confirm the balance rises and the original
payment is unchanged, then cancel the charge and confirm the visit can be charged again.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator and a charge with money received, **When** they record a refund
   with an amount, a method, and a reason, **Then** it is stored with the staff member and time, the
   amount paid falls, the balance rises, the status updates, and the original payment is unchanged.
2. **Given** a refund above the amount paid, zero or negative, with more than two decimal places, or with
   no reason, **When** it is submitted, **Then** it is refused with a validation message.
3. **Given** two refunds at the same moment that together exceed the amount paid, **When** both are
   processed, **Then** the total refunded never exceeds what was received, and the other is refused.
4. **Given** a charge with no money still held, **When** it is cancelled with a reason, **Then** it shows
   as `cancelled`, stays on record, takes no more payments, and the visit can be charged again.
5. **Given** a charge with money still held, **When** someone tries to cancel it, **Then** it is refused
   with a validation message.
6. **Given** a visit with an active charge, **When** its check-in is undone (feature 007), **Then** the
   undo is refused with a validation message until the charge is cancelled.
7. **Given** a signed-in receptionist or doctor, **When** they try to record a refund, **Then** it is
   refused as forbidden; **Given** a signed-in doctor, **When** they try to cancel a charge, **Then** it
   is refused as forbidden.

---

### Edge Cases

- **Fee changes**: A charge keeps the fee it was created with, whatever happens to the doctor's fee later.
- **When a visit can be charged**: While waiting, with the doctor, or seen, on the visit's day or any
  later day. A visit marked as left cannot be newly charged; an existing charge on it stays and can be
  paid, refunded, or cancelled.
- **Payment and care**: Payment status never blocks a check-in, a call, finishing a visit, or a medical
  record. Whether a patient pays before being seen is the clinic's own practice.
- **Undoing a check-in**: Feature 007 removes the visit when a check-in is undone, so it must be refused
  while the visit has an active charge. A cancelled charge stays on record after the visit is gone, with
  its patient, doctor, and date.
- **No visit, no charge**: Cancelled and no-show appointments have no visit, so they are not charged in
  this feature. Deposits and no-show fees are out of scope.
- **Inactive doctors**: A deactivated doctor's fee stays, and their visits can still be charged, because
  the visit took place.
- **Exact money**: Every amount is exact to two decimal places. Totals never pick up rounding errors,
  including many small part payments on one charge.
- **Currency**: One currency, Egyptian pounds; amounts carry no currency field.
- **Backdating**: A payment or refund carries the time it was recorded. It cannot be entered with an
  earlier date.
- **No deletion**: Charges, payments, and refunds are never deleted, and payments are never edited.
- **Card details**: Card numbers are never stored; a payment holds only its method.
- **Languages**: Messages are returned in English or Arabic with identical structure; statuses and
  methods are fixed English identifiers, never translated.
- **Clinic time**: All dates and times are the clinic's local wall-clock time (Egypt), as in features
  005–008.

## Requirements *(mandatory)*

### Functional Requirements

**Fees**

- **FR-001**: Administrators MUST be able to set and change each doctor's consultation fee. Receptionists
  and administrators MUST be able to read fees.
- **FR-002**: A fee MUST be above 0 and at most 100,000.00, exact to two decimal places.

**Charges**

- **FR-003**: A charge MUST cover only the doctor's consultation fee. Reception MUST NOT enter the amount;
  it comes from the doctor's fee at that moment.
- **FR-004**: Receptionists and administrators MUST be able to charge a visit that is waiting, with the
  doctor, or seen, on its day or any later day. Charges MUST NOT be created automatically, and a charge or
  its status MUST NOT affect checking in, calling a patient, finishing a visit, or a medical record.
- **FR-005**: A charge MUST keep its fee, patient, doctor, and date as they were when it was created. A
  later fee change MUST NOT alter it.
- **FR-006**: A visit MUST have at most one active charge, also under simultaneous requests. Booked and
  walk-in visits MUST be treated the same way. A cancelled charge does not count as active.
- **FR-007**: A charge MUST show its fee, discount, amount due (fee less discount), amount paid (payments
  less refunds), balance (amount due less amount paid), and status: `unpaid` (nothing paid and something
  owed), `partially_paid`, `paid` (nothing owed), or `cancelled`.

**Payments**

- **FR-008**: Receptionists and administrators MUST be able to record a payment on an active charge, with
  an amount, a method (`cash`, `card`, or `wallet`), the staff member, and the time.
- **FR-009**: A payment MUST be above 0, exact to two decimal places, and at most the balance, also under
  simultaneous requests. A charge MAY take several payments, with different methods.
- **FR-010**: Card numbers and other payment credentials MUST NOT be stored.

**Discounts and corrections**

- **FR-011**: Administrators MUST be able to set a discount with a reason of up to 500 characters on an
  active charge. It MUST NOT exceed the fee less the amount paid. The original fee MUST stay visible.
- **FR-012**: Charges, payments, refunds, and discounts MUST NOT be deleted, and payments MUST NOT be
  edited.
- **FR-013**: A charge MUST be cancellable with a reason of up to 500 characters only when its amount paid
  is zero. A cancelled charge MUST stay on record and take no further changes.
- **FR-014**: A check-in (feature 007) MUST NOT be undone while its visit has an active charge. A cancelled
  charge MUST stay on record, with its patient, doctor, and date, after its visit is removed.
- **FR-015**: Administrators, and nobody else, MUST be able to correct money received by recording a refund
  on an active charge, with an amount, a method (`cash`, `card`, or `wallet`), a reason of up to 500
  characters, the staff member, and the time. A refund MUST be above 0, exact to two decimal places, and at
  most the amount paid, also under simultaneous requests.

**Finding**

- **FR-016**: Receptionists and administrators MUST be able to list a date's visits with their charge
  status and balance, filtered optionally by doctor and charge status (`none`, `unpaid`, `partially_paid`,
  `paid`), in pages of a bounded size.
- **FR-017**: Receptionists and administrators MUST be able to list charges for a date range of at most 92
  days, filtered optionally by patient, doctor, and status, newest first, in pages of a bounded size.
- **FR-018**: Receptionists and administrators MUST be able to open one charge with all its payments and
  refunds.
- **FR-019**: Responses MUST show only the patient summary (patient number, full name, phone) and MUST NOT
  contain medical content, or the national ID, address, or emergency contact.

**Cross-cutting**

- **FR-020**: Doctors MUST NOT use any action in this feature.
- **FR-021**: Every change MUST be all-or-nothing.
- **FR-022**: All actions MUST require a signed-in, active staff account and MUST be authorized through the
  system's single authorization mechanism (feature 002); refused requests are reported as forbidden,
  distinct from unauthenticated.
- **FR-023**: Logs MUST identify patients only by internal identifier; seed data, fixtures, and tests MUST
  use synthetic data only (Constitution XII).
- **FR-024**: All dates and times MUST be the clinic's local time (Egypt), as in features 005–008.
- **FR-025**: All user-facing messages MUST exist in English and Arabic with identical response field names
  and structure; statuses and methods are fixed English identifiers, never translated.
- **FR-026**: Payment capabilities MUST follow the API versioning and module conventions established by
  features 001–008.

### Key Entities *(include if feature involves data)*

- **Doctor fee**: A doctor's current consultation fee, with who set it and when. Past amounts live on the
  charges made at them.
- **Charge**: What one visit costs. Belongs to one visit, patient, doctor, and date. Holds the fee at
  that moment, the discount with its reason and who set it, the status, who created it, and when and why
  it was cancelled.
- **Payment**: Money received on one charge: amount, method, staff member, and time. Never edited.
- **Refund**: Money returned on one charge: amount, method, reason, staff member, and time. Never edited.
- **Visit** (feature 007, queue entry): Gains one rule: its check-in cannot be undone while it has an active
  charge.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A receptionist can charge a visit and record its payment in under 30 seconds of work.
- **SC-002**: 0 payments above the balance, 0 refunds above the amount paid, and 0 visits with two active
  charges, in automated tests that submit many simultaneous charges, payments, and refunds.
- **SC-003**: 100% of existing charges keep their original amounts after a fee change, as shown by automated
  tests.
- **SC-004**: Every balance is exact to the piastre, including after 100 small part payments on one charge,
  as shown by automated tests.
- **SC-005**: 100% of actions refuse unauthenticated requests and doctors, and only administrators set fees
  and discounts and record refunds, as shown by automated tests covering every role against every action.
- **SC-006**: Staff receive a date's visits or a page of charges in under 2 seconds with 500 doctors and
  500,000 charges.
- **SC-007**: Every user-facing message added by this feature exists in both English and Arabic, as verified
  by automated tests in both languages.

## Assumptions

- Patients come from feature 003, doctors from 004, visits from 007, and roles from 002. The only change to
  an earlier feature is FR-014: undoing a check-in is refused while the visit has an active charge.
- Doctors have no access to fees or payments (least privilege). Showing a doctor their own earnings would
  belong to "Reports" (010).
- All amounts are in Egyptian pounds. Other currencies are out of scope.
- This feature records money and moves none: card processing, online payment, and mobile wallet integration
  are out of scope.
- Priced services beyond the consultation fee, insurance, patient credit accounts, deposits, no-show fees,
  and instalment plans are out of scope.
- Receptionists and administrators may both cancel a charge with no money held, since nothing is being
  returned; only refunding is limited to administrators.
- Printed receipts and invoices are out of scope. Applicable Egyptian tax and e-receipt obligations, like
  health-data obligations, need legal review before production use.
- A cash drawer or end-of-day balancing is out of scope; totals belong to "Reports" (010).
- A detailed change history belongs to "Audit logs"; each record keeps its own staff members and times.
- The 2-second and 500,000-charge targets follow the scale of features 005–008 and can be tuned during
  planning.
