# Roadmap

The order in which features are specified and built. Each feature goes through the Spec Kit
workflow (`/speckit-specify` → `/speckit-plan` → `/speckit-tasks` → `/speckit-implement`) and gets
its own directory under `specs/`. The order follows dependencies: a feature starts only after the
features it depends on are done.

This is a planning aid, not a contract. Scope for each feature is fixed by its approved spec; update
this file when a feature is completed or the order changes.

## Features

| #   | Feature                    | Status  | Depends on    | Spec                                                       |
|-----|----------------------------|---------|---------------|------------------------------------------------------------|
| 001 | Project foundation         | Done    | —             | [specs/001-project-foundation](../specs/001-project-foundation/spec.md) |
| 002 | Staff authentication & roles | Done  | 001           | [specs/002-staff-auth-roles](../specs/002-staff-auth-roles/spec.md)     |
| 003 | Patients                   | Next    | 002           | —                                                          |
| 004 | Doctors & specialties      | Planned | 002           | —                                                          |
| 005 | Scheduling & availability  | Planned | 004           | —                                                          |
| 006 | Appointments               | Planned | 003, 005      | —                                                          |
| 007 | Patient queues             | Planned | 006           | —                                                          |
| 008 | Medical encounters         | Planned | 003, 004, 006 | —                                                          |
| 009 | Payments                   | Planned | 006, 008      | —                                                          |
| 010 | Reports                    | Planned | 003–009       | —                                                          |
| —   | Audit logs                 | Planned | 002           | —                                                          |

Numbers for planned features are indicative; the actual number is assigned by `/speckit-specify`
when the spec directory is created.

## Feature notes

- **003 Patients**: register, search, view, and update patients. General listings return
  non-sensitive fields only. Synthetic data in seeds and tests.
- **004 Doctors & specialties**: specialty catalog; a doctor profile linked to a staff user with the
  doctor role.
- **005 Scheduling & availability**: doctor working hours, breaks, and bookable slots.
- **006 Appointments**: book, reschedule, and cancel; prevent conflicts and handle concurrent
  booking of the same slot safely.
- **007 Patient queues**: check-in on arrival and a per-doctor waiting list for the day.
- **008 Medical encounters**: visit records and medical notes; doctors access only the records the
  approved access rules allow. Notes never appear in listings or logs.
- **009 Payments**: fees and payments stored as decimals; historical prices preserved on each
  record.
- **010 Reports**: operational and financial summaries for administrators.
- **Audit logs**: who did what and when on sensitive records. It has no dependency beyond 002, so it
  can be built early or alongside 003 and later features.

## Before production

Applicable Egyptian health-data and data-protection obligations must be identified for legal review
before any production use (see `CLAUDE.md`, Security and Privacy).
