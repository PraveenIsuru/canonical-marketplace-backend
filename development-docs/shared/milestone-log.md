# Milestone Log

**Status:** append only
**Applies to:** both repositories

---

## 0. How this file works

This file is **byte identical in both repositories**, at `development-docs/shared/milestone-log.md`. Both sides append to it. Whoever appends copies the file to the sibling repository in the same commit.

It exists so the other side of the system can answer "what actually shipped, and does it match what we planned" without reading a diff.

**Append, do not rewrite.** An entry that turned out to be wrong gets a correcting entry below it, not an edit. The history of what was believed and when is part of the value.

---

## 1. Status board

Update the two status columns as milestones complete. Everything else in this file is append only.

| Milestone | Backend | Frontend |
|---|---|---|
| M0 Foundations | Done, with deferrals | Done |
| M1 Accounts | Not started | Not started |
| M2 Catalogue read | Not started | Not started |
| M3 Search | Not started | Not started |
| M4 Seller onboarding | Not started | Not started |
| M5 Wizard | Not started | Not started |
| M6 Confirmation and proposals | Not started | Not started |
| M7 Peer review | Not started | Not started |
| M8 Listings and wishlist | Not started | Not started |
| M9 Community and verification | Not started | Not started |
| M10 Analytics and versions | Not started | Not started |
| M11 Administration | Not started | Not started |
| M12 Caching and revalidation | Not started | Not started |

Values: `Not started`, `In progress`, `Done`.

---

## 2. Entry template

Copy this block, fill it in, append it to section 3. Keep entries in chronological order, newest at the bottom.

```markdown
### M<n> <name>, <backend | frontend>, <YYYY-MM-DD>

**Shipped.**
- <endpoints for backend, screens for frontend>

**Contract.**
- Contract version at time of writing: <n>
- Changes made to api-contract.md: <none, or what and why>
- Error codes now live: <codes this milestone introduced>

**Deviations from the plan.**
- <what differs from the build plan, and why. Write "none" only when it is genuinely none>

**Known gaps handed to the other side.**
- <anything the other side must work around, or must not rely on yet>

**Verified by.**
- <which tests, and which demonstration flow was walked by hand>
```

---

## 3. Entries

### M0 Foundations, frontend, 2026-08-26

**Shipped.**
- Route groups `(public)`, `(auth)`, `(buyer)`, `(seller)`, `(admin)` under `app/`
- `lib/api/client.ts` with `ApiError`, `AiUnavailableError` carrying the queued job id, and `NetworkError`
- `proxy.ts` route protection, matcher excluding `products`, `search`, `stores`
- `lib/auth/` session helper, guards, and `useSession` hook
- Route handlers: `/api/auth/login` (writes the httpOnly cookie), `/api/auth/logout`, `/api/auth/session`, `/api/revalidate`
- Types for product, store, proposal, community, and the shared envelope
- `lib/query/` provider and key factory with the staleness table
- X-04 navigation, S-01 home shell, S-08 boundaries at root and under `products/[slug]`
- UI primitives: Button, Input, Select, Card, Skeleton, Alert, EmptyState, Dialog
- `lib/schemas/common.ts` with the paginator, session, and job schemas, plus a forbidden field assertion
- Dependencies installed: TanStack Query, Leaflet, date-fns, zod

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none
- Error codes now live: none. No feature endpoint is consumed yet

**Deviations from the plan.**
- **The X-04 navigation is split into a server half and a client half.** The plan implied a single server rendered navigation calling `getSession()`. Doing that put a `cookies()` read in the root layout, which forced every route in the application to render dynamically, `/` included. That breaks invariant 7 and would have silently destroyed the static generation the public catalogue needs for indexing. The session dependent half is now a client component reading `/api/auth/session`. Section 5.7 of the frontend build plan has been updated with the reasoning.
- A new route handler, `GET /api/auth/session`, was added to support that split. It is frontend hosted and calls EP-04 server side, so it is not a change to the API contract.
- `.gitignore` was amended so `.env.example` is committed while `.env.local` stays ignored. The starter's `.env*` pattern had been swallowing both.

**Known gaps handed to the other side.**
- Nothing blocking. M0 needed no endpoint.
- Observed while testing: the backend currently answers `POST /api/login` with Laravel's default 404, which carries `message` but no `code`. The frontend client falls back to `code: "unknown"`. This is expected until backend M0 installs the JSON error envelope, and is a good argument for doing that before any feature endpoint, as the backend plan says.

**Verified by.**
- `npm run build` clean, with `/` reported as static with a 1h revalidate
- `npm run lint` clean, `npx tsc --noEmit` clean
- `npm run docs:check` green
- By hand against `next dev`: home renders anonymously; `/dashboard`, `/wishlist`, and `/admin/metrics` each 307 to `/login?next=...`; `/products`, `/search`, and `/stores/1` are not intercepted by the proxy; `/does-not-exist` renders the not found boundary with a 404; `/api/revalidate` returns 401 with no secret, 401 with a wrong secret, 422 with no slug, and 200 with both; `/api/auth/session` returns `{"data":null}` with `Cache-Control: no-store, private`

