# Feature Specification: Staff Authentication and Roles

**Feature Branch**: `002-staff-auth-roles`

**Created**: 2026-10-27

**Status**: Draft

**Input**: User description: "Staff authentication and roles for administrators, receptionists, and doctors"

## Context

Every later clinic feature (patients, scheduling, appointments, encounters, payments) needs to
know **who** is making a request and **what they are allowed to do**. This feature provides that
for clinic staff only: staff accounts, signing in and out, and three separated roles —
administrator, receptionist, and doctor — as required by Constitution Principle I.

The foundation (feature 001) removed the framework's default user schema, so staff accounts are
introduced here from scratch. Patients do not have accounts; there is no patient-facing access.

## Clarifications

### Session 2026-10-27

- Q: Can one person hold more than one role? → A: No. Exactly one role per account; a person
  needing two roles uses two accounts (FR-003).
- Q: How long does a signed-in session last? → A: It expires after 8 hours without a request;
  each request restarts the period (FR-009).
- Q: Can staff reset a forgotten password themselves by email? → A: No. An administrator sets a
  new password; no email reset in this feature (FR-024).
- Security review (during US1 implementation): one combined email-and-client limit let attempts
  spread across many addresses guess one account. → A: Two separate limits, 5 failures per email
  and 20 attempts per client, counted before checking (FR-007). Follow-up reviews: look-alike
  emails (full-width letters) matched the same account under the database collation, letting
  variants dodge the per-email limit; keying on the account id instead revealed which accounts
  exist. → A: Emails are ASCII only, rejected at validation before any lookup (edge cases, R7).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Staff member signs in and out (Priority: P1)

A staff member with an active account signs in with their email address and password and receives
access that identifies them on every later request. They can see their own profile (name, email,
role) and sign out, which ends that access immediately.

**Why this priority**: Nothing else in the system can be protected until staff can prove who they
are. This is the minimum slice every other feature depends on.

**Independent Test**: Create one active staff account, sign in, view the own profile, sign out,
and confirm the old access no longer works.

**Acceptance Scenarios**:

1. **Given** an active staff account, **When** the staff member signs in with the correct email
   and password, **Then** access is granted and their profile (name, email, role) can be retrieved.
2. **Given** a wrong password or an unknown email, **When** someone tries to sign in, **Then**
   access is refused with the same generic message in both cases, revealing nothing about which
   part was wrong or whether the account exists.
3. **Given** a signed-in staff member, **When** they sign out, **Then** that access stops working
   at once, and other devices they are signed in on are not affected.
4. **Given** no access or expired access, **When** any protected request is made, **Then** it is
   refused as unauthenticated.
5. **Given** a deactivated account, **When** its owner tries to sign in with the correct password,
   **Then** access is refused with the same generic message as a wrong password.
6. **Given** repeated failed sign-in attempts for one email, **When** the limit is exceeded,
   **Then** further attempts are temporarily refused and the message says when to try again.

---

### User Story 2 - Roles restrict what each staff member can do (Priority: P1)

Each staff member has exactly one role. The system checks the role on every protected request and
refuses actions the role does not allow, so later features can declare "only administrators" or
"administrators and receptionists" and rely on it.

**Why this priority**: Role separation and least privilege are constitutional requirements; staff
management (US3) and every future feature depend on it.

**Independent Test**: Sign in as each of the three roles and call a staff-management action;
only the administrator succeeds, the others are refused as forbidden.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator, **When** they perform a staff-management action, **Then**
   it is allowed.
2. **Given** a signed-in receptionist or doctor, **When** they attempt a staff-management action,
   **Then** it is refused as forbidden, and nothing is changed.
3. **Given** any signed-in staff member, **When** they view their own profile or sign out, **Then**
   it is allowed regardless of role.
4. **Given** a refused request, **When** the response is returned, **Then** "not signed in"
   (unauthenticated) and "signed in but not allowed" (forbidden) are distinguishable.

---

### User Story 3 - Administrator manages staff accounts (Priority: P2)

An administrator creates staff accounts (name, email, role, initial password), lists and views
staff, changes a staff member's name, email, or role, deactivates and reactivates accounts, and
sets a new password for a staff member who has lost theirs.

**Why this priority**: Without it, accounts can only be created by a technician from the command
line (US4). It is needed before the clinic can onboard real staff, but sign-in and role checks can
be built and tested first.

**Independent Test**: As an administrator, create a receptionist, confirm they can sign in,
deactivate them, confirm their existing access stops working and they cannot sign in, reactivate
them, and confirm they can sign in again.

**Acceptance Scenarios**:

1. **Given** a signed-in administrator, **When** they create an account with a unique email, a
   valid role, and a password meeting the password rules, **Then** the account exists, is active,
   and its owner can sign in.
2. **Given** an email already used by another account (active or not), **When** an administrator
   creates or edits an account with it, **Then** it is rejected with a validation message.
