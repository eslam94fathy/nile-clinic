# Feature Specification: Project Foundation and Local Development Environment

**Feature Branch**: `001-project-foundation`

**Created**: 2026-10-27

**Status**: Draft

**Input**: User description: "Define Feature 001: Project Foundation and Local Development Environment
for a new Clinic Management System — clean API application, database and containerized local
environment, safe environment configuration, documented setup and migration workflow, minimal
automated testing workflow, preparation for versioned API and English/Arabic localization, and
initial architecture conventions required by the Constitution. Backend only; no business modules;
no production deployment."

## Context

This feature creates the empty, working skeleton every later clinic feature builds on. Its
"users" are the engineers who develop, test, and review the system. The technology stack is
fixed by Constitution Principle II (Laravel REST API, MySQL, Docker Compose); exact versions are
**not** fixed here and MUST be verified for mutual compatibility during planning (FR-002).

## Clarifications

### Session 2026-10-27

- Q: Standard JSON response/error envelope now, or framework defaults? → A: Keep
  framework-default JSON shapes; only guarantee API errors are always JSON (FR-019).
- Q: Health verification via a versioned endpoint or framework commands only? → A: Add public
  `GET /api/v1/health` reporting app and database status without sensitive details (FR-020).
- Q: Domain code layout? → A: Self-contained modules under `app/Modules/<Domain>/` (FR-023).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Start the system locally from a fresh checkout (Priority: P1)

A developer clones the repository onto a machine that has only the container runtime installed,
follows the setup guide, and gets the API application and its database running, connected to each
other, with the database schema migrated.

**Why this priority**: Nothing else (tests, conventions, features) can be done or verified until
the environment runs reproducibly.

**Independent Test**: On a clean machine, follow only the setup guide; the application responds to
a request and the migration status shows all migrations applied.

**Acceptance Scenarios**:

1. **Given** a fresh checkout and a copied example environment file, **When** the developer runs
   the documented build-and-start steps, **Then** the application and database services start and
   report healthy/running. *(AC-1)*
2. **Given** the services are running, **When** the application connects to the database, **Then**
   it reaches the database using the database service name over the internal container network,
   not a host-published port. *(AC-2)*
3. **Given** the services are running, **When** the developer runs the documented migration
   command, **Then** all migrations apply without error and re-running reports nothing to migrate.
   *(AC-3)*
4. **Given** the services are running, **When** anyone on the host network tries to reach the
   database port, **Then** the database is not reachable from outside the container network.
5. **Given** the services are running, **When** a client calls `GET /api/v1/health`, **Then** it
   receives a JSON success response confirming the application and database are healthy.
6. **Given** the database is stopped, **When** a client calls `GET /api/v1/health`, **Then** it
   receives a JSON failure response with no internal details (hostnames, credentials, traces).

---

### User Story 2 - Run the automated tests reproducibly (Priority: P2)

A developer (or reviewer) runs one documented command and the test suite executes inside the
containerized environment against an isolated test database, producing the same result on any
machine.

**Why this priority**: The Constitution (Principle VIII) requires automated tests for every
feature; the workflow must exist before the first business feature.

**Independent Test**: Run the documented test command twice on two different machines from a fresh
start; both runs pass with identical results and leave development data untouched.

**Acceptance Scenarios**:

1. **Given** the environment is running, **When** the developer runs the documented test command,
   **Then** the suite runs and reports pass/fail with a non-zero exit code on failure. *(AC-4)*
2. **Given** development data exists, **When** the test suite runs, **Then** the development
   database contents are unchanged afterwards.
3. **Given** the foundation is complete, **When** the suite runs, **Then** it includes tests for the
   health endpoint (healthy and database-unavailable cases) and for locale selection/fallback.

---

### User Story 3 - Configure the environment safely (Priority: P2)

A developer creates their local configuration from a committed example file that documents every
required setting, with placeholder values only, and real secrets never enter source control.

**Why this priority**: Constitution Principles I and XII forbid secrets and real data in source
control; this must be enforced from the first commit.

**Independent Test**: Inspect the committed example environment file and repository ignore rules;
every variable is documented with a safe placeholder and the real environment file is ignored.

**Acceptance Scenarios**:

1. **Given** the repository, **When** a reviewer reads the example environment file, **Then** every
   variable the application and containers need is present, explained, and holds only non-secret
   placeholder/default values. *(AC-5)*
2. **Given** a developer creates a real environment file, **When** they check repository status,
   **Then** that file is ignored and cannot be committed accidentally.
3. **Given** a missing or invalid required setting, **When** the application starts, **Then** the
   troubleshooting guide describes the resulting symptom and its fix.

---

### User Story 4 - Understand and follow the architecture conventions (Priority: P3)

A developer starting the first business feature reads the architecture documentation and knows
exactly where controllers, Form Requests, API Resources, Policies, Services/Actions, repository
interfaces and implementations, translations, and tests belong, and which rules they must obey.

**Why this priority**: Conventions prevent drift once multiple features are built, but they deliver
value only after the environment and tests exist.

**Independent Test**: A reviewer can answer "where does X go and what may it do?" for each layer
using only the documentation.

