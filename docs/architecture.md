# Architecture

Nile Clinic is a backend-only Laravel REST API organized as a **modular monolith**: each business
domain is a self-contained module under `app/Modules/<Domain>/`. This document says where each
layer lives, what it is responsible for, and what it must not do. The `System` module (health
endpoint) is the worked example; copy its shape when adding a new module.

## Module layout

```text
app/
├── Http/
│   └── Middleware/                            # cross-cutting HTTP concerns only (e.g. locale selection)
├── Modules/
│   └── <Domain>/                              # PascalCase domain name: System, Patients
│       ├── Http/
│       │   ├── Controllers/                   # thin: Form Request in → Service/Action → Resource out
│       │   ├── Requests/                      # Form Requests: validation (+ authorize() → Policy)
│       │   └── Resources/                     # API Resources: the only response serialization
│       ├── Actions/                           # single-purpose business operations
│       ├── Services/                          # multi-step business workflows, transactions
│       ├── Repositories/
│       │   ├── Contracts/<Name>Repository.php # interfaces; services depend on these
│       │   └── <Impl><Name>Repository.php     # e.g. EloquentPatientRepository; all queries here
│       ├── Models/                            # Eloquent models: relations, casts
│       ├── Policies/                          # authorization per model/ability
│       ├── Providers/<Domain>ServiceProvider.php  # bindings, routes, policies, translations
│       ├── routes/api.php                     # loaded with prefix api/v1 + api middleware
│       └── lang/{en,ar}/*.php                 # module messages: __('<domain>::file.key')
└── Providers/AppServiceProvider.php
bootstrap/providers.php                        # lists each module provider explicitly
database/migrations/                           # all migrations (root, Laravel ordering)
database/factories/                            # model factories (synthetic data only)
lang/{en,ar}/                                  # framework messages (validation, auth, …)
tests/Feature/<Domain>/   tests/Unit/<Domain>/
```

**Naming**: namespaces follow the path (`App\Modules\<Domain>\Http\Controllers\…`). Classes are
suffixed with their layer: `…Controller`, `…Request`, `…Resource`, `…Service`, `…Policy`,
`…Repository`. Actions are named for what they do (`BookAppointment`).

**No empty folders**: a folder appears in a module only when the module first needs it. `System`
has no Requests, Policies, Models, or `lang/` because it needs none.

## Layers

| Layer | Location | Responsible for | Must not |
|-------|----------|-----------------|----------|
| Routes | `app/Modules/<Domain>/routes/api.php` | Mapping URIs to controllers; naming routes `<domain>.<name>` | Contain closures with logic; declare their own `api/v1` prefix (the provider adds it) |
| Controllers | `Http/Controllers/` | HTTP orchestration: receive a Form Request, authorize via a Policy, call one Service/Action, return an API Resource and status code | Contain business logic; run queries or use `DB`, Eloquent, or models' query methods; return models or raw arrays |
| Form Requests | `Http/Requests/` | Input validation rules; `authorize()` delegating to a Policy | Contain business rules or queries beyond what validation rules need |
| API Resources | `Http/Resources/` | The only place that shapes response JSON; stable field names in every locale | Run queries; translate field names; expose medical notes or sensitive data in listings |
| Policies / Gates | `Policies/` | Every authorization decision for a protected resource; registered in the module provider | Be skipped; be replaced by ad hoc role checks in controllers or services |
| Services | `Services/` | Multi-step business workflows and rules; **own transactions** | Build queries or call `DB`/Eloquent directly; depend on concrete repositories |
| Actions | `Actions/` | One focused business operation (same rules as Services) | Same prohibitions as Services |
| Repository contracts | `Repositories/Contracts/` | Interfaces with explicit, purpose-named methods for one domain aggregate | Be generic (`find`, `all`, `save($anything)`); extend a base repository |
| Repository implementations | `Repositories/` | **The only place** with Eloquent, query builder, or `DB` calls | Contain business rules; start transactions on their own behalf |
| Models | `Models/` | Persistence mapping: table, relations, casts, fillable | Hold business workflows; be returned from controllers |
| Migrations | `database/migrations/` | All schema changes, MySQL/InnoDB, `utf8mb4`, foreign keys and indexes; money as `decimal` | Be edited once applied (add a new migration instead); use floats for money |
| Factories | `database/factories/` | Synthetic test data | Contain real patient data or real credentials |
| Translations | Module: `app/Modules/<Domain>/lang/{en,ar}/`; framework: `lang/{en,ar}/` | Every user-facing message, in both `en` and `ar` with the same keys | Translate API field names, code identifiers, database columns, or internal logs; hard-code user-facing strings |
| Tests | `tests/Feature/<Domain>/`, `tests/Unit/<Domain>/` | Business rules, authorization, validation, API responses, persistence; both locales for user-facing messages | Run against the development database (they use `clinic_testing`); use real patient data; be weakened to make a change pass |