3. **Given** a signed-in administrator, **When** they list staff, **Then** they see each account's
   name, email, role, and active status, never passwords or secrets, and can filter by role and
   status.
4. **Given** an active staff member who is signed in, **When** an administrator deactivates the
   account, **Then** all of that member's existing access stops working immediately and new
   sign-ins are refused.
5. **Given** an administrator sets a new password for a staff member, **When** that completes,
   **Then** the old password no longer works and all of that member's existing access is ended.
6. **Given** a staff member's role is changed, **When** they make their next request, **Then** the
   new role's permissions apply.
7. **Given** the administrator is the only active administrator, **When** anyone tries to
   deactivate that account or change its role, **Then** it is refused so the clinic is never left
   without an active administrator.

---

### User Story 4 - First administrator is created without seeded credentials (Priority: P2)

On a new installation there are no accounts. A technician with access to the server creates the
first administrator through a command-line step, choosing the email and password at that moment.
No default or shared credentials ship with the system.

**Why this priority**: Required to use US3 on a fresh install, and required by the no-secrets and
no-real-data rules; small in scope.

**Independent Test**: On an empty database, run the documented step, sign in as the new
administrator, and confirm no other accounts exist.

**Acceptance Scenarios**:

1. **Given** a fresh installation, **When** the technician runs the documented step with a name,
   email, and password, **Then** an active administrator account exists and can sign in.
2. **Given** the step is run with an email already in use or a password breaking the rules,
   **Then** it fails with a clear message and creates nothing.
3. **Given** the repository, seeders, and fixtures, **When** they are inspected, **Then** they
   contain no real or default staff credentials.

---

### User Story 5 - Staff member changes their own password (Priority: P3)

A signed-in staff member changes their own password by giving the current password and a new one.

**Why this priority**: Good security hygiene (for example after an administrator sets an initial
password), but the clinic can operate without it at first.

**Independent Test**: Sign in, change the password, confirm the old password fails and the new one
works.

**Acceptance Scenarios**:

1. **Given** a signed-in staff member, **When** they give the correct current password and a valid
   new password, **Then** the password changes, their other signed-in sessions end, and the
   current one stays valid.
2. **Given** a wrong current password or a new password breaking the rules, **When** they submit,
   **Then** it is rejected with a validation message and nothing changes.

---

### Edge Cases

- Email matching ignores letter case and surrounding spaces (`Sara@Clinic.test` and
  `sara@clinic.test` are the same account). Emails must be ASCII; look-alike letters (for
  example full-width) are rejected rather than matched.
- A deactivated account still blocks its email from being reused by a new account.
- An administrator cannot deactivate their own account or remove their own administrator role if
  they are the last active administrator (US3 scenario 7); if other administrators exist, they may.
- Access that is expired, revoked, malformed, or belongs to a deactivated account is always refused
  as unauthenticated, never as a server error.
- Sign-in, validation, and refusal messages are returned in English or Arabic per the request's
  language, with identical response structure (Constitution VII).
- Passwords, password hashes, and access credentials never appear in responses, error messages, or
  logs.
- Accounts are never hard-deleted in this feature, so later records (appointments, encounters,
  audit entries) can keep referring to the staff member who acted.

## Requirements *(mandatory)*

### Functional Requirements

**Accounts and roles**

- **FR-001**: The system MUST store staff accounts with: full name, email (unique, case-insensitive),
  role, active status, and a securely hashed password. Plain-text passwords MUST never be stored.
- **FR-002**: The system MUST support exactly three roles: administrator, receptionist, and doctor.
  Adding a role requires an approved specification (Constitution I).
- **FR-003**: Each staff account MUST have exactly one role at a time. A person who needs two roles
  (for example a doctor who also administers the system) uses two separate accounts.
- **FR-004**: Accounts MUST be deactivated, not deleted; deactivated accounts keep their history and
  their email stays reserved.

**Authentication**

- **FR-005**: Staff MUST be able to sign in with email and password and receive access credentials
  that identify them on later requests.
- **FR-006**: Failed sign-ins MUST return one generic message whether the email is unknown, the
  password is wrong, or the account is deactivated.
- **FR-007**: Sign-in attempts MUST be rate-limited by two separate limits, each refusing further
  attempts for at least 1 minute with a message saying when to retry:
  - **per email**, from any client: after 5 failed attempts within 1 minute; a successful
    sign-in resets this count;
  - **per client**, for any email: after 20 attempts within 1 minute (looser, because clinic
    staff usually share one public address); a successful sign-in does not reset it.
- **FR-008**: Staff MUST be able to sign out, ending only the access used for that request.
- **FR-009**: Access MUST expire after 8 hours without any request (about one clinic shift); each
  request made with the access restarts that 8-hour period.
- **FR-010**: Every protected request with missing, expired, revoked, or malformed access, or from a
  deactivated account, MUST be refused as unauthenticated.