**Acceptance Scenarios**:

1. **Given** the documentation, **When** a reviewer looks up each layer, **Then** it states the
   layer's location, responsibility, and prohibitions — including that controllers contain no
   business logic or database queries and that all data access goes through repository interfaces
   bound in the service container. *(AC-6)*
2. **Given** the documentation, **When** a developer looks for the API versioning rule, **Then** it
   states that all endpoints live under `/api/v1` and how future versions would be added.

---

### User Story 5 - Localization foundation for English and Arabic (Priority: P3)

The application selects English or Arabic for user-facing messages from a validated
`Accept-Language` value and falls back to English, using only framework-provided messages (e.g.,
standard validation messages) — no invented business messages.

**Why this priority**: Required by Constitution Principle VII for every later feature, but no
business message exists yet.

**Independent Test**: Send requests with `ar`, `en`, an unsupported value, and no header; observe
the selected locale in each case via a framework-provided message.

**Acceptance Scenarios**:

1. **Given** a request with `Accept-Language: ar`, **When** a framework-provided user-facing
   message is returned, **Then** it is in Arabic. *(AC-7)*
2. **Given** a request with an unsupported or malformed `Accept-Language`, or none, **When** a
   message is returned, **Then** it is in English.
3. **Given** responses in both locales, **When** they are compared, **Then** field names and
   response structure are identical.
4. **Given** the translation files, **When** a reviewer inspects them, **Then** they contain only
   framework-standard messages for `en` and `ar`, with no clinic business messages.

---

### Edge Cases

- Host port already in use for the application: the guide documents how to change the published
  application port through configuration.
- Database container not ready when the application starts: startup or migration must wait for
  database readiness or the guide must document the retry step.
- Application started before the example environment file is copied or before the application key
  is generated: troubleshooting guide covers the symptom and fix.
- Database data persists across container restarts via a named volume; resetting it requires an
  explicitly documented, opt-in destructive command (never part of normal setup).
- `Accept-Language` with quality values or region subtags (e.g., `ar-EG`, `en-US;q=0.8`) resolves
  to the supported base locale; anything else falls back to English.
- Line-ending or file-permission differences on Windows hosts must not break container startup
  (documented in troubleshooting).
- Tests run while the development environment holds data: tests MUST use an isolated database.

## Requirements *(mandatory)*

### Functional Requirements

**Application & environment**

- **FR-001**: The project MUST contain a clean API-only application (no frontend views, Blade
  pages, Livewire, frontend build tooling, or patient portal) using the stack mandated by
  Constitution Principle II.
- **FR-002**: Runtime, framework, dependency-manager, and database versions MUST be selected during
  planning after verifying their mutual compatibility against official sources, recorded with the
  evidence, and pinned (no `latest` tags or floating versions).
- **FR-003**: The local environment MUST be built and started by a documented container workflow
  consisting of at most a few documented commands.
- **FR-004**: The application MUST connect to the database using the database service name over the
  internal container network.
- **FR-005**: The database port MUST NOT be published to the host or any public interface.
- **FR-006**: Database data MUST be stored in a named persistent volume that survives container
  restarts and rebuilds.
- **FR-007**: The database MUST use a character set and collation supporting full Unicode (Arabic
  text) by default.
- **FR-008**: Only dependencies required for this feature's scope MAY be added; each dependency
  beyond the framework's defaults MUST be justified in the plan. No authentication, business, or
  frontend packages are added in this feature.

**Configuration & secrets**

- **FR-009**: A committed example environment file MUST list and document every required variable
  with safe placeholder or local-only default values and no real secrets.
- **FR-010**: Real environment files, local secrets, and generated keys MUST be excluded from source
  control.

**Database workflow**

- **FR-011**: A documented command MUST apply migrations in the development environment; the
  documented workflow MUST state that applied migrations are never edited (new migrations only).
- **FR-012**: Any command that drops or resets data MUST be documented as destructive and opt-in.

**Testing workflow**

- **FR-013**: A single documented command MUST run the automated test suite inside the containerized
  environment and return a non-zero exit status on failure.
- **FR-014**: Tests MUST run against an isolated test database on the same database engine as
  development, never against development data.
- **FR-015**: The foundation test suite MUST include tests for the health endpoint (healthy and
  database-unavailable responses) and for locale selection and fallback.

**API versioning & localization**

- **FR-016**: API routing MUST be prepared so all endpoints are served under the `/api/v1` prefix.
- **FR-017**: The application MUST select `en` or `ar` per request from a validated
  `Accept-Language` value, falling back to `en` for missing, malformed, or unsupported values.
- **FR-018**: English and Arabic translation resources MUST exist for framework-provided user-facing
  messages (e.g., validation); no business-specific messages are created in this feature.
- **FR-019**: API error responses MUST be returned as JSON (never HTML) for API requests, with
  identical structure across locales. This feature keeps the framework-default JSON response and
  error shapes; no custom response envelope is defined (a standard envelope, if needed, is a later
  feature).
