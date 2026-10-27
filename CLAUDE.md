# Clinic Management System — Claude Code Rules

Project Scope

Build a backend-only Clinic Management System for a single medical center.

- Framework: Laravel REST API.
- Database: MySQL.
- Local development: Docker Compose.
- API versioning: "/api/v1", unless an approved existing convention differs.
- Languages: English ("en") and Arabic ("ar").
- No frontend, Blade, Livewire, or patient-facing portal unless explicitly approved.

## Mandatory Architecture

- Follow the Repository Pattern using interfaces and concrete implementations.
- Controllers must contain no business logic and no direct database queries.
- Controllers handle HTTP orchestration and return appropriate API Resources/responses.
- Put business rules and workflows in Services or focused Actions.
- Keep data access and database queries inside repositories.
- Use Laravel dependency injection and the service container.
- Use Form Requests for request validation.
- Use API Resources for response serialization.
- Use Policies/Gates for authorization.
- Avoid unnecessary abstractions and generic base repositories.
- Never assume that an endpoint, database table, model, dependency, or feature exists without verifying it.
- Don't over complicated, keep it simple as we can.
- Use best practices.
- Avoid unnecessary rewrites and large, unrelated changes.
- Follow the conventions of the installed Laravel version.

## Architecture

Use a modular Laravel monolith.

Organize the application around business domains, including:

- Authentication and authorization.
- Users and roles.
- Patients.
- Doctors and specialties.
- Scheduling and availability.
- Appointments.
- Patient queues.
- Medical encounters.
- Payments.
- Reports.
- Audit logs.

Use Laravel conventions wherever practical.

## Database and Transactions

- Use MySQL/InnoDB with foreign keys, appropriate indexes, and "utf8mb4".
- Use migrations for schema changes.
- Never edit an already-applied migration to change an existing schema; create a new migration.
- Use transactions for operations that must be atomic.
- Handle concurrent appointment booking safely.
- Store money using appropriate decimal types, never floating-point types.
- Preserve historical values when business requirements require them.

## Security and Privacy

- Enforce least privilege and authorize access to every protected resource.
- Separate administrator, receptionist, doctor, and any approved additional roles.
- Limit doctors' access to records according to approved access rules.
- Do not expose medical notes or sensitive patient information in general listings or logs.
- Never place credentials, secrets, or real patient data in source control or seed fixtures.
- Use synthetic patient data in development and automated tests.
- Do not run destructive database commands or production changes without explicit approval.
- Identify applicable Egyptian health-data and data-protection obligations for legal review before production use.

## English and Arabic

- Support "en" and "ar" using Laravel localization.
- Select supported locales through a validated "Accept-Language" value.
- Use English as the documented fallback.
- Translate user-facing validation and application messages.
- Keep API field names and response structure stable across languages.
- Keep code identifiers, database columns, and internal logs language-independent.

## Docker

- Use Docker Compose for reproducible local development.
- Pin compatible image/runtime versions; do not use "latest".
- Keep MySQL data in a named persistent volume.
- Use the Compose service name as the database hostname from application containers.
- Do not expose database ports publicly.
- Never delete persistent volumes without explicit approval.
- Document setup, migrations, tests, and common troubleshooting steps.

## Testing and Quality

- Add tests for business rules, authorization, validation, API responses, and important persistence behavior.
- Test both supported locales where user-facing messages are involved.
- Test appointment conflicts and concurrent booking behavior.
- Never weaken or remove tests just to make a task pass.
- Report actual test results; never claim commands passed unless they were run successfully.

## Spec Kit Workflow

- Treat the approved Constitution, feature specification, plan, and task list as the development contract.
- Inspect relevant specifications and existing code before implementation.
- Do not invent endpoints, schema, dependencies, or existing behavior.
- Implement only the selected task and its required dependencies.
- Stop and report material conflicts or missing decisions before making assumptions.
- Keep implementation changes aligned with the approved plan and update artifacts when approved decisions change.
- Load only the context
