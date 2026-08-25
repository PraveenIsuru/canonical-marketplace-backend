# Backend Build Plan

**Status:** Build ready
**Applies to:** `C:\MyApps\canonical-marketplace\backend`

---

## 1. Context

This repository is the Laravel API for the canonical product marketplace. It **owns all business rules**. It is the only layer that talks to PostgreSQL and PostGIS, Redis, Meilisearch, object storage, the AI provider, the geocoding provider, and email.

The client is a separate Next.js application at `C:\MyApps\canonical-marketplace\frontend`. It holds no business rules. Everything crossing between them is defined in [shared/api-contract.md](shared/api-contract.md), and the working agreement between the two repositories is in [shared/integration-protocol.md](shared/integration-protocol.md).

The platform exposes 61 endpoints (`EP-01` to `EP-61`) supporting 37 client screens, built across 13 milestones, `M0` to `M12`. **The backend ships a milestone's endpoints before the frontend builds the screens that consume them.** There is no mock API on the client side, so an endpoint that does not answer is a screen that cannot be built.

---

## 2. What this repository actually is

The scaffold is `laravel/react-starter-kit`, which is an Inertia monolith with its own React frontend, Fortify auth screens, two factor columns, and passkey tables. The platform needs an API service instead.

**Keep the skeleton, stop using the Inertia side.**

- Add `routes/api.php` and register it. All platform routes live there.
- Leave `web.php` serving nothing beyond a health check.
- Install Sanctum and issue tokens from the API routes.
- **Leave the passkey and two factor migrations in place.** Unpicking them risks breaking the users table for no benefit. Simply expose no route to them.
- Ignore `resources/js`, `components.json`, and the Vite pipeline. Do not delete them in the first pass, because the starter's scripts reference them.

Starting again from a plain Laravel skeleton would be cleaner but throws away the Pest, Larastan, and Pint configuration that is already wired up. Keeping it is the right trade.

| Item | Starter ships | Target |
|---|---|---|
| Framework | Laravel 13, PHP 8.3 | Unchanged |
| Auth | Fortify, sessions, passkeys | **Add Sanctum.** Fortify may still back registration, reset, and verification, but the client talks to token endpoints |
| Routes | `web.php`, `settings.php`, `console.php` | **Add `routes/api.php`** |
| Database | SQLite | **PostgreSQL 16 with PostGIS** |
| Cache and queue | Not configured | **Redis 7 with Horizon** |
| Search | Not installed | **Laravel Scout with the Meilisearch driver** |
| Storage | Local | S3 compatible via the filesystem abstraction, local driver in development |
| Testing | Pest 4, Larastan, Pint wired | Use Pest for all feature tests |

---

## 3. Invariants

These survive every milestone. If a change breaks one, the change is wrong regardless of what it improves.

1. **No seller writes to a product, an attribute, or a variant. Ever.** The only seller path into product data is a proposal.
2. A variant combination, once generated, is **never removed** by anyone, including an administrator.
3. The confidence score **never leaves the server**.
4. A proposal is accepted or rejected **as a whole**.
5. A pending proposal means **no attachment row exists** for that seller and product.
6. A version is created on an accepted proposal or an administrator edit, and on **nothing else**.
7. A verification photograph is deleted **whether verification passed or failed**.
8. Buyer search falls back to keyword results with a visible notice. **Every other AI path blocks and queues.**
9. Public catalogue routes work with no token and **never resolve a session**.
10. Notifications are **email only**. No in app notification surface exists anywhere.
11. Prices cross the boundary as **integers in the smallest currency unit**.
12. A store is visible to buyers **if and only if** it holds at least one attachment.

A concise Pest test file asserting each of these against the routes and serialisers is worth more than any amount of documentation, because it fails when a future change quietly breaks one. Write it early and keep it passing.

---

## 4. Architecture rules

- **Keep Eloquent models thin.** Business rules live in service classes, not in controllers and not in models.
- **Vendor SDKs live only in adapters.** Features depend on interfaces. This applies to the AI provider and the geocoding provider, both of which must be switchable by configuration.
- **Build a fake adapter for each provider first**, with a deliberate failing mode. The failing mode is the only way to exercise the `ai_unavailable` path and the keyword search fallback, so it is not optional.
- **One transaction per consequential write.** The wizard submit and the proposal resolution each touch many tables and must roll back cleanly as a unit.
- **The resolution matrix lives in exactly one service.** Both the vote endpoint and the scheduled sweep call it. Two implementations of the same matrix will drift.
- **Revalidation fires after the transaction commits**, dispatched as a queued job, so a slow or unreachable frontend never fails the request that created the version.
- Use Eloquent API Resources for every response, so the envelope is applied in one place rather than per controller.

---

## 5. M0 Foundations, specified