### Core rules

- **Controllers contain no business logic and no database queries.** Anything beyond "validate,
  authorize, call, serialize" belongs in a Service or Action.
- **Repositories are interface-bound in the service container.** Services type-hint the contract;
  the module provider binds the contract to its implementation. Only the provider names the
  concrete class.
- **There is no generic base repository.** Each repository serves one aggregate with explicit
  methods (`findActiveByDoctor`, not `findBy(array $criteria)`). Shared helpers are added only when
  two real repositories need the same thing.
- **Transaction ownership**: the Service or Action that runs a workflow opens the transaction
  (`DB::transaction(...)` is the one `DB` call allowed outside repositories) and calls repositories
  inside it. Repositories never open transactions themselves, so a workflow composes several of
  them atomically. Concurrent booking uses locking inside such a transaction.
- **Authorization** happens through Policies/Gates only, for every protected resource.

## API versioning

- All endpoints live under `/api/v1`. Module route files declare paths without the prefix
  (`Route::get('/health', …)`); the module provider adds `api/v1` and the `api` middleware group.
- To add `v2` later, leave `v1` untouched. Add `routes/api_v2.php` to the module that changes, and
  load it in that module's provider with the `api/v2` prefix. Add new controllers and resources
  only where the contract actually differs; keep shared Services and repositories as they are.

## Registering a module

1. Create `app/Modules/<Domain>/Providers/<Domain>ServiceProvider.php`.
2. In `register()`, bind each repository contract to its implementation:
   `$this->app->bind(Contract::class, Implementation::class);`
3. In `boot()`:
   - load routes: `Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../routes/api.php');`
   - register policies, when the module has any: `Gate::policy(Model::class, ModelPolicy::class);`
   - load translations, when the module has any:
     `$this->loadTranslationsFrom(__DIR__.'/../lang', '<domain>');` and use
     `__('<domain>::file.key')`
4. Add the provider to `bootstrap/providers.php`.
5. Confirm with `docker compose exec app php artisan route:list --path=api`.

## Worked example: the System module

`GET /api/v1/health` reports whether the API can reach its database.

| Location | File | Role |
|----------|------|------|
| `routes` | `api.php` | `Route::get('/health', HealthController::class)->name('system.health')` |
| `Http/Controllers` | `HealthController` | Invokable; calls `HealthCheckService::check()` and returns `HealthResource` with 200 (`ok`) or 503 (`unavailable`) |
| `Services` | `HealthCheckService` | Builds the status; depends only on the `DatabaseStatusRepository` contract |
| `Repositories/Contracts` | `DatabaseStatusRepository` | `isReachable(): bool` |
| `Repositories` | `MySqlDatabaseStatusRepository` | Runs `select 1`; returns `false` on any exception and passes no details on |
| `Http/Resources` | `HealthResource` | `$wrap = null`, so the body is `{"status": …, "checks": {"database": …}}` |
| `Providers` | `SystemServiceProvider` | Binds the contract and loads the routes under `api/v1` |

The endpoint is public and takes no input, so it has no Form Request or Policy. Its feature test
(`tests/Feature/System/HealthEndpointTest.php`) swaps the repository binding for a fake to test the
503 path, which works only because the service depends on the interface.
