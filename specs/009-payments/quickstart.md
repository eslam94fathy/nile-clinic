# Quickstart Validation: Payments

**Feature**: 009-payments | **Date**: 2026-11-05

Run from the repository root with the development stack up (see `README.md`). Commands are POSIX-shell
syntax (Git Bash/WSL/macOS/Linux). Endpoint details:
[contracts/payments.openapi.yaml](contracts/payments.openapi.yaml); fields and rules:
[data-model.md](data-model.md). All data below is synthetic.

## Q1 — Migrate

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
docker compose exec app php artisan route:list --path=fee
docker compose exec app php artisan route:list --path=charge
```

Expected: the four payment migrations show `Ran`; earlier migrations unchanged; the eleven routes in the
contract are listed (`/visits` appears under `--path=visits`).

## Q2 — Tokens, a doctor, patients

Reuse feature 007 quickstart Q2 (`B`, `J`, `signin`, `ADMIN`, `RECEPTION`, `DOCTOR`, `DOC`, patients `P1`,
`P2`). Set `TODAY` to the clinic's date.

## Q3 — Fees (US1)

```sh
curl -s -w '  %{http_code}\n' $B/doctors/$DOC/fee -H "Authorization: Bearer $RECEPTION"
curl -s -w '  %{http_code}\n' -X PUT $B/doctors/$DOC/fee -H "Authorization: Bearer $ADMIN" -H "$J" -d '{"amount":"300.00"}'
curl -s -w '  %{http_code}\n' $B/fees -H "Authorization: Bearer $RECEPTION"
```

Expected: `200` with `amount: null`; `200` with `amount: "300.00"`; the doctor listed with that fee. Then:

- `amount` of `0`, `-5`, `1.999`, or `100000.01` → `422`;
- `PUT` as `$RECEPTION` or `$DOCTOR` → `403`; `GET` as `$DOCTOR` → `403`; unknown doctor → `404`.

## Q4 — Charge and pay (US2)

Add `P1` as a walk-in for `DOC` (007 Q4), and note the entry id as `V1`.

```sh
curl -s -w '  %{http_code}\n' -X POST $B/visits/$V1/charge -H "Authorization: Bearer $RECEPTION"
curl -s -w '  %{http_code}\n' -X POST $B/charges/$C1/payments -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"amount":"100.10","method":"cash"}'
curl -s -w '  %{http_code}\n' -X POST $B/charges/$C1/payments -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"amount":"500.00","method":"card"}'
curl -s -w '  %{http_code}\n' -X POST $B/charges/$C1/payments -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"amount":"199.90","method":"card"}'
```

Set `C1` from the first response's `data.id`. Expected: `201` `unpaid`, `fee: "300.00"`, `balance: "300.00"`;
`201` `partially_paid`, `balance: "199.90"`; `422` `above_balance`; `201` `paid`, `balance: "0.00"`, two
payments. Then:

- charging `V1` again → `422` `already_charged`;
- changing the fee to `400.00`, then opening `C1` → still `fee: "300.00"`;
- charging as `$DOCTOR` → `403`.

## Q5 — Finding (US3)

```sh
curl -s "$B/visits?date=$TODAY&charge_status=paid" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/visits?date=$TODAY&charge_status=none" -H "Authorization: Bearer $RECEPTION"
curl -s "$B/charges?from=$TODAY&to=$TODAY&patient_id=$P1" -H "Authorization: Bearer $RECEPTION"
curl -s $B/charges/$C1 -H "Authorization: Bearer $RECEPTION"
```

Expected: `V1` under `paid`, and not under `none`; the charge listed with no payments or reasons; the single
view shows both payments with methods and staff. No response holds a national ID, address, or emergency
contact.

## Q6 — Discount (US4)

Add `P2` as a walk-in (`V2`), and charge it (`C2`, `fee: "400.00"`).

```sh
curl -s -w '  %{http_code}\n' -X PUT $B/charges/$C2/discount -H "Authorization: Bearer $ADMIN" -H "$J" -d '{"amount":"150.00","reason":"Synthetic hardship"}'
curl -s -w '  %{http_code}\n' -X PUT $B/charges/$C2/discount -H "Authorization: Bearer $ADMIN" -H "$J" -d '{"amount":"400.00","reason":"Synthetic waiver"}'
```

Expected: `200`, `fee: "400.00"`, `discount: "150.00"`, `balance: "250.00"`; `200`, `discount: "400.00"`,
`balance: "0.00"`, `paid`. No reason → `422`; above the fee → `422` `discount_too_large`; as `$RECEPTION` →
`403`.

## Q7 — Refund, cancel, and undo (US5)

```sh
curl -s -w '  %{http_code}\n' -X POST $B/charges/$C1/refunds -H "Authorization: Bearer $ADMIN" -H "$J" -d '{"amount":"300.00","method":"cash","reason":"Synthetic mistake"}'
curl -s -w '  %{http_code}\n' -X POST $B/charges/$C1/cancel -H "Authorization: Bearer $RECEPTION" -H "$J" -d '{"reason":"Synthetic wrong visit"}'
curl -s -w '  %{http_code}\n' -X POST $B/visits/$V1/charge -H "Authorization: Bearer $RECEPTION"
```

Expected: `201`, `amount_paid: "0.00"`, `unpaid`, both payments still shown; `200` `cancelled`; `201` a new
charge at the current fee. Then:

- a refund above the amount paid → `422` `above_paid`; as `$RECEPTION` → `403`;
- cancelling a charge with money held → `422` `money_held`;
- a payment on the cancelled charge → `422` `charge_cancelled`.

**Undo**: book and check in an appointment for today (007 Q2–Q3, entry `V3`), and charge it (`C3`).

- `DELETE $B/queue/$V3` → `422` `has_active_charge`, and the entry stays;
- cancel `C3`, then `DELETE $B/queue/$V3` → `204`;
- `GET $B/charges/$C3` → `200`, `cancelled`, `visit_id: null`, with its patient, doctor, and date.

## Q8 — Tests and style

```sh
docker compose exec app composer test
docker compose exec app php artisan test --group=concurrency
docker compose exec app vendor/bin/pint --test
```

Expected: all tests exit `0`, including feature 007's and 008's tests unchanged, the payment concurrency
tests (no overpayment, no over-refund, one active charge per visit), and the message tests in both locales;
Pint exits `0`.