No feature endpoint is implemented in this milestone.

### 5.1 Local infrastructure

Docker Compose or Laravel Sail covering four services:

| Service | Purpose |
|---|---|
| PostgreSQL 16 with PostGIS | Primary database, distance calculation, JSONB |
| Redis 7 | Cache and queue |
| Meilisearch | Product search and the keyword fallback path |
| Mailpit or similar | Catching outbound SMTP in development |

Object storage uses the local filesystem driver in development, with **two separate disks** so product images and verification photographs have visibly different lifecycles from the beginning. That separation is what makes the unconditional photograph deletion enforceable later.

### 5.2 Database

Switch the default connection to PostgreSQL and **enable the PostGIS extension in a migration**, so a fresh clone gets it without a manual step.

### 5.3 Packages

Install Sanctum, Horizon, Scout with the Meilisearch driver, and the S3 filesystem driver. Configure Redis as both cache and queue backend, with append only file persistence, since queued AI jobs carry user visible consequences.

### 5.4 Routes and the error envelope

Create `routes/api.php` and register it. Add the JSON error envelope as an exception handler **from day one**, so every error has `code`, `message`, and optional `errors` before any endpoint exists. Retrofitting this later means touching every controller.

The envelope and the full error code registry are in [shared/api-contract.md](shared/api-contract.md), sections 1 and 7.

### 5.5 Access middleware

Four access levels: auth, seller, admin, and a public route group with **no session resolution at all**. The seller check reads the `stores` table, not a role column.

### 5.6 Rate limiters

Add the named limiters from section 9 of the contract. Named limiters, not middleware scattered across route definitions.

### 5.7 The invariants test

Create the Pest test file asserting section 3 above. Most assertions will be trivially true while no endpoints exist. That is fine. The file exists so that assertions get added as endpoints land, rather than being written from scratch at M12 when it is too late to be useful.

### 5.8 The shared docs sync test

`tests/Feature/SharedDocsInSyncTest.php`, comparing `development-docs/shared/` against the frontend repository's copy. It skips cleanly when the sibling repository is absent.

### 5.9 M0 demonstrates

The four services run, `php artisan migrate` succeeds against PostgreSQL with PostGIS enabled, a health check answers on `/api`, an unknown API route returns the standard error envelope rather than an HTML page, and `composer test` passes.

---

## 6. Milestone roadmap

Each milestone lists its endpoints and the work that is not an endpoint. Every milestone ends with something demonstrable. **Do not begin a milestone before its predecessor demonstrates.**

The endpoint to milestone mapping is in section 10 of [shared/api-contract.md](shared/api-contract.md).

### M1 Accounts and roles
EP-01 to EP-07, EP-55, EP-56.
**Tests.** Registration validation, duplicate email, invalid credentials not revealing which field was wrong, a soft deleted account treated as invalid, and the reset token expiring.
**Demonstrates.** A person registers, receives the verification email in the mail catcher, logs in, updates their saved location, and logs out. A user with no store gets a null store object.

### M2 Catalogue read path
EP-08 to EP-13, EP-53. **Plus the models, migrations, and factories for the whole schema**, because the seeders need them.

**Write the seeders first.** With no mock API on the client, seeded data is the only thing the frontend has to build against. Seed a handful of products with attributes and generated variants, images, several stores with coordinates in **different cities**, and attachments at varied prices. Include a product with zero sellers and a dark store, because those states must be visible on screen.

Build the seller list query with care. It is the highest traffic query in the system, it does distance in PostGIS, and it needs the denormalised product id on attachments to avoid joining variants on every request.

**Tests.** Distance ordering against known coordinates, a product with zero sellers still returned, dark stores excluded, a null distance when no coordinates are supplied, and every filter combination.

### M3 Search
EP-14, EP-15, the Scout configuration, the indexing job, and the AI provider interface with both a fake adapter and the real one.

Build the fallback deliberately: force the fake adapter into a failing mode and confirm the response comes back with `mode: "keyword"` rather than an error.

**Tests.** Both modes asserted from the response body, the empty result state in each mode, and the seller endpoint returning 503 where the buyer endpoint returns 200.

### M4 Seller onboarding
EP-16, EP-17, EP-18, EP-54, and the geocoding provider interface with a LocationIQ adapter and a fake.
**Tests.** A second store refused with `store_exists`, geocoding failure returning **201 rather than 4xx**, the pin path setting the manual source, and the live flag staying false throughout.

### M5 The wizard path
EP-20, EP-23, EP-24, EP-48, EP-50, plus the variant generation service with its deterministic combination hash, the version creation service, and the search indexing job.

The wizard submit is **one transaction** covering product, attributes, variants, version 1, the version pointer, attachments, and the live flag. Build it as a single service class, not as controller code. A half created product with attributes but no variants would be unrecoverable, because there is no product deletion path.