- **FR-020**: The application MUST expose a public `GET /api/v1/health` endpoint that reports
  whether the application is running and whether the database is reachable. It MUST return a
  success status when both are healthy and a failure status when the database is unreachable, and
  MUST NOT disclose versions, hostnames, credentials, configuration, error traces, or any patient
  data.

**Architecture conventions & documentation**

- **FR-021**: Architecture documentation MUST define, for each layer (routes, controllers, Form
  Requests, API Resources, Policies/Gates, Services/Actions, repository interfaces and
  implementations, models, migrations, translations, tests): its location, responsibility, and
  prohibitions, consistent with Constitution Principles III–VI.
- **FR-022**: The documentation MUST state that controllers contain no business logic or database
  queries, and that repositories are interface-bound in the service container with no generic base
  repository.
- **FR-023**: Code MUST be organized as self-contained domain modules under
  `app/Modules/<Domain>/`, each holding that domain's controllers, Form Requests, API Resources,
  Policies, Services/Actions, repository interfaces and implementations, and models. The
  documentation MUST define the module folder layout, naming, and how a module registers its
  routes (under `/api/v1`), container bindings, policies, and translations, keeping that wiring as
  simple as possible (Constitution: no unnecessary abstractions). This feature creates the module
  convention and only the minimal structure needed for the health endpoint; no business modules.
- **FR-024**: A setup guide MUST document prerequisites, first-time setup, daily start/stop, running
  migrations, running tests, and environment configuration.
- **FR-025**: A troubleshooting guide MUST cover at least: port conflicts, database not ready,
  missing environment file or application key, permission/line-ending issues on Windows hosts, and
  how to inspect service logs.

### Out of Scope

- Patient, doctor, specialty, scheduling, appointment, queue, encounter, payment, report, and audit
  modules.
- Authentication, users, roles, and permissions (later feature).
- Production or staging deployment, CI/CD pipelines, and hosting.
- Any frontend or patient-facing interface.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A developer with only the container runtime installed reaches a running, migrated
  environment from a fresh checkout in under 15 minutes (excluding image download time) by following
  only the setup guide.
- **SC-002**: The documented test command produces identical pass results on 2 different developer
  machines from a fresh start, and leaves development data unchanged in 100% of runs.
- **SC-003**: 0 real secrets or real patient data exist in the repository; 100% of required
  environment variables are documented in the example file.
- **SC-004**: The database is unreachable from the host and network in 100% of connection attempts
  from outside the container network.
- **SC-005**: Locale tests confirm correct selection for `en`, `ar`, regional variants, and fallback
  to English for unsupported or missing values in 100% of cases.
- **SC-006**: A reviewer can locate the correct place and rules for each architecture layer using
  only the documentation, for 100% of the layers listed in FR-021.
- **SC-007**: Each of the 8 requested acceptance criteria (AC-1 to AC-8) maps to at least one
  verifiable scenario or requirement in this specification.

## Acceptance Criteria Traceability

| AC | Requested criterion | Covered by |
|----|---------------------|------------|
| AC-1 | Built and started via documented Docker workflow | US1-S1, FR-003, SC-001 |
| AC-2 | MySQL connection over container networking | US1-S2, US1-S5/S6, FR-004, FR-005, FR-020 |
| AC-3 | Migrations run in development | US1-S3, FR-011 |
| AC-4 | Tests executed reproducibly | US2, FR-013–FR-015, SC-002 |
| AC-5 | Environment configuration documented without secrets | US3, FR-009, FR-010, SC-003 |
| AC-6 | Project structure and architecture rules documented | US4, FR-021–FR-023, SC-006 |
| AC-7 | en/ar localization foundation without invented messages | US5, FR-017, FR-018, SC-005 |
| AC-8 | Setup and troubleshooting documented | FR-024, FR-025 |

## Open Decisions Deferred to Planning

These are technical choices for `/speckit-plan`, not business questions; each MUST be decided with
justification against the Constitution:

- Exact PHP, Laravel, Composer, and MySQL versions (FR-002), verified against official
  compatibility/support information.
- Container setup approach (custom Compose services vs. a framework-provided dev tool) and the web
  server/PHP runtime arrangement inside the containers.
- Test runner choice (framework default vs. an alternative), consistent with FR-008.
- Mechanism for creating the isolated test database (FR-014).
- Where documentation lives (e.g., `README.md` plus `docs/`).
- Module wiring mechanism for `app/Modules/<Domain>/` (e.g., one service provider per module vs.
  a single registration point), choosing the simplest option that satisfies FR-023.
- Which module hosts the health endpoint (e.g., a small `System`/`Health` module).

## Assumptions

- Developers use Docker Desktop (Windows/macOS) or Docker Engine with Compose v2 (Linux); no PHP,
  Composer, or MySQL is installed on the host.
- The application is a single deployable unit (modular monolith) for one medical center.
- The application port is published to the host on localhost for development; only the database
  stays internal.
- Framework-provided Arabic translations may be sourced from the framework's official or a widely
  used community translation set, reviewed before commit; this is decided in planning under FR-008.
- No authentication package is installed in this feature; protecting endpoints begins with the
  authentication feature.
- Seeders, if any are added, contain synthetic data only (Constitution Principle XII); none are
  required for this feature.