### M0 Foundations, backend, 2026-08-26

**Shipped.**
- PostgreSQL confirmed as the connection, **PostGIS 3.6 enabled by migration** so a fresh clone gets it without a manual step
- `users` extended with `is_admin`, nullable `latitude` and `longitude`, a PostGIS `geography(Point, 4326)` column with a GIST index, and soft deletes
- Sanctum 4.3 and Scout 11.6 installed; `personal_access_tokens` migrated
- `routes/api.php` created and registered, grouped by the four access levels with each milestone's endpoints listed as comments
- Public health check at `GET /api/health`, deliberately dependency free
- **JSON error envelope from day one**: `ApiExceptionRenderer` maps every exception to `{ code, message, errors? }`, with `ApiException` named constructors for all 15 domain codes and `AiUnavailableException` putting `queued_job_id` at the top level
- Access middleware: `store` (403 `store_required`), `admin` (403 `forbidden`), `public` (no session resolution, sets `X-Access-Level`)
- Seven named rate limiters in `RateLimitServiceProvider`
- `tests/Feature/Api/ErrorEnvelopeTest.php` and `tests/Feature/Api/InvariantsTest.php`

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none. The implementation matched it
- Error codes now live: all 15 domain codes plus `validation_failed`, `unauthenticated`, `forbidden`, `not_found`, `rate_limited`, `server_error`. Every one is asserted by a test

**Deviations from the plan.**
- **Redis, Horizon, and a Meilisearch server are deferred.** None is installed on this machine and there is no Docker or WSL to run them. Cache and queue currently use the `database` driver, which the ADR rejected for production but which works for development. This blocks nothing until M3, which needs a Meilisearch server, and until queued jobs need Horizon's visibility.
- **PostgreSQL is 17.10, not the 16 the plan names.** It was already installed and PostGIS 3.6.2 came with it. Nothing in the schema depends on the difference.
- **Tests now run against PostgreSQL, not SQLite.** `phpunit.xml` pointed at SQLite `:memory:`, but this machine has no `pdo_sqlite` and, more importantly, SQLite cannot do PostGIS. A test suite on SQLite would not exercise the distance query, which is the part most likely to break. Tests use a separate `canonical_marketplace_test` database.
- **Rate limiters are prefixed `api-` for the credentials group.** Fortify already owns the names `login`, `two-factor`, and `passkeys`. Registering those names again silently replaced Fortify's limiters with differently keyed ones and broke its rate limit test.
- **`is_admin` is deliberately absent from the User model's fillable list**, so no registration payload can grant itself administrator. Asserted in the invariants test.
- **One starter kit test was updated, not deleted.** `ProfileUpdateTest` asserted an account is hard deleted. Soft deletes on users are required, since a deleted account must be reported as invalid credentials rather than as missing, which needs the row to survive. The assertion now expects a trashed model.
- `phpstan analyse` crashed at PHP's default 128M. The composer script now passes `--memory-limit=1G`.

**Known gaps handed to the other side.**
- The gap the frontend logged in its M0 entry is **closed**. `POST /api/login` now returns `{"code":"not_found", ...}` rather than Laravel's default 404 with no code. It stays 404 until EP-02 lands at M1.
- No feature endpoint exists yet. Every path except `/api/health` returns `not_found`.
- Before M3, a Meilisearch server must be installed and running. Before queued AI work matters, Redis must be available or the queue driver decision revisited.

**Verified by.**
- `composer test` green: Pint passed, PHPStan level 7 with 0 errors, Pest 72 passed and 9 todo
- Live against the running server: `/api/health` returns a `data` envelope with `X-Access-Level: public` and **no Set-Cookie**; an unknown API route returns the standard envelope
- PostGIS proven end to end by a test computing Colombo to Kandy distance in the database and asserting it falls between 80 and 110 km

---

---

> M0 is unusual: neither side depends on the other. The backend builds infrastructure and the error envelope, the frontend builds its shell, route groups, and API client. Both can append an M0 entry independently. From M1 onward the backend entry always precedes the frontend entry for the same milestone.

---

## 4. Open requests

Things one side needs from the other that are not yet built. Remove a row only when it has shipped and been recorded in section 3.

| Raised by | Date | Need | Status |
|---|---|---|---|
| Backend | 2026-08-26 | A Meilisearch server must be installed and running before M3 search work | Open, blocks M3 |
| Backend | 2026-08-26 | Redis must be available before queued AI work needs Horizon's visibility, or the queue driver decision revisited | Open, blocks nothing yet |

Use this table rather than guessing. A frontend screen that needs a field the contract does not define adds a row here. It does not invent a field name and hope.