**Tests.** The cross product generated correctly for zero, one, and two attributes, a single default variant where no attributes were defined, version 1 created with the pointer set, attachments created only for carried combinations, the eight image ceiling, format and size limits, and the whole transaction rolling back cleanly on a mid sequence failure.

### M6 The confirmation and proposal path
EP-19, EP-21, EP-22, and the reviewer notification email. **The heart of the platform. Do not compress this milestone.**

**Tests.** Every question must be answered with `confirmation_incomplete` otherwise, no attachment row while a proposal is pending, a second attempt returning `proposal_pending`, the confidence score written to the proposal and appearing in no response body, the review closing time exactly three days after opening, and the attached store set recorded at opening time not changing when a store attaches later.

### M7 Peer review and resolution
EP-27 to EP-30, the resolution matrix service, and the scheduled review window sweep.

**Tests.** Each of the four matrix rows, a tie escalating, non voters excluded from the denominator, a sole reviewer's single vote being a majority, approval creating a version and the proposing seller's attachment, rejection creating neither, a store not attached at opening unable to vote, a store that detaches mid window keeping its vote, voting twice refused, voting after close refused, and two simultaneous votes resolving exactly once.

### M8 Listing management and alerts
EP-25, EP-26, EP-36, EP-37, EP-38, the price drop alert job, and the nearby availability alert job.
**Tests.** A price decrease queuing alerts and an increase not doing so, repeat alerts suppressed by the last notified price, the live flag recomputed on both attachment creation and deletion, zero and negative prices rejected, and the product remaining visible after its last seller leaves.

### M9 Community and verification
EP-31 to EP-35, EP-57, the verification AI call, the photograph cleanup job, and the sentiment summary job.
**Tests.** Posting refused without verification for that specific product, verification on one product granting nothing on another, the attempt ceiling of five enforced per user per product, **the photograph deleted on both pass and fail** with the timestamp set, no response containing a photograph path, and soft deleted posts hidden along with their replies.

### M10 Analytics and version history
EP-39, EP-46, EP-47, EP-52.
**Tests.** Version history refused for an unattached seller even though they hold the seller role, access evaluated at request time so detaching removes it, rejected proposals absent from the history, anonymous access refused, and view counts attributed to the right store.

### M11 Administration
EP-40 to EP-45, EP-49, EP-58 to EP-61.
**Tests.** Both escalation outcomes unblocking the seller, direct edits creating a version with the administrator flag and the acting administrator recorded, adding an attribute option generating combinations **additively** while leaving existing attachments untouched, reversing an approval creating a further version, and a post soft deleted rather than removed.

### M12 Caching, revalidation, and hardening
EP-51 dispatched as a queued job on every version creation, catalogue response caching in Redis, the live flag reconciliation job, and Horizon configured with monitoring on the review window sweep and the photograph cleanup.

Review window expiry deserves particular attention. A proposal that receives no votes must escalate, and the proposing seller is blocked from selling until resolution. **A missed scheduler run leaves a seller unable to trade**, so this job requires monitoring rather than best effort execution.

**Tests.** The webhook rejecting a wrong secret, revalidation firing on version creation only and never on a rejected proposal, and a slow frontend not failing the request that created the version.

---

## 7. Dependency order

```
M0 Foundations
 ├── M1 Accounts
 │    └── M4 Seller onboarding
 │         └── M5 Wizard  ──────────────┐
 ├── M2 Catalogue read                  │
 │    └── M3 Search                     │
 └──────────────────────────────────────┤
                                        v
                              M6 Confirmation and proposals
                                        │
                                        v
                              M7 Peer review and resolution
                                        │
                    ┌───────────────────┼───────────────────┐
                    v                   v                   v
          M8 Listings and alerts   M10 Analytics       M11 Administration
                    │              and versions              │
                    v                                        │
          M9 Community and verification                      │
                    └───────────────────┬────────────────────┘
                                        v
                              M12 Caching and revalidation
```

M2 and M3 can run in parallel with M1 and M4. Everything from M6 onward is strictly sequential, because each milestone depends on records the previous one creates.

---

## 8. Verification

**Every milestone.**

```bash
vendor/bin/pint --format agent      # formatting
composer test                        # pint check, phpstan, and the full Pest suite
php artisan test --compact --filter=SharedDocs   # contract copies in sync
```

Then walk the milestone's demonstration flow by hand, with the frontend running against this API.

**Before handing a milestone to the frontend**, append an entry to [shared/milestone-log.md](shared/milestone-log.md) recording what shipped, which error codes are now live, and any deviation from this plan. Copy the shared folder across. The full checklist is section 9 of [shared/integration-protocol.md](shared/integration-protocol.md).
