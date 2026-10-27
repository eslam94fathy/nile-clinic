# Nile Clinic Management System Constitution

## Core Principles

### I. Security & Patient Privacy by Design

- Every protected endpoint MUST require authentication and MUST authorize access to each
  resource it reads or changes, following least privilege.
- Roles MUST be separated at minimum into administrator, receptionist, and doctor; additional
  roles require an approved specification.
- Doctors MUST only access patient and encounter records permitted by approved access rules.
- Medical notes and sensitive patient fields MUST NOT appear in general listings, error
  responses, or logs.
- Secrets and credentials MUST NOT be committed; configuration comes from environment variables.
- Applicable Egyptian health-data and data-protection obligations MUST be identified for legal
  review before any production use.

Rationale: the system holds medical data; a privacy failure is a patient-safety and legal failure.

### II. Laravel REST API, MySQL, and Docker Compose

- The system is a backend-only Laravel REST API for a single medical center, versioned under
  `/api/v1`. No frontend, Blade, Livewire, or patient portal without explicit approval.
- MySQL (InnoDB, `utf8mb4`) is the only database; schema changes MUST be made via migrations,
  and applied migrations MUST NOT be edited—create a new migration instead.
- Local development MUST run through Docker Compose with pinned image versions (never `latest`),
  a named persistent volume for MySQL, the Compose service name as DB host, and no publicly
  exposed database port.
- Code MUST follow the conventions of the installed Laravel version.

### III. Mandatory Repository Pattern

- All persistence MUST go through repositories defined as interfaces with concrete
  implementations, bound in the Laravel service container and injected by interface.
- Generic base repositories and speculative abstractions are prohibited; each repository serves
  one domain aggregate with explicit, purpose-named methods.

Rationale: a single, explicit data-access boundary keeps queries testable and reviewable.

### IV. Thin Controllers

- Controllers MUST contain no business logic and no direct database or Eloquent queries.
- Controllers only: accept a Form Request, call a Service/Action, authorize via Policies/Gates,
  and return an API Resource or standard response.

### V. Services/Actions Own Workflows; Repositories Own Data Access

- Business rules and multi-step workflows MUST live in Services or focused single-purpose
  Actions, organized by business domain (modular monolith).
- Services/Actions MUST NOT build queries directly; repositories MUST NOT contain business rules.

### VI. Laravel Components Used for Their Intended Responsibilities

- Form Requests MUST perform input validation.
- API Resources MUST perform response serialization; models MUST NOT be returned raw.
- Policies/Gates MUST perform authorization; authorization checks MUST NOT be scattered in
  services or controllers as ad hoc conditionals.

### VII. English and Arabic Localization

- The API MUST support `en` and `ar` via Laravel localization, selected from a validated
  `Accept-Language` header; unsupported or missing values MUST fall back to `en` (documented).
- All user-facing validation and application messages MUST be translatable in both locales.
- API field names, response structure, code identifiers, database columns, and internal logs
  MUST remain language-independent and identical across locales.

### VIII. Automated Testing, Maintainability, and Acceptance Criteria

- Every feature specification MUST state explicit, testable acceptance criteria.
- Automated tests MUST cover business rules, authorization, validation, API responses, and
  important persistence behavior; user-facing messages MUST be tested in both locales.
- Tests MUST NOT be weakened, skipped, or deleted to make a task pass.
- Test results MUST be reported as actually observed; unrun commands MUST NOT be claimed as passing.
- Code MUST be readable and simple; complexity MUST be justified in the plan.

### IX. Small, Reviewable, Independently Verifiable Tasks

- Work MUST be broken into small tasks, each with a clear outcome that can be verified on its own
  (test, command, or observable API behavior).
- A task MUST implement only its scope and required dependencies; unrelated rewrites and large
  incidental changes are prohibited.

### X. No Invented Endpoints, Dependencies, Schema, or Requirements

- Endpoints, tables, models, packages, and business rules MUST NOT be assumed or invented; they
  MUST be traced to an approved spec/plan or verified in the existing code.
- New dependencies require explicit justification and approval in the plan.
- Material conflicts or missing decisions MUST be stopped and reported, not resolved by assumption.

### XI. Appointment Integrity, Transactions, and Concurrency

- Operations that must be atomic (e.g., booking, rescheduling, cancellation, payment recording)
  MUST run inside database transactions.
- Concurrent booking MUST NOT produce double-booked doctor slots; protection MUST be enforced at
  the database level (e.g., unique constraints and/or row locking), not only in application code.
- Appointment conflicts and concurrent booking behavior MUST have automated tests.
- Money MUST be stored as `DECIMAL`, never floating point; historical values (e.g., fees at time
  of service) MUST be preserved where business requirements demand it.

### XII. No Real Patient Data in Fixtures or Logs

- Seeders, factories, fixtures, and tests MUST use synthetic data only.
- Real patient data MUST NOT be committed, seeded, or copied into development or test environments.
- Logs MUST NOT contain patient identifiers beyond opaque internal IDs, nor medical content.

## Technology & Security Constraints

- Stack: Laravel (installed version's conventions), PHP, MySQL/InnoDB `utf8mb4`, Docker Compose.
- Schema: foreign keys and appropriate indexes are required on all relations and lookup columns.
- Domains: authentication/authorization, users and roles, patients, doctors and specialties,
  scheduling and availability, appointments, patient queues, medical encounters, payments,
  reports, and audit logs.
- Destructive database commands, volume deletion, and production changes MUST NOT be run without
  explicit approval.
- Documentation MUST cover setup, migrations, running tests, and common troubleshooting.

## Development Workflow & Quality Gates

- The approved Constitution, feature spec, plan, and task list form the development contract.
- Every plan MUST include a Constitution Check against all twelve principles; any violation MUST
  be justified in the plan's complexity tracking or the plan is rejected.
- Before implementation, relevant specs and existing code MUST be inspected.
- A task is done only when: its acceptance criteria are met, required tests exist and pass,
  controllers remain thin, data access stays in repositories, and both locales are handled.
- When approved decisions change, the spec, plan, and tasks MUST be updated to match.

## Governance

- This Constitution supersedes all other practices; `CLAUDE.md` provides runtime guidance and
  MUST NOT contradict it.
- Every technical decision, feature plan, and review MUST verify compliance with these principles.
- Amendments require a documented proposal, explicit approval, and a version bump with an updated
  Last Amended date; dependent artifacts MUST be reconciled after amendment.
- Versioning: MAJOR for removing or redefining a principle; MINOR for adding a principle/section
  or materially expanding guidance; PATCH for clarifications and wording fixes.

**Version**: 1.0.0 | **Ratified**: 2026-10-27 | **Last Amended**: 2026-10-27
