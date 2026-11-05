# Quickstart Validation: Reports

**Feature**: 010-reports | **Date**: 2026-11-05

Run from the repository root with the development stack up (see `README.md`). Commands are POSIX-shell syntax (Git
Bash/WSL/macOS/Linux). Endpoint details: [contracts/reports.openapi.yaml](contracts/reports.openapi.yaml); figures and
rules: [data-model.md](data-model.md). All data below is synthetic.

## Q1 — Migrate

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
docker compose exec app php artisan route:list --path=reports
```

Expected: the report indexes migration shows `Ran`; earlier migrations unchanged; the four routes in the contract are
listed.

## Q2 — Tokens, a doctor, and money

Reuse the setup from feature 009 quickstart Q2–Q4 (`B`, `J`, `ADMIN`, `RECEPTION`, `DOCTOR`, `DOC`, patients `P1`, `P2`), with a
fee of `300.00` and today's walk-in `V1` charged as `C1`, but stop before recording its payments. Set `TODAY` to the clinic's date.
Record these instead:

```sh
curl -s -X POST $B/charges/$C1/payments -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"amount":"100.10","method":"cash"}'
curl -s -X POST $B/charges/$C1/payments -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"amount":"199.90","method":"card"}'
curl -s -X POST $B/charges/$C1/refunds -H "Authorization: Bearer $ADMIN" -H "$J" -d '{"amount":"150.00","method":"cash","reason":"Synthetic mistake"}'
```

On a development database with earlier rows, compare the change in each figure rather than its absolute value.

## Q3 — Money collected (US1)

```sh
curl -s -w '  %{http_code}\n' "$B/reports/money-collected?from=$TODAY&to=$TODAY&doctor_id=$DOC" -H "Authorization: Bearer $ADMIN"
```

Expected: `200`, and these amounts within the day's figures:

- `received` `300.00` and `refunded` `150.00`, so `net` `150.00`;
- `cash`: `received` `100.10`, `refunded` `150.00`, `net` `-49.90`, the negative value printed exactly;
- `card`: `net` `199.90`;
- each staff member listed under the method they handled; `by_day` adds up to the totals.

A date range with no money → `200`, zero totals, empty lists.

## Q4 — Billing (US2)

```sh
curl -s -w '  %{http_code}\n' "$B/reports/billing?from=$TODAY&to=$TODAY&doctor_id=$DOC" -H "Authorization: Bearer $ADMIN"
```

Expected: `200`, one doctor row. `C1` counts in `active_charges`, `fees`, `amount_paid` `150.00` and adds `150.00` to `balance`.
Then, while the doctor has a seen visit with no charge (007 Q5), `uncharged_seen_visits` is at least 1. Cancelling a charge with
nothing paid removes it from the money figures and adds to `cancelled_charges`.

## Q5 — Activity (US3)

```sh
curl -s -w '  %{http_code}\n' "$B/reports/activity?from=$TODAY&to=$TODAY" -H "Authorization: Bearer $ADMIN"
curl -s -w '  %{http_code}\n' "$B/reports/activity?from=$TODAY&to=$TODAY&doctor_id=$DOC" -H "Authorization: Bearer $ADMIN"
```

Expected: `200`, each doctor with appointment and visit counts by status; `no_show_rate` is a number or `null`. The first response's
`totals` has `new_patients`, and the second's does not.

## Q6 — Times and records (US4)

```sh
curl -s -w '  %{http_code}\n' "$B/reports/times?from=$TODAY&to=$TODAY&doctor_id=$DOC" -H "Authorization: Bearer $ADMIN"
```

Expected: `200`; `wait` and `consultation` each with whole `average_minutes` or `null`, and `visits`. `records.finalized + draft + none`
equals the number of seen visits reported by Q5. No clinical text anywhere.

## Q7 — Refusals

For each of the four endpoints:

- as `$RECEPTION` or `$DOCTOR` → `403`, also with invalid dates or an unknown doctor;
- with no token → `401`;
- as `$ADMIN` with `to` before `from`, a missing `from`, `from=2026-02-30`, or a period of 367 days → `422`; 366 days → `200`;
- as `$ADMIN` with an unknown `doctor_id` → `404`;
- with `Accept-Language: ar` → the same structure, with the messages in Arabic.

## Q8 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app vendor/bin/pint --test
postman spec lint specs/010-reports/contracts/reports.openapi.yaml --issueType=syntax
```

Expected: all tests exit `0`, including every earlier feature's tests unchanged, and the message tests in both locales; Pint exits `0`;
the contract reports no issues.