- **FR-011**: A signed-in staff member MUST be able to view their own profile: name, email, role,
  and active status.

**Authorization**

- **FR-012**: Every protected action MUST check the requester's role through the system's single
  authorization mechanism, so later features grant access by role without ad hoc checks
  (Constitution VI).
- **FR-013**: Requests refused for lack of permission MUST be reported as forbidden, distinguishable
  from unauthenticated.
- **FR-014**: Only administrators MAY create, list, view, edit, deactivate, or reactivate staff
  accounts, or set another staff member's password.
- **FR-015**: Any signed-in staff member MAY view their own profile, change their own password, and
  sign out.

**Staff management (administrators)**

- **FR-016**: Administrators MUST be able to create accounts with name, email, role, and initial
  password.
- **FR-017**: Administrators MUST be able to list staff with name, email, role, and active status,
  filterable by role and active status, in pages of a bounded size.
- **FR-018**: Administrators MUST be able to change a staff member's name, email, and role.
- **FR-019**: Administrators MUST be able to deactivate and reactivate accounts; deactivation MUST end
  all of that account's existing access immediately.
- **FR-020**: Administrators MUST be able to set a new password for a staff member; this MUST end all
  of that member's existing access.
- **FR-021**: The system MUST refuse any change that would leave zero active administrators.

**Passwords**

- **FR-022**: Passwords MUST be at least 12 characters and MUST NOT exceed 72 characters; no other
  composition rules are imposed.
- **FR-023**: A signed-in staff member MUST be able to change their own password by providing the
  current password; on success, their other access is ended and the current access stays valid.
- **FR-024**: Password recovery MUST be done by an administrator setting a new password (FR-020).
  There is no self-service or email-based password reset in this feature.

**First administrator**

- **FR-025**: The system MUST provide a documented command-line step to create an administrator,
  prompting for or accepting name, email, and password, applying the same validation as FR-001 and
  FR-022.
- **FR-026**: No staff credentials MAY be shipped in source control, seeders, or fixtures; test data
  MUST be synthetic (Constitution XII).

**Cross-cutting**

- **FR-027**: All user-facing messages from this feature (sign-in, validation, refusals, rate
  limiting) MUST exist in English and Arabic, with identical response field names and structure.
- **FR-028**: Passwords, password hashes, and access credentials MUST NOT appear in any response,
  error, or log.
- **FR-029**: Sign-in, sign-out, and staff-management capabilities MUST follow the project's
  existing API versioning and module conventions established by feature 001.

### Key Entities *(include if feature involves data)*

- **Staff account**: A person working at the clinic who uses the system. Name, email, role, active
  status, hashed password, and when it was created and last changed. Never deleted.
- **Role**: One of administrator, receptionist, doctor. Determines which actions the account may
  perform. A fixed set in this feature.
- **Access credential**: Proof of a signed-in session, tied to one staff account and one sign-in.
  It can be ended individually (sign-out) or all at once for an account (deactivation, password
  reset, own password change except the current one), and it expires.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member with valid credentials can sign in and reach their profile in under 5
  seconds, in a single step.
- **SC-002**: 100% of protected actions refuse requests without valid access, and 100% of
  staff-management actions refuse receptionists and doctors, as shown by automated tests covering
  every role against every staff-management action.
- **SC-003**: After deactivation or an administrator password reset, 0 requests using the member's
  earlier access succeed.
- **SC-004**: Failed sign-in responses are identical for unknown emails, wrong passwords, and
  deactivated accounts in 100% of tested cases.
- **SC-005**: An administrator can onboard a new staff member (create the account and have them sign
  in) in under 2 minutes.
- **SC-006**: A fresh installation goes from no accounts to a signed-in administrator by following
  the documentation alone, with no credentials found in the repository.
- **SC-007**: Every user-facing message added by this feature exists in both English and Arabic, as
  verified by automated tests in both languages.

## Assumptions

- Only clinic staff use the system; patients have no accounts, and there is no patient-facing
  sign-in (Constitution II).
- Staff use the API through a client application built separately; this feature exposes API
  capabilities only.
- Linking a doctor account to a doctor profile, specialties, or schedules belongs to the later
  "Doctors and specialties" feature. Here, "doctor" is a role only.
- Doctors' access to patient records (Constitution I) is defined in the features that introduce
  those records; this feature provides the role checks they will use.
- A dedicated audit log of sign-ins and staff changes belongs to the later "Audit logs" feature.
  Accounts here keep created and last-changed timestamps only.
- Multi-factor authentication, single sign-on, invitation emails, and email-based password reset
  are out of scope; this feature sends no email.
- Rate-limit values (FR-007) and password length (FR-022) follow common security guidance (NIST SP
  800-63B for length without composition rules) and can be tuned during planning.
- The 72-character password maximum is an upper bound against abuse and a common limit of
  password-storage methods; planning will confirm it.
