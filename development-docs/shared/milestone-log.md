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
| M1 Accounts | Done | Done |
| M2 Catalogue read | Done | Done |
| M3 Search | Done | Done |
| M4 Seller onboarding | Done | Done |
| M5 Wizard | Done | Done |
| M6 Confirmation and proposals | Done | Done |
| M7 Peer review | Done | Done |
| M8 Listings and wishlist | Done | Done |
| M9 Community and verification | Done | Done |
| M10 Analytics and versions | Done | Done |
| M11 Administration | Done | Done |
| M12 Caching and revalidation | Done | Done |

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

### M1 Accounts and roles, backend, 2026-08-26

**Shipped.**
- EP-01 `POST /api/register`, EP-02 `POST /api/login`, EP-03 `POST /api/logout`, EP-04 `GET /api/user`
- EP-05 `POST /api/password/forgot`, EP-06 `POST /api/password/reset`, EP-07 `PATCH /api/user/location`
- EP-55 `POST /api/email/verification-notification`, EP-56 `GET /api/email/verify/{id}/{hash}`
- `UserResource` as the single serialiser for the session user
- Five form requests reusing the starter's existing `PasswordValidationRules` and `ProfileValidationRules` concerns, so the API and the starter cannot drift on what a valid account is

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none. The implementation matched it
- Error codes now live from this milestone: `validation_failed` (with field `errors`), `unauthenticated`

**Response shapes the frontend should code against.**
- `POST /api/register` returns **201** with `data.token` and `data.user`
- `POST /api/login` returns **200** with the same shape
- `POST /api/logout` returns **204** with no body
- `GET /api/user` returns `data` as the user object directly: `{ id, name, email, email_verified_at, is_admin, latitude, longitude, store }`
- `store` is **always null** for now. The stores table lands at M4, so it is hard coded rather than guessed
- Invalid credentials return **422** `validation_failed` with the message on the `email` key, deliberately not saying which half was wrong

**Deviations from the plan.**
- **`User` now implements `MustVerifyEmail`.** Without it `event(new Registered)` sends nothing, so the requirement that registration dispatches a verification email could not be met. The starter's Fortify tests still pass.
- **`User` gained `HasApiTokens`.** Sanctum's `createToken()` does not exist without it.
- **The verification route is named `api.verification.verify`, not `verification.verify`.** Fortify already owns the bare name for the starter's web route, and two routes sharing a name silently breaks whichever loses. `VerifyEmail::createUrlUsing()` in `AppServiceProvider` points the emailed link at the API route explicitly.
- **`ResetPassword::createUrlUsing()` points at the frontend**, not the API. The person needs a form to type into and the API has no pages. The link is `{FRONTEND_URL}/reset-password?token=…&email=…`, which is what S-12 must read.
- **New config `app.frontend_url`, from `FRONTEND_URL`.** Added to `.env` and `.env.example`, defaulting to `http://localhost:3000`.
- **`is_admin` has a model level default of false** as well as a database default. Without it a freshly created model serialises `is_admin` as null on the registration response, and null is not the same answer as false to a client deriving roles.
- Two inaccurate PHPDoc annotations in the starter's `ProfileValidationRules` were corrected. `Rule::unique()` returns `Unique`, which does not implement `ValidationRule`, so the declared return type was wrong.

**Known gaps handed to the other side.**
- `store` is null on every session. Do not build seller navigation against real data until M4.
- Mail is on the `log` driver, so verification and reset emails land in `storage/logs/laravel.log` rather than an inbox. Grep for `email/verify` or `reset-password` to get a working link.
- Password rules are relaxed outside production by the starter's `Password::defaults()`, so a short password is accepted locally but not in production.

**Verified by.**
- 29 M1 tests in `tests/Feature/Api/AuthTest.php`, covering registration validation, duplicate email, the password confirmation, mass assignment of `is_admin`, invalid credentials not revealing which field was wrong, a soft deleted account treated as invalid, logout revoking only the current token, the reset token expiring and being refused on reuse, and a signed verification link replayed against another account
- `composer test` green: Pint passed, PHPStan level 7 with 0 errors, 101 passed and 9 todo
- Live against the running server: register issued a token and logged a verification link, `GET /api/user` returned the session user, the location write derived the PostGIS point, out of range coordinates were refused with field errors, a wrong password returned the neutral message, logout returned 204, and the revoked token then returned `unauthenticated`

---

### M1 Accounts and roles, frontend, 2026-08-26

**Shipped.**
- S-09 `/login`, honouring `?next=` and refusing any absolute URL there
- S-10 `/register`, with a dedicated path for an address that already has an account
- S-11 `/forgot-password`, whose confirmation copy is identical either way
- S-12 `/reset-password`, reading `token` and `email` from the emailed link
- S-13 `/verify-email` with resend, redirecting away when already verified
- S-16 `/account` with the profile summary and the saved location
- X-03 `LocationPrompt`, browser geolocation with manual entry as an equal path
- `lib/api/auth.ts`, `lib/location/geolocation.ts`, and the `(auth)` layout
- Route handlers `/api/auth/register` and **`/api/proxy/[...path]`**

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none
- Error codes handled on screen: `validation_failed` with per field errors, `rate_limited` with a wait message, `unauthenticated`

**Deviations from the plan.**
- **An authenticated API proxy was added at `/api/proxy/[...path]`, and this was not optional.** The plan had browser calls going to Laravel directly with `credentials: 'include'`. That cannot work here. The token is in an httpOnly cookie on `localhost:3000`, and a browser will not send that cookie to `localhost:8000`, nor can JavaScript read it to attach a Bearer header, because httpOnly is the entire point. Every authenticated browser call therefore goes through this application's own origin, where the handler reads the cookie server side and attaches the Bearer header. The token stays out of JavaScript and out of the network tab. Public catalogue reads do not use the proxy and will be fetched server side, which keeps them cacheable.
- `lib/api/client.ts` was changed to point browser calls at `/api/proxy` and to use `credentials: 'same-origin'`. `apiFetchServer` still calls Laravel directly.
- Auth screens are marked `robots: { index: false }`, matching the indexing rules.
- S-09, S-12, and S-13 are wrapped in `Suspense` because they read `useSearchParams`, which otherwise opts the route out of prerendering.

**Known gaps handed to the other side.**
- Nothing blocking.
- Every seller entry in the navigation is still unreachable, because `store` is null until M4. This is expected, not a bug.

**Verified by.**
- `npm run build` clean, 15 routes, `/` still static with a 1h revalidate
- `npm run lint` and `npx tsc --noEmit` clean
- Live against the running Laravel API, with a cookie jar: registration set an **httpOnly** `auth_token` cookie (confirmed by the `#HttpOnly_` prefix in the jar); the session endpoint returned the user; the proxy attached the Bearer token and returned the same user; **the proxy without the cookie returned `unauthenticated`**; the location write persisted and came back on the user
- Logout returned 204 and the session then resolved to null; login restored it; a wrong password returned the neutral message that does not say which half was wrong
- `/account` returned 200 when signed in and 307 to `/login?next=%2Faccount` when signed out
- The full reset cycle end to end: requested a link, pulled the real token from the mail log, reset the password, confirmed the old password stopped working, confirmed the new one worked, and confirmed reusing the same token was refused

---

### M2 Catalogue read path, backend, 2026-08-26

**Shipped.**
- The **entire database schema**: stores, products, product_attributes, variants, attachments, proposals, proposal_votes, product_versions, product_images, verification_attempts, community_posts, community_summaries, wishlist_items, product_views, with every index from the schema design
- Models for the catalogue half, with factories for all of them
- `CatalogueSeeder`: 5 products, 13 variants, 6 stores across 5 cities, 16 attachments
- EP-08 `/products`, EP-09 `/products/{slug}`, EP-10 `/variants`, EP-11 `/sellers`, EP-12 `/summary`, EP-13 `/stores/{id}`, EP-53 `/categories`
- `SellerListQuery` and `SellerListFilters`, the PostGIS distance query

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none
- Error codes now live from this milestone: `not_found` on an unknown slug and on a dark store

**Response shapes the frontend should code against.**
- `lowest_price_minor` and `currency` are **null** on a product no live store carries. Never zero, which would render as free
- `seller_count` counts **distinct stores**, so one store carrying three variants counts once
- `distance_km` is **null** when no `lat` and `lng` were supplied, and a rounded float otherwise
- `/summary` returns `data: null` when no summary exists, so the section is omitted rather than rendered blank
- `/variants` returns **every** combination, including those with `seller_count: 0`
- Query parameters on `/sellers`: `variant_id`, `lat`, `lng`, `max_distance_km`, `max_price_minor`, `min_rating`, `available_only`, `sort` (one of distance, price, rating), `page`

**Seeded data available to build against.**

There is no mock API, so this is what the screens have. It deliberately includes the states that are easy to forget:

| Slug | What it exercises |
|---|---|
| `vertex-one-smartphone` | Two attributes, six combinations, five sellers, a sentiment summary, **one combination nobody carries**, and **one seller out of stock** |
| `meridian-14-laptop` | One attribute, three combinations, two sellers in the same city |
| `standard-usb-c-cable-2m` | **No attributes at all**, so a single default variant and no variant selector |
| `orbit-wireless-earbuds` | **Zero sellers.** Still listed, null price, page still loads |
| `lumen-desk-lamp` | Zero sellers by a different route: its only would be seller is dark |

Stores sit in Colombo (two, a few km apart), Kandy, Galle, Jaffna, and one dark store in Matara. Log in as any seeded seller with the email pattern shown in the seeder.

**Deviations from the plan.**
- **The PostGIS point is derived in a model `saving` hook, not at each call site.** The column is NOT NULL, so setting it after insert is too late, and doing it per call site means every future path has to remember. The hook means the factory, the seeder, and the M4 registration endpoint all get a correct point for free.
- **`DatabaseSeeder` no longer uses `WithoutModelEvents`.** Store visibility is maintained by model events on `Attachment`. Muting events would have seeded a catalogue in which every store is dark and no seller list returned anything, which looks like a broken frontend for a reason nothing in the code explains.
- **`Product::attributes()` is named `productAttributes()`.** `attributes` collides with Eloquent's own internal attribute bag.
- The seeder spreads the two Colombo stores a few kilometres apart. At identical coordinates the seller list showed several rows at 0.0 km, which reads as broken.

**Known gaps handed to the other side.**
- `store` is still null on every session until M4, so seller navigation stays unreachable.
- Product images are seeded as rows with fake storage paths. No actual image files exist, so `primary_image.url` points at nothing. Expect broken images and build the placeholder state now rather than later.
- `current_version_number` is reported as 1 for every product. Real versions arrive at M5.

**Worth knowing: a hole in the live flag.**

`is_live` is maintained by model events on `Attachment`. A **mass delete** through the query builder, `$store->attachments()->delete()`, does not fire those events, so the flag would silently stay true and a dark store would keep appearing in seller lists. A test documents this. The application only ever deletes one attachment at a time, so it does not bite today, but it is the drift the design anticipated when it called for a periodic reconciliation job at M12.

**Verified by.**
- 25 tests in `tests/Feature/Api/CatalogueTest.php`, including distance ordering asserted against real coordinates from two different buyer locations, dark stores excluded, null distance without coordinates, every filter, prices as integers, and a public route returning identical data with and without a token
- `composer test` green: Pint passed, PHPStan level 7 with 0 errors, 125 passed and 9 todo
- Live against seeded data: Colombo to Kandy measured 97 km and Colombo to Jaffna 303 km, matching real geography; ordering flipped correctly when the buyer moved to Jaffna; `max_distance_km=120` dropped Jaffna; `available_only` dropped the out of stock row; the dark store returned 404

---

### Infrastructure note, both repositories, 2026-08-26

**The shared docs sync check had a flaw, found by the check itself.**

The backend carries a `.gitattributes` with `eol=lf`; the frontend carried none. On Windows that means two byte identical documents differ by one byte per line, so hashing raw bytes reported drift that was not real. Once both repositories were committed it would have failed permanently, and a check that cries wolf is one people learn to ignore.

Both checkers now normalise line endings before hashing, because what matters is that the content agrees; line endings are a platform artifact. A `.gitattributes` was also added to the frontend so the stored bytes match too.

---

### Infrastructure note, backend, 2026-08-26

Re-recorded. This entry was written when Meilisearch was configured and was lost from the log at some point between then and the M2 commit. The work itself was never lost, and was re-verified before writing this.

**Meilisearch configured.**
- Meilisearch Cloud, server 1.53.1. `meilisearch/meilisearch-php` installed, `config/scout.php` published
- `SCOUT_DRIVER=meilisearch`, `SCOUT_QUEUE=true` so indexing runs off the request, which matters at M5 where the wizard submit is already one large transaction
- `MEILISEARCH_HOST` and `MEILISEARCH_KEY` live in `.env` only. The key is admin scoped, which Scout needs in order to create indexes and write documents
- Verified by a health check, a version read, an index create, and a delete

**PHP had no CA certificate bundle at all.** This was the important half.

`curl.cainfo` and `openssl.cafile` were both empty and no `cacert.pem` existed anywhere, so **every outbound HTTPS request from PHP failed**, including to google.com. It surfaced as a Meilisearch connection error but was never specific to Meilisearch.

Left unfixed it would have broken the AI provider at M3, LocationIQ geocoding at M4, and S3 object storage at M7, each looking like a vendor outage rather than a local misconfiguration.

Fixed by downloading the Mozilla CA bundle to `C:\php-8.3.12\extras\ssl\cacert.pem` and pointing both ini directives at it. `php.ini` was backed up first. Certificate verification was **not** disabled, since doing so would have hidden the fault and followed the project into production.

This is a machine level change and lives outside both repositories. A different machine will need it done again.

**Open decision, not blocking.** The ADR rejected Algolia because a hosted service with per operation pricing was inappropriate for this project, and chose Meilisearch partly because it self hosts free as a single binary. Meilisearch Cloud reintroduces that cost after a 14 day trial. The configuration is identical either way, so switching to the local binary is a one line change to `MEILISEARCH_HOST`. Decide before the write up whether the report describes self hosted or hosted search.

---

### M2 Catalogue read path, frontend, 2026-08-26

**Shipped.**
- S-01 `/` with category tiles and a recently added strip, static, revalidated hourly
- S-02 `/products` with category filtering in the URL and pagination
- S-04 `/products/[slug]`, prerendered per product, with client variant selection
- S-05 `/products/[slug]/sellers` with filters, sorting, and pagination
- S-07 `/stores/[id]`, contact block and listings
- X-03 location prompt wired into S-04 and S-05, not just the account screen
- `lib/api/catalogue.ts` and `lib/schemas/catalogue.ts` for EP-08 to EP-13 and EP-53
- `components/product/ProductImage.tsx`, the placeholder for images that fail to load
- `scripts/verify-m2-contract.mjs`, which parses every live M2 response through its schema

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none
- Error codes handled on screen: `not_found` on an unknown slug and on a dark store

**Deviations from the plan.**
- **Catalogue reads bypass `/api/proxy` entirely.** The proxy exists to attach a session token; routing public reads through it would resolve a session on the highest traffic paths and make the responses uncacheable. They go server side straight to Laravel.
- **The seller list is cached when it is not personalised, and uncached when it is.** Without coordinates or filters the response is identical for every visitor, and caching that call is what allows S-04 to be statically generated. Any request carrying coordinates, filters, or a sort passes `revalidate: 0`, so one buyer's distance ordering can never be served to another.
- **`loading.tsx` was removed from `products/` and `stores/[id]`, replaced by `<Suspense>` inside the catalogue page.** See the note below; this one is worth reading before adding a `loading.tsx` anywhere near a route that can 404.
- **`getProducts` takes a `revalidate` argument.** A hardcoded 300 inside the helper was silently overriding the home page's hourly setting, because Next uses the shortest revalidate across every fetch in a route.
- S-05 fetches an unfiltered list on the server and hands it to the client panel as initial data, so contact details are in the server rendered HTML rather than appearing only once JavaScript runs.

**A backend contract violation this milestone caught.**

`EP-11 /sellers` returned `attribute_values` as `[]` for a product with no attributes, while `EP-10 /variants` returned `{}` for the same variant. The contract specifies an object. A product whose default variant has an empty combination is stored as an empty JSON array, and `json_decode` handed that straight back.

It was found by the zod schema at the fetch boundary during a build, not by a screen rendering something odd. Fixed at the source in `SellerListingResource` with a cast, matching what `EP-10` already did, and covered by a regression test asserting the raw JSON, since `json_decode` to an array cannot tell `{}` from `[]`. This is the drift the schemas exist to catch.

**Worth knowing: notFound() and streaming.**

A `loading.tsx` beside a route applies to **every nested route as well**, and it makes Next begin streaming before the page component runs. Once streaming has begun a `notFound()` can no longer change the status, so the page renders the not found UI with a **200**. That is a soft 404 a crawler will index.

`app/(public)/products/loading.tsx` was doing exactly this to `/products/[slug]`. Before the fix, an unknown slug and a dark store both answered 200. Both now answer 404. The loading state was restored as a `<Suspense>` boundary **inside** the catalogue page, which is scoped to that page and does not leak downward.

**Known gaps handed to the other side.**
- Product images 404, because the seeder writes storage paths for files that were never uploaded. The placeholder handles it and stays useful once real uploads arrive at M5.
- The wishlist button on S-04 is a disabled affordance bound to the selected variant. The mutation is M8, and rendering a control that fails when clicked would be worse than one that says it is not ready.
- S-07 is server rendered on demand rather than prerendered at build. There is no endpoint that lists live stores, so there is nothing to enumerate for `generateStaticParams`. It still caches for 300 seconds after first request. See the open request below.
- Seller navigation remains unreachable, as expected while `store` is null.

**Verified by.**
- `npm run build`, `npm run lint`, and `npx tsc --noEmit` all clean. All five seeded products prerender as SSG; `/` is static at 1h; `/products` and `/products/[slug]/sellers` are dynamic, which is correct
- `scripts/verify-m2-contract.mjs`: all 11 live responses parse, including both `/summary` states and both `/sellers` coordinate modes
- Against the production build with seeded data: all five products listed anonymously; all six combinations of `vertex-one-smartphone` render including the one nobody carries; the summary shows; `standard-usb-c-cable-2m` renders **no** variant selector; `orbit-wireless-earbuds` and `lumen-desk-lamp` load with the empty seller state and no price; with no location **no distance is rendered at all** and never a zero; from Colombo the distances read 2.1, 5.6, and 97.0 km in a sensible order; `available_only` cut 10 sellers to 9, `max_distance_km=50` to 4, `max_price_minor=240000` to 2; the store page shows address, email, and phone with no login; the dark store is absent from every seller list and answers 404 at its own URL

---

### M3 Search, backend, 2026-08-26

**Shipped.**
- EP-14 `GET /api/search`, public, and EP-15 `GET /api/seller/catalogue-search`, seller only
- `AiProvider` interface, with `FakeAiProvider` (including a deliberate failing mode) and `AnthropicAiProvider`
- `AiServiceProvider` binding the interface by config, and `config/ai.php`
- `ProductSearchService`, `SearchMode`, and `SearchResult`
- Scout `Searchable` on `Product`, with Meilisearch index settings
- `ai_jobs` table, `AiJob` model, and the `InterpretSearchQuery` queued job
- The seeded catalogue is indexed: 5 documents, searchable by name, category, description, and specification values

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none. The implementation matched it
- Error codes now live from this milestone: `ai_unavailable`, from EP-15 only

**Response shapes the frontend should code against.**
- Both endpoints return `mode` **beside** `data` at the top level, never inside it. Values are `ai` and `keyword`
- **EP-14 never returns `ai_unavailable` and never queues work.** On any provider failure it returns **200** with `mode: "keyword"`
- **EP-15 does the opposite.** On provider failure it returns **503** with `code: "ai_unavailable"` and `queued_job_id` at the top level. That body carries no `data` and no `mode`
- Query parameters on both: `q` (required, 1 to 200 characters) and `category` (optional)
- Pagination links carry `q`, not Scout's own `query` parameter

**Deviations from the plan.**
- **The `AiProvider` interface carries one method, not five.** The ADR describes five kinds of AI call. Only `interpretSearchQuery` exists, because four unimplemented stubs would be dead code no test exercises. The interface grows one method per milestone, and the coming methods are listed in its docblock, including the note that two of them need vision capable models.
- **An `ai_jobs` table was added, which the schema document does not define.** EP-15 must return a `queued_job_id` that EP-50 can later poll for a status and a result. Laravel's own `jobs` table deletes the row the moment work finishes, so polling it would report "not found" for every job that succeeded. The contract already specifies the job payload; this is the storage it implies.
- **`stores.location` is now a PostGIS generated column.** It was previously built by a model `saving` hook, which was a convention any future write could forget. The database derives it from the coordinate pair, so the two cannot disagree by construction. This also removed the last place PHP assembled spatial SQL by hand.
- The fake interpreter strips filler words rather than doing anything clever. Its purpose is to return something **different** from the raw query, so a test can tell which path served a response from the results rather than trusting the mode field to be honest about itself.

**Two defects found and fixed during this milestone.**

**The test suite was writing to the live Meilisearch index.** Making `Product` searchable without disabling Scout in tests meant every factory created product was pushed to the real Cloud index and left there by rollback. It grew to 29 documents, displaced the seeded catalogue, and a manual search for a seeded product returned nothing **while the suite still passed green**. `phpunit.xml` now sets `SCOUT_DRIVER=null`, and the index was flushed and reimported. Tests must never write to a shared external service.

**PHPStan had 63 pre-existing errors from M2 that went unreported.** They were in the M2 resources, controller, and factories, and were missed because the `composer test` run at the end of M2 was backgrounded and its output truncated; the exit code was read without the analysis lines. The earlier claim that M2 passed "PHPStan level 7 with 0 errors" was wrong, and this entry corrects it. The root cause was relation methods declared without generics, so Larastan resolved every relation to `Collection<Model>`. All are now fixed at source and the analyser is genuinely at zero.

**Known gaps handed to the other side.**
- **Indexing runs through the queue.** `SCOUT_QUEUE=true` with the `database` driver means a worker must be running, or nothing is indexed. `scout:import` reports success either way, which is misleading. Run `php artisan queue:work --stop-when-empty` after importing, and keep a worker running from M5 when the wizard indexes new products.
- **Keyword mode is genuinely worse than AI mode**, which is the point of the visible notice. A verbose query like "I am looking for a good smartphone" finds the product in `ai` mode and returns nothing in `keyword` mode, because the raw string goes to the engine untouched. S-03 should show both the fallback notice and the no matches message so a visitor can tell a weak query from a degraded service.
- EP-50 `GET /api/jobs/{id}` does not exist yet; it lands at M5. A `queued_job_id` from EP-15 is a real, persisted row, but nothing can poll it until then.
- The real Anthropic adapter is implemented but unexercised. `AI_PROVIDER=fake` is the default, and no test touches the network.

**Verified by.**
- 22 tests in `tests/Feature/Api/SearchTest.php`, covering both modes read from the response body, empty results in each mode, buyer parity with and without a token, no session started, and the queued job completing and failing
- The decisive test asserts the divergence directly: one provider failure, buyer 200 with `mode: "keyword"`, seller 503 with `ai_unavailable`
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 149 passed and 9 todo
- Live against the running server and the real index: `vertex` returns the smartphone in `ai` mode; "I am looking for a good smartphone" and "I would like a cheap laptop please" both resolve to the right product; with `AI_FAKE_SHOULD_FAIL=true` the buyer endpoint stays 200 with `mode: "keyword"` while the seller endpoint returns 503 with a top level `queued_job_id` and no `data` or `mode` key

---

### M3 Search, frontend, 2026-08-26

**Shipped.**
- S-03 `/search`, server rendered per request, under `(public)`
- X-02 `KeywordFallbackNotice`, rendered only when the response says `mode` is `keyword`
- `SearchForm`, a plain GET form now shared by S-03 and the home page
- `searchResponseSchema` and the `searchProducts` helper for EP-14
- `scripts/verify-m3-contract.mjs`, which parses live EP-14 responses and asserts a body missing `mode` is rejected

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none
- Error codes handled on screen: none new. EP-14 answers 200 in both modes, so the only failure path is the ordinary error boundary

**How the notice is driven.**

By `mode` from the response body, and by nothing else. The client does not infer a fallback from an empty result set, a slow response, or anything it noticed itself, and it has no fallback logic of its own to grow. `mode` is required in the schema rather than optional with a default, so a body missing it fails loudly instead of quietly reading as `ai`.

**The two empty states, which are not the same state.**

A verbose query finds the product in `ai` mode and returns nothing in `keyword` mode, because the raw string goes to the engine untouched. So an empty result means different things depending on which path served it, and the screen says so:

- Empty in `keyword` mode: the notice stays visible **and** the empty state explains that smart search would normally understand a phrase like this, suggesting a shorter term.
- Empty in `ai` mode: a plain "nothing matched" with no suggestion that anything failed, because nothing did.

Collapsing these into one message would leave a visitor unable to tell a degraded service from a weak query, which is the whole reason the fallback is visible rather than silent.

**Deviations from the plan.**
- **No EP-15 client helper was added.** The plan ties M3 to S-03 and X-02 only, and seller catalogue search has no screen until the attachment flow at M5. A helper with no caller would be dead code, and its failure path needs the queued job panel, which is M5 work.
- **An empty `q` makes no request at all.** The API requires `q` and answers 422, so calling it would turn "you have not searched yet" into an error the visitor did nothing to cause. The screen shows a prompt instead.
- The loading state is a `<Suspense>` boundary inside the page, keyed on the query, rather than a `loading.tsx`. A segment level file would apply to sibling routes and start streaming before the page component runs, which is what produced soft 404s during M2.

**Known gaps handed to the other side.**
- Nothing blocking.
- `/search` is deliberately `noindex, follow`, so it will not appear in search engines. That is per the indexing rules; product pages carry the indexable content.
- Category filtering is read from the URL and passed through to EP-14, but no category control is rendered on S-03. The catalogue screen owns that interaction, and adding a second one here was not asked for.

**Verified by.**
- `npm run build`, `npm run lint`, and `npx tsc --noEmit` all clean. `/search` builds as a dynamic route, which is correct for an endlessly varying query
- `scripts/verify-m3-contract.mjs`: live responses parse, and a body without `mode` is rejected
- Against the production build and the live API, with the backend toggled both ways:
  - AI mode, verbose query: product card shown, **no notice**
  - AI mode, no match: plain empty state, **no notice** and no claim that anything failed
  - Keyword mode, verbose query: **notice shown and empty state shown together**, with wording specific to the degraded path
  - Keyword mode, short query `vertex`: notice shown **and** the product found
  - Toggling the backend back to healthy made the notice disappear again
  - `/search` returns 200 with no token and carries `robots: noindex, follow`
  - An empty query renders the prompt with no error

---

### M4 Seller onboarding, backend, 2026-08-27

**Shipped.**
- EP-16 `POST /api/stores`, EP-17 `POST /api/stores/mine/pin`, EP-18 `PATCH /api/stores/mine`, EP-54 `GET /api/stores/mine`
- `GeocodingProvider` interface, with `FakeGeocodingProvider` (including a failing mode) and `LocationIqProvider`
- `config/geocoding.php` and the binding in the provider that already binds the AI adapter
- `StoreRegistrationService`, `StoreWriteResult`, `OwnStoreResource`, and three form requests
- `GET /api/user` now returns a **real** store object instead of a hard coded null

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none
- Error codes now live from this milestone: `store_exists`

**Response shapes the frontend should code against.**
- `geocoding_failed` sits **inside `data`**, per section 11.3 of the contract. EP-16's own wording says "at the top level of a 201 response", which is ambiguous; the contract is what the client mirrors, so `data` wins. Noted rather than silently chosen
- The field appears **only on a write**: EP-16, EP-17, and EP-18 responses carry it, EP-54 does not
- `latitude`, `longitude`, and `geocode_source` are **null** until geocoding succeeds or a pin is placed. Null is the routing signal into pin placement and must not be defaulted
- `geocode_source` is `locationiq` or `manual_pin`
- `is_live` is **false throughout onboarding** and there is no endpoint in this milestone that can change it
- `GET /api/user` returns `store` as `{ id, name, is_live }` or null. Deliberately minimal; EP-54 is what prefills the settings form

**Deviations from the plan.**
- **`latitude`, `longitude`, and `geocode_source` are now nullable.** The schema design made all three NOT NULL, but EP-16 and contract section 11.3 both require creating a store with null coordinates when geocoding fails. Two independent specifications describe that path, so the constraints are what gave. `location` is a generated column and becomes null on its own, which is correct: a store with no coordinates cannot appear in a proximity sorted list, and it is not live either.
- **EP-16 sits behind `auth:sanctum`, not the seller middleware.** The caller has no store yet, so the seller check would refuse the very request that creates one.
- **A failed re-geocode on EP-18 keeps the previous coordinates.** The endpoint spec says to keep them; worth restating because the alternative is quietly removing a working store from every proximity sorted list because an edit to an unrelated field failed.
- EP-18 re-geocodes only when the address or city actually changed. Re-running it on a phone number edit would spend a provider call answering a question nobody asked, and could replace good coordinates with a worse match.

**A route collision found and fixed.**

`GET /api/stores/{store}` was registered before `GET /api/stores/mine`, so route model binding tried to resolve a store with the id `mine` and the seller endpoint was unreachable. The public route is now constrained with `whereNumber('store')`, which fixes it regardless of registration order rather than relying on the file staying in a particular sequence.

**Known gaps handed to the other side.**
- **A store created through the geocoding failure path has null coordinates**, and S-18 must collect a pin before the seller can do anything useful. The 201 is not an error and must not be styled as one.
- `is_live` stays false for every store this milestone can produce. Seller navigation will now appear because `store` is populated, but the dashboard should state plainly that the store is not yet visible and that at least one approved listing is required.
- The real LocationIQ adapter is implemented but unexercised: `GEOCODING_PROVIDER=fake` is the default and no test touches the network. The fake resolves six Sri Lankan cities and treats anything else as a failure, which is a convenient way to demonstrate the pin flow without changing config.
- EP-19 `GET /api/stores/mine/listings` is **not** part of this milestone. It lands at M6, so the dashboard cannot list listings yet.

**Verified by.**
- 31 tests in `tests/Feature/Api/SellerOnboardingTest.php`, covering the second store refused with `store_exists`, geocoding failure returning 201 rather than a 4xx, the pin path recording the manual source, the live flag staying false across create, pin, and update, validation, `store_required` on every seller route, and a payload being unable to set coordinates or visibility
- One test walks invariant 12 end to end: a newly registered store is absent from the buyer seller list, and appears the moment it holds an attachment
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 180 passed and 9 todo
- Live against the running server: a new account showed `store: null`, seller routes returned `store_required`, store creation returned 201 with coordinates and `is_live: false`, `GET /api/user` then returned the minimal store object, a second create returned 409 `store_exists`; with the geocoder forced to fail, creation still returned **201** with null coordinates and the submitted details intact, and the pin endpoint then set the coordinates with `geocode_source: manual_pin` while `is_live` stayed false

---

### M4 Seller onboarding, frontend, 2026-08-27

**Shipped.**
- S-17 `/sell/start`, store registration
- S-18 `/sell/pin`, manual pin placement with a draggable Leaflet map
- S-19 `/store/settings`, prefilled from EP-54
- S-20 `/dashboard`, the empty state
- `(seller)` route group and layout, `lib/api/stores.ts`, `ownStoreSchema`, `components/seller/StorePinMap.tsx`
- Seller navigation entries now light up on their own, because `AccountNav` already derived them from `session.store` and the backend populates it

**Contract.**
- Contract version at time of writing: 1
- Changes made to api-contract.md: none
- Error codes handled on screen: `store_exists` (409), `validation_failed` (422), `store_required` (403)

**How geocoding failure is presented, which is the point of this milestone.**

It is not an error, and nothing in the interface treats it as one.

The API answers **201**. The store exists, the submitted details were kept, and only the location is missing. So S-17 reads `geocoding_failed` and **redirects to the pin screen**, exactly as a successful registration redirects to the dashboard. There is no red, no "failed", and no invitation to retry the form.

S-18 then explains it in its own words: the address could not be matched automatically, and buyers see sellers by distance, so the seller is asked to show where they are. The same field on EP-18 routes to the same screen while keeping the previous coordinates, so an edit that failed to re-geocode never leaves a seller worse off than before.

**Deviations from the plan.**
- **`needsPinPlacement()` checks null coordinates as well as `geocoding_failed`.** The flag appears only on a write, so a settings page loading a half configured store through EP-54 can tell only from the nulls. Checking one and not the other would leave that seller with no route to the pin screen.
- **The Leaflet marker icon is built explicitly from the packaged images.** Leaflet resolves its default icon by a relative path that the bundler rewrites, so the pin renders invisibly otherwise. This is the standard fix and has to run on the client.
- **The numeric coordinate fields stay available even when the map works.** If tiles fail there has to be a way through, and some sellers simply know their coordinates.
- **S-20 shows no listings table.** EP-19 lands at M6. An empty grid would imply the seller has no listings, when in fact the feature that creates them has not shipped, so the screen says that plainly instead.

**Known gaps handed to the other side.**
- Nothing blocking.
- The dashboard states that attaching to a product is not available yet. That copy should be replaced when the attach flow lands rather than left to age.
- `(seller)` screens are client rendered, per the plan, so their content is not in the server HTML. Route protection still happens in `proxy.ts` before the page runs, and the API refuses independently.
- The registration rate limiter is 3 per hour per IP, which is easy to hit while testing by hand. Create the account directly and sign in, rather than assuming registration is broken.

**Verified by.**
- `npm run build`, `npm run lint`, and `npx tsc --noEmit` all clean
- Against the production build and the live API, through the authenticated proxy:
  - A new account reports `store: null` and every seller endpoint returns `store_required`
  - Registration returned **201** with coordinates, `geocode_source: locationiq`, and `is_live: false`; the session then carried `{ id, name, is_live }`, which is what unlocks the seller navigation
  - A second registration returned **409 `store_exists`**
  - With the geocoder forced to fail, registration still returned **201** with null coordinates and the submitted details intact, and the pin endpoint then set them with `geocode_source: manual_pin` while `is_live` stayed false
  - EP-54 prefilled the settings form and carried **no** `geocoding_failed`; a PATCH round tripped and reported it as false
  - An address change that failed to re-geocode saved the new address, signalled `geocoding_failed`, and **preserved the previous coordinates**
  - All four seller routes return 200 signed in and 307 to `/login?next=…` anonymously
  - The newly registered stores are **absent** from the public seller list, because they carry nothing

---

### M5 The wizard path, backend, 2026-08-27

**Shipped.**
- EP-20 `POST /api/attach/match`, EP-23 `POST /api/attach/wizard/start`, EP-24 `POST /api/attach/wizard/submit`, EP-48 `POST /api/products/{slug}/images`, EP-50 `GET /api/jobs/{id}`
- `ProductMatchingService`, `ProductWizardService`, `VariantGenerationService` with the deterministic combination hash, `ProductVersionService`
- `AiProvider` grew `scoreProductMatches` and `generateWizardQuestions`, implemented in both the fake and the real adapter
- `attach_sessions` table and the `AttachSession` model
- Queued jobs `MatchProduct`, `GenerateWizardQuestions`, and `IndexProduct`
- `ProductImageService` and a shared `ImageUpload` validator, plus the two image disks

**Contract.**
- Contract version at time of writing: **bumped from 1 to 2**
- Changes made to api-contract.md: `search_interpretation` added to `result_type` in section 8, which seller catalogue search has emitted since M3 and the list omitted; stated that `result_type` is null until a job completes and that another user's job answers 404; added section 11.7, the wizard submit outcome
- Error codes now live from this milestone: `match_required`, `unsupported_media_type`, `file_too_large`, `image_limit_reached`

**Response shapes the frontend should code against.**
- EP-20 returns `{ data: { candidates: [...] } }`. **An empty array is a success, not an error.** It is the answer that routes the seller to the wizard, and nothing in the interface should style it as a failure
- Each candidate is `{ product_id, slug, name, primary_image_url, match_score }`. `primary_image_url` is **null** where the record holds no images, which is common
- `match_score` is between 0 and 1. It is **not** the confidence score the contract forbids exposing. That one is written to a proposal at M6 and drives the resolution matrix; this one describes a search result and decides nothing
- EP-23 returns `{ data: { session_id, questions: [{ id, attribute, text }], expires_at } }`. `expires_at` is not in the api specification and was added here: a session lasts **24 hours**, and a client that cannot see the deadline cannot warn anyone about it
- EP-24 returns the shape now recorded in section 11.7 of the contract
- EP-48 returns `{ data: { id, url, mime_type, position, uploaded_by_user_id } }`. There is no storage path and no moderation status
- EP-50 returns `result_type` and `result` as **null until the job completes**, on a failed job included

**Deviations from the plan.**
- **EP-23 re-runs matching itself rather than trusting the client.** The rule is that the wizard is reachable only when matching returned nothing, and a client that reports its own compliance is not enforcing anything. It costs one extra provider call per wizard start, which is the right price for making `match_required` mean something. The alternative was a match token in the request, which would have been a shape invented to avoid doing the check.
- **The shortlist for matching comes from PostgreSQL, not Meilisearch**, even though buyer search uses the index. Two reasons. Indexing runs off the request, so a product created moments ago by another seller may not be searchable yet, and missing it would admit exactly the duplicate this step exists to catch, invisibly. And the index is an external service: buyer search may degrade to keyword results because a worse list is still useful, but matching cannot degrade at all. The AI then scores the shortlist, so retrieval is generous and precision is the provider's job.
- **`AiProvider::scoreProductMatches` takes candidates rather than going looking for them.** An adapter that queried the database would be a vendor class holding a business query, and asking a model to recall the whole catalogue from a prompt is not something any model does reliably. The reply refers to candidates by their position in the prompt, so an invented product id cannot reach the database.
- **`attach_sessions` is a new table, not in the schema design.** The endpoints hand back a `session_id` submitted later, the provider may be unavailable when the questions are wanted, and completeness has to be checkable: a client supplying both the questions and the answers could always claim it answered them. It carries a `type` column so the confirmation flow at M6 uses the same table rather than a second one with the same five columns.
- **An unanswered wizard question returns `validation_failed`, not `confirmation_incomplete`.** That code belongs to the confirmation flow at M6, where it means a seller skipped part of a review of an existing record. Here the errors name the specific questions, keyed `answers.q2`, which is more useful, and borrowing the other code would make a client handling it show the wrong screen.
- **An expired session returns `match_required`.** The seller has to start again, because the catalogue may have gained the very product they are describing while the session sat open, and matching has to run before the wizard opens a second time.
- **`carried_variants` requires at least one entry.** A seller reaches the wizard in order to sell something, and a run carrying nothing would create a permanent canonical record while leaving the store dark, which is not an outcome the flow describes.
- **A carried combination the defined attributes cannot produce is refused, not skipped.** Skipping it would report a lower attachment count than the seller listed, with no way for them to find out which entry vanished.
- **`image_limit_reached` is 422, following the contract.** The api specification writes it as 409. The contract is what the client mirrors, so 422 wins. Noted rather than silently chosen, the same way the `geocoding_failed` placement was at M4.
- **EP-20 does not check `proposal_pending`.** The refusal is listed for it, but no proposal can exist until M6 creates one, and the check needs a product that matching has not yet identified. Blocking all matching because a seller has a pending proposal on some unrelated product would be far broader than the rule, which blocks a seller on **that** product only. It is enforced at EP-21 at M6, where the product is known.
- **Search indexing is dispatched after the transaction commits, not by Scout's observer.** The observer fires on save, which is inside the transaction, so an indexed product could be advertised before its row committed and would stay in the index if the transaction rolled back.
- Frontend revalidation and nearby availability alerts are listed among EP-24's dispatches but belong to M12 and M8. Neither is dispatched yet.

**A timezone defect found and fixed, which was not an M5 bug.**

`timestamptz` values were being stored at the wrong instant. Laravel writes a datetime as `Y-m-d H:i:s` with no offset, and PostgreSQL reads a naive value into a `timestamptz` by assuming the session timezone, which defaults to the server machine's. On this machine that is Asia/Colombo, so every such value was stored five and a half hours away from the moment the application meant, and reading it back produced a different instant than the one written.

It surfaced here because `attach_sessions.expires_at` is the first `timestamptz` compared against `now()`: a session set to expire in one hour was already expired on the next request. It was never specific to M5. `config/database.php` now pins the connection to UTC.

Left unfixed it would have reached M6 and M7 as a **wrong proposal deadline**. A three day review window computed and compared through that column would have closed hours early or late, and the peer review resolution matrix is built entirely on that deadline. It would have looked like a scheduling bug rather than a connection setting.

**The two image disks from M0 now exist.** The M0 plan called for product images and verification photographs on separate disks from the beginning, and it was not done. `ProductImage::url()` already read a config key that did not exist and fell back to the public disk. Both disks are now defined. Verification photographs are private and nothing serves a URL from that disk, so the unconditional deletion at M9 cannot reach catalogue images.

**`php artisan storage:link` is now a setup step.** It had never been run, which nothing had noticed because no endpoint served a file until now. Without the link, an image URL does not 404. It returns **403**, because Laravel's own `storage/{path}` route, registered by the `local` disk, catches the request and looks for the file on the private disk instead. That is a confusing failure to debug from the outside, since the upload succeeds, the row is correct, and the bytes are on disk. This is a machine level step like the CA bundle at M3, so a fresh clone needs it too.

**Known gaps handed to the other side.**
- **EP-19 `GET /api/stores/mine/listings` still does not exist.** It lands at M6, so the dashboard still cannot list what a seller carries, even now that the wizard creates listings.
- The confirmation path is not built. A seller whose product **does** match has nowhere to go after EP-20 returns candidates until M6 ships EP-21 and EP-22. Matching answers, and the flow stops there.
- `variants_generated` will usually exceed `attachments_created`. Do not present that as a warning or an error. The uncarried combinations are permanent and appear on the product page with no sellers.
- The wizard image ordering in UF-16 puts image upload before submit, but EP-48 needs a product slug, so the product must exist first. Upload after the submit returns, against the slug it gives back.
- The fake AI provider asks the **same six wizard questions every time**, and says so in its own docblock. That is enough to build S-25 against, but the questions are not tailored to the product until `AI_PROVIDER=anthropic`.
- The fake matcher scores on name word overlap above 0.45. A near identical name matches and a genuinely different one does not, which is a convenient way to demonstrate both branches without changing config.
- The `attach` limiter is **20 per hour**, which is easy to reach while testing the flow by hand.

**Verified by.**
- 39 tests in `tests/Feature/Api/WizardTest.php`, covering the cross product for zero, one, and two attributes, the single default variant where none were defined, version 1 created with the pointer set, attachments created only for carried combinations, the eight image ceiling, the format and size limits, an empty match result answering 200, `match_required` when a candidate is outstanding, and a job readable only by its owner
- The transaction rollback is tested by throwing part way through, after the product row exists and while attributes are being created. Product, attributes, variants, version, and attachment are all absent afterwards, the store is still dark, and the session survives so the seller can retry against the same questions
- Two invariants moved from todo to asserted: generated combinations survive a run that carried only one of them and are still returned by the public variant list, and a store is visible if and only if it holds an attachment, tested in both directions
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 228 tests with 221 passed and 7 todo
- Live against the running server, walked end to end:
  - A new account got 403 `store_required` on the attach routes, then registered a store
  - EP-20 on a seeded product name returned one candidate at score 1.0; EP-23 on the same name returned **422 `match_required`**
  - EP-20 on a genuinely new product returned **200 with an empty array**, and EP-23 then opened a session with six questions and an expiry 24 hours out
  - EP-24 with one question blank returned 422 with the error keyed `answers.q3`, naming the question rather than the form
  - EP-24 complete, with two attributes of two and three options and two combinations carried, returned **201 with `variants_generated: 6`, `attachments_created: 2`, `store_is_live: true`**
  - The public variant list returned **all six** combinations, the four uncarried ones showing `seller_count: 0` and a null lowest price; the product response carried no `created_by_store_id` and no confidence field
  - The store, dark a moment earlier, answered 200 and appeared on the product's seller list at both prices
  - EP-48 stored a real JPEG and the URL served it as `image/jpeg`; a PDF sent as `front.jpg` with `Content-Type: image/jpeg` was refused **`unsupported_media_type`**, which is the guessed type doing its job rather than the claimed one
  - With the provider forced to fail, EP-20 returned **503** with `queued_job_id` at the top level. EP-50 reported `queued` with a null `result_type`, and the same id under a different account returned **404**. After the provider recovered and the worker ran, EP-50 reported `completed`, `result_type: match_candidates`, the candidate, and `image_considered: false`
  - The wizard created product was indexed and came back from buyer search

---

### M5 The wizard path, frontend, 2026-08-27

**Shipped.**
- S-22 `/sell/attach`, the catalogue check and its candidate list
- S-25 `/sell/wizard`, six steps, with the live combination preview and images held in client state
- X-01 `components/system/QueuedJobPanel.tsx`, with `lib/jobs/useQueuedJob.ts` and `lib/jobs/storage.ts`
- `lib/api/attach.ts`, `lib/schemas/attach.ts`, `types/attach.ts`, `lib/attach/combinations.ts`
- `components/seller/AttributeEditor.tsx`, `CombinationPreview.tsx`, `ImagePicker.tsx`
- The M4 dashboard copy saying attaching was unavailable is replaced by a real entry point, and X-04 gained a seller entry

**Contract.**
- Contract version at time of writing: 2
- Changes made to api-contract.md: none. This side mirrors it
- Error codes handled on screen: `match_required` (422), `validation_failed` (422), `store_required` (403), `ai_unavailable` (503), `unsupported_media_type` (422), `file_too_large` (422), `image_limit_reached` (422)

**A contract version 2 gap that would have broken a screen.**

`jobSchema` in `lib/schemas/common.ts` and `JobResultType` in `types/api.ts` were both written against contract version 1, whose `result_type` union omitted `search_interpretation`. Seller catalogue search has queued jobs of that type since M3, so polling one through EP-50 would have failed at the fetch boundary with a schema error, on a payload that was entirely valid.

Nothing had caught it because M3 shipped before EP-50 existed, so no screen had ever polled a job. Both are corrected. This is exactly the drift the schemas are for, and it was found by reading the contract rather than by a screen breaking, which is the cheaper of the two ways.

**How the two match outcomes are presented, which is the point of this milestone.**

**No candidates is a success.** The screen does not render an empty state, an apology, or a retry. It routes straight to the wizard, because "the catalogue does not have this" is precisely the condition the wizard exists for. Anything that styled it as a miss would make every genuinely new product feel like a failure to list.

**Candidates mean the record already exists**, and the seller joins it rather than writing a second one. Joining is the confirmation flow at M6, so S-22 shows the candidates and says plainly that the step is being built. It calls no endpoint that does not exist, invents no confirmation questions, and creates nothing.

There is deliberately **no "none of these is mine" control**, and its absence is the design rather than an omission. A seller may not overrule the match result to declare their product new. Instead the screen suggests refining the name, since a closer name finds a closer match or none at all, and none is what opens the wizard.

`match_score` is rendered as "94% match to what you typed". It is search relevance and is labelled as such. It is **not** the confidence score, which never leaves the server and appears in no type definition on this side.

**Deviations from the plan.**
- **`localStorage` is read through `useSyncExternalStore`, not in an effect.** The first version restored the job id and the draft with a mount effect, which this repository's React Compiler lint rules reject outright, and correctly: it cascades a render, and the lazy initial value alternative disagrees with the server HTML. Storage is now a subscribable store. That turned out better than what it replaced, because resuming needs no mount effect at all and a second tab finishing the same job now stops this one polling.
- **A terminal job does not clear its own stored id.** The build plan says the id persists so closing the browser does not lose the result. Clearing on completion would have meant the answer existed only for whoever was still watching, so the flow clears it once the seller has acted, or the panel's dismiss does.
- **The draft is persisted, not only the job id.** The plan requires the seller's input to stay on screen. Restoring a job without the words that produced it would hand a returning seller an empty form and ask them to remember.
- **Images upload sequentially rather than in parallel.** The API assigns position as one past the highest, so eight concurrent uploads would race for the same position and land in arbitrary order. One at a time keeps the gallery in the order the seller chose.
- **A retry is offered only for images that failed in transit.** A file the browser already judged too large or the wrong format will be refused again for the same reason, so retrying it would only repeat the refusal.
- **An expired session is presented as "start the check again", not as an error.** The API answers `match_required`, and the reason is worth stating: the catalogue may have gained this very product while the session sat open.
- **Removing an attribute row on step 3 is allowed; removing a generated combination is not.** These are different things and only one is forbidden. Step 3 is a form still being filled in and nothing has been generated. Step 4 has no remove control of any kind.
- **One currency, LKR, fixed for the whole submission.** A currency selector is not in any M5 screen specification and the seeded catalogue is single currency. Prices are typed in rupees and converted to minor units by `parseMoneyToMinor`, so no float is ever sent.

**Known gaps handed to the other side.**
- **Nothing blocking.**
- X-04 still links to `/listings`, `/proposals`, and `/analytics`, which do not exist. Those are M6, M7, and M10. They were already there before this milestone and were left alone rather than quietly changing another milestone's navigation, but they are dead links today and worth a decision.
- S-22 renders candidates and stops. When EP-21 and EP-22 land, the "Joining this record is not open yet" panel is what should be replaced, rather than left to age the way the M4 dashboard copy did.
- The wizard lets a seller edit the product name on step 1 after matching ran against the original. EP-24 does not re-run matching, so a substantially changed name could describe a product that does exist. The screen says so in a note. If that turns out to matter, the fix belongs on the backend as a re-check at submit, not as a client side guess.
- `WizardOutcome` links to the product page and back to the dashboard, and states plainly that managing prices from one place is still being built. It does **not** link to a listings screen, because there is none.
- The `attach` limiter is 20 per hour per user and is easy to reach while testing the flow by hand.

**Verified by.**
- `npm run docs:check`, `npx tsc --noEmit`, `npm run lint`, and `npm run build` all clean
- Against the live API through the authenticated proxy, with the queue worker running:
  - A user with no store got **403 `store_required`** from the attach route
  - Matching a seeded product name returned one candidate at score 1.0 with its image URL; attempting the wizard with it outstanding returned **422 `match_required`**
  - Matching a genuinely new name returned **200 with `candidates: []`**, and the wizard then opened a session with six questions and an expiry 24 hours out
  - A submission with one blank answer returned **422 `validation_failed` with the error keyed `answers.q3`**, which is what puts the seller back on that question rather than on a generic form error
  - A complete submission with two attributes of two and three options, two combinations carried, returned **201 with `variants_generated: 6`, `attachments_created: 2`, `store_is_live: true`**
  - An image uploaded against the returned slug answered 201; a PDF sent as `front.jpg` with `Content-Type: image/jpeg` was refused **`unsupported_media_type`**, so the API is judging the bytes rather than the claim
  - With the provider forced to fail and the queue worker stopped, EP-23 returned **503** with `queued_job_id` at the top level, and EP-50 reported **`status: queued` with `result_type` and `result` both null**. After the provider recovered and the worker ran, EP-50 returned `completed`, `result_type: wizard_questions`, and the session the wizard resumes from
  - The same job id read by a different signed in account returned **404**, not 403
  - The wizard created product is publicly readable with no token, shows **all six** combinations with four at `seller_count: 0`, carries no forbidden field, and renders at `/products/{slug}`

---

### M6 The confirmation and proposal path, backend, 2026-08-27

**Shipped.**
- EP-19 `GET /api/stores/mine/listings`, EP-21 `POST /api/attach/confirm/start`, EP-22 `POST /api/attach/confirm/submit`
- `ConfirmationService`, `RecordComparison`, `ConfirmationOutcome`, `StoreListingsQuery` with a `StoreListing` value object
- `Proposal` and `ProposalReviewer` models, the `proposal_reviewers` table, and `attach_sessions.ai_job_id`
- `AiProvider` grew `generateConfirmationQuestions` and `scoreConfirmationAnswers`, implemented in both adapters
- `ProposalNeedsReview`, a queued mail notification, and the `CompleteConfirmation` job for the provider failure path

**Contract.**
- Contract version at time of writing: **bumped from 2 to 3**
- Changes made to api-contract.md: `confirmation_outcome` added to `result_type` in section 8. **Section 11.4 is unchanged** and is what EP-22 returns
- Error codes now live from this milestone: `confirmation_incomplete`, and `proposal_pending` and `already_attached` are now reachable for the first time

**Response shapes the frontend should code against.**

EP-21 returns:

```json
{ "data": { "session_id": "…", "product_id": 12, "questions": [{ "id": "q1", "attribute": "inputs", "text": "…" }], "expires_at": "…" } }
```

- A question's `attribute` names the field the answer is compared against: a core field like `name`, a specification key like `inputs`, or a variant attribute like `Colour`
- **The record's current value is deliberately not sent.** It is stored on the session so the comparison works, but showing the seller the answer we expect would turn confirmation into a yes or no exercise, and the value of the flow is that they describe their own unit unled. There is a test asserting `current_value` appears in no response
- Sessions last **24 hours**. An expired one returns 422 `validation_failed` keyed on `session_id`, and the seller restarts at EP-21, not at matching: the product is known and still exists, only the questions are stale

EP-22 returns 201 for **both** outcomes, per section 11.4, distinguished by `outcome` and never by the status code:

```json
{ "data": { "outcome": "attached", "attachment_ids": [901] } }
{ "data": { "outcome": "proposal_created", "proposal_id": 77, "review_closes_at": "…" } }
```

- The two carry **different keys on purpose**. A client that forgets to branch on `outcome` should fail loudly rather than render an attached state for a blocked seller
- A proposal is **not a failure to attach**. It is the platform doing what it exists to do, and nothing in the interface should style it as an error

EP-19 returns two lists in one call:

```json
{
  "data": {
    "listings": [
      {
        "product": { "id": 7, "slug": "…", "name": "…", "primary_image_url": null },
        "variants": [{ "attachment_id": 901, "variant_id": 55, "attribute_values": {}, "price_minor": 450000, "currency": "LKR", "is_available": true }]
      }
    ],
    "blocked": [
      { "proposal_id": 77, "status": "pending", "review_opens_at": "…", "review_closes_at": "…", "changed_fields": ["inputs"], "product": { "id": 12, "slug": "…", "name": "…" } }
    ]
  }
}
```

- **`blocked` is the half that matters and the reason this is one call.** A product with a proposal under review has no attachment row at all, so a screen built from `listings` alone shows nothing and leaves the seller wondering where their submission went
- `status` is `pending` or `escalated`. Escalated means the window closed without enough votes and an administrator is deciding, so the seller is still blocked and still owed an answer
- `changed_fields` names what is under review, so the screen can say what is being argued about rather than only that something is
- Not paginated. It returns an object with two arrays rather than a list, so `Paginated<T>` does not apply and no second list shape is being introduced

**Deviations from the plan.**
- **`proposal_reviewers` is a new table, not in the schema design.** The schema says eligibility is "evaluated in application logic against attachment state when the proposal opened", which describes the rule correctly but cannot be implemented from the attachments table alone. Attachments change during the window: a store attaching on day two would look eligible to any query run on day three, and a store that detaches would look ineligible even though its vote must stand. Neither is recoverable once the moment has passed, so the set is written down at opening and never recalculated. A table rather than a JSONB array because M7's `to-review` listing needs to find a store's pending reviews by index.
- **The proposing store is excluded from its own reviewer set.** A seller voting on their own proposal decides their own case, and where they are the only other attached store the vote would be unanimous by construction.
- **A proposal can open with zero reviewers.** Nobody else carries the product. That is a real state rather than a bug: it reaches its closing time with no votes and escalates to an administrator, which is the defined outcome for an unreviewed proposal. No email is sent because there is nobody to send one to.
- **`already_attached` is checked at EP-21, not only at EP-22.** The endpoint responsibilities list it only under submit, but generating questions costs a provider call for a flow that can only end in refusal, and UF-15's own edge case says an already attached seller belongs in price editing instead.
- **The answer comparison is deterministic, not an AI call.** Comparing answers to the record is a system step in the specification, not a provider step, and putting the branch behind a second provider call would mean an identical submission could attach today and open a proposal tomorrow. Its limit, stated plainly: normalising case and spacing means `"  192   KHZ "` matches `"192 kHz"`, but `"two"` does not match `"2"` and would open a proposal. That is the safe direction to be wrong in. A spurious proposal is reviewed by people who know the product and costs three days; a missed one silently corrupts a record every seller shares.
- **A queued confirmation submit completes the whole submission, not only the scoring.** Scoring alone would leave the write to some later request that might never come, and the seller would be told their submission was saved while nothing had been decided. A second submit while one is outstanding returns **the same job id**, so two jobs cannot race to create a duplicate proposal.
- **EP-21's provider failure queues a job but no worker.** Regenerating questions is cheap to re-request and the seller has lost nothing but a moment. What must not be lost is a submission, which is why only that path carries a worker.
- The confidence band threshold is **0.7**, in `config/ai.php`. The raw score is stored alongside the band so it can be retuned later without the past meaning something different than it did.

**An M5 test that had been passing by luck.**

`WizardTest` asserted on `Product::pluck('slug')` with no `ORDER BY`, so it was asserting on PostgreSQL's physical row order. M6 changed the churn on that table and the order flipped. The assertion was always about the slugs rather than their heap position, and it now orders explicitly. Not an M6 regression, but worth recording: an unordered `pluck` in an assertion is a test that will eventually fail for a reason unrelated to what it is testing.

**Known gaps handed to the other side.**
- **Nothing blocking. S-24 and the listings dashboard are both unblocked.**
- **Voting does not exist yet.** EP-27 to EP-30 are M7, so a reviewer who receives the email has nowhere to go. The email says voting closes in three days and describes what is being changed, but there is no screen behind it. That is the largest visible gap in the platform right now.
- **Nothing resolves a proposal.** Every proposal created today stays `pending` until M7 ships the resolution matrix and the scheduled window sweep. `review_closes_at` passing has no effect on its own.
- The reviewer email renders through the `log` mail driver locally, so it lands in `storage/logs/laravel.log` rather than an inbox.
- EP-21 refuses with `already_attached` as well as `proposal_pending`. Handle both on S-22 and S-24.
- A confirmation session's questions are keyed `q1`, `q2`, and so on, and the ids are only meaningful within that session. Submit the answers keyed by the ids the session returned.

**Verified by.**
- 27 tests in `tests/Feature/Api/ConfirmationTest.php` over HTTP, and 15 in `tests/Feature/Api/ConfirmationServiceTest.php` at the service level, split so a failure points at the decision rather than at the wiring around it
- The build plan's stated M6 list, item by item: every question answered or `confirmation_incomplete`; no attachment row while a proposal is pending; a second attempt refused with `proposal_pending`; the confidence score written to the proposal and asserted absent from EP-21, EP-22 and EP-19 responses; the review window closing exactly three days after opening; and the attached store set recorded at opening with a store attaching mid window proven not to join it
- Two invariants moved from todo to asserted: no seller route writes to a product, attribute, or variant, proven by walking the registered routes rather than by guessing at paths; and no attachment row exists while a proposal is pending, with the product's own values proven unchanged
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 268 tests with 263 passed and 5 todo

---

### M6 The confirmation and proposal path, frontend, 2026-08-27

**Shipped.**
- S-23 match selection, wired into confirmation from the existing `/sell/attach` screen
- S-24 `/sell/confirm`, the mandatory confirmation flow and both of its outcomes
- S-21 `/listings`, built from both halves of EP-19
- X-05 `components/proposal/PendingProposalNotice.tsx`, on the dashboard, the listings screen, and the match screen
- `lib/api/confirmation.ts`, `lib/schemas/confirmation.ts`, `types/confirmation.ts`, `lib/api/parse.ts`
- The M5 dashboard copy saying listings were still being built is replaced by real EP-19 data

**Contract.**
- Contract version at time of writing: 3
- Changes made to api-contract.md: none. This side mirrors it
- Error codes handled on screen: `confirmation_incomplete` (422), `proposal_pending` (409), `already_attached` (409), `validation_failed` (422, including the expired session case keyed on `session_id`), `ai_unavailable` (503)
- `confirmation_outcome` added to the job `result_type` union in `lib/schemas/common.ts` and `types/api.ts`, per contract version 3

**S-26 and S-27 were not built, and this is the important entry.**

The frontend build plan lists both under M6. Neither can be built, because their data does not exist: S-26 needs `GET /api/proposals/mine` (EP-27) and S-27 needs `GET /api/proposals/{id}` (EP-29), and both are **M7**.

Building either would have meant inventing a response shape the backend has not defined, which section 8 of the integration protocol forbids outright, and section 6 answers directly: a screen whose endpoint does not answer does not get built yet. They are raised as an open request instead.

The knock on effect is worth stating, because it changes a specified behaviour. The screen inventory says X-05 carries a link to S-27. That link would go nowhere, so **X-05 renders the proposal detail inline instead**: status, both dates, and which fields are under review, all of which EP-19's `blocked` entry already carries. A seller sees everything the notice was specified to tell them, without a dead link.

**How the two outcomes are presented, which is the point of this milestone.**

EP-22 answers **201 for both**, so the status code says nothing and `outcome` says everything. That is enforced rather than trusted: `ConfirmationOutcome` is a **discriminated union** in both the type and the zod schema, and the two variants share no keys. A component that forgot to branch would fail to compile rather than read `attachment_ids` off a proposal payload, get `undefined`, and tell a blocked seller they were live.

`proposal_created` is **not styled as a failure anywhere**. The heading reads "Your answers are with the other sellers", the panel explains that nobody edits a record directly and that this is how the catalogue stays accurate, and it says plainly that being unable to list yet is not a penalty. Blue, not red. A seller who answered honestly and hit a difference has done the thing the platform exists to capture.

**Deviations from the plan.**
- **S-21 is read only.** Price editing, the availability toggle, and detach are EP-25 and EP-26 at M8. The screen says so once at the bottom rather than rendering disabled controls on every row: a greyed out price field would imply this seller is not allowed to edit, when in fact nobody can yet.
- **X-05 shows a countdown as well as the closing date**, from `timeRemaining`. "Closes 30 Aug" is less useful than "closes 30 Aug, about 3 days from now" to someone deciding whether to wait.
- **The blocked section renders above the listings**, on both the dashboard and S-21. A seller who submitted something and cannot find it is the person most likely to be on that screen, so it answers their question first.
- **`already_attached` and `proposal_pending` are handled on the match screen as well as the confirmation screen.** EP-21 is called when a candidate is chosen, so both refusals land before a navigation. `already_attached` redirects to the listings; `proposal_pending` fills X-05 from EP-19, which is what actually knows the dates and the fields under review.
- **The chosen candidate is persisted to `localStorage`**, alongside the draft and the job ids from M5. A reload mid confirmation still knows which product is being confirmed, and the product name renders without a second fetch.
- **The queued job for confirmation gets its own storage key.** A seller can plausibly have a match queued and a confirmation queued at once, and resuming the wrong one would drop them back into a flow they had already left.
- **`getProductVariants` fetches EP-10 through the proxy from the browser**, unlike the other catalogue helpers which fetch server side for the static pages. The confirmation screen is a client component and needs the versions at interaction time. Safe rather than merely convenient: a public route is defined not to change behaviour when a token happens to be present, so the extra hop costs a request and changes nothing about the answer.
- **A new shared `lib/api/parse.ts`.** The M4 and M5 modules keep their own local copies of the same helper; refactoring shipped code for a cosmetic gain was not part of this milestone.

**Known gaps handed to the other side.**
- **Nothing blocking on the backend.**
- **Reviewers still cannot vote, and this is now the most visible gap in the platform.** A seller who receives the reviewer email has nowhere to go: S-28 and S-29 need EP-28 and EP-30, both M7. Nothing in this milestone's interface implies a vote screen exists, and no vote UI was invented.
- **Nothing resolves a proposal.** Every proposal stays `pending` until M7 ships the resolution matrix and the window sweep, so a seller blocked today stays blocked. X-05 gives a closing date that nothing currently acts on when it passes.
- S-26 and S-27 are outstanding, per the note above. The S-24 outcome panel and X-05 both say a page showing the review's progress is still being built, rather than linking anywhere.
- X-04 still links to `/proposals` and `/analytics`, which do not exist. `/listings` is live as of this milestone. The other two are M7 and M10 and remain dead links.
- The record is not changed by a pending proposal, and the interface says so. A seller looking at the public product page during their own review sees the old value, which is correct and worth not treating as a bug report.

**Verified by.**
- `npm run docs:check`, `npx tsc --noEmit`, `npm run lint`, and `npm run build` all clean
- Against the live API through the authenticated proxy, with the queue worker running:
  - Matching a seeded product returned one candidate, and EP-21 opened a session with **8 questions covering every field**, carrying **no `current_value` and no confidence field**
  - A blank answer returned **422 `confirmation_incomplete`**
  - Answers matching the record returned **201 `{"outcome":"attached","attachment_ids":[23]}`**; the product appeared in `listings` and the session reported `is_live: true`
  - A second start for that seller returned **409 `already_attached`**
  - From a second seller, one differing answer returned **201 `{"outcome":"proposal_created","proposal_id":2,"review_closes_at":"2026-08-30T12:15:59+00:00"}`**, three days out
  - That seller's EP-19 showed **`listings=0, blocked=1`** with the status, the closing date, and `changed_fields: ["Battery"]`, and their store stayed dark
  - A second start while pending returned **409 `proposal_pending`**
  - No `confidence_score`, `confidence_band`, `current_value`, or `created_by_store_id` in any M6 payload or in the rendered HTML
  - The public record still reads `Battery: 4500 mAh` while the proposal says `5200 mAh`, so a pending proposal changes nothing
  - `/listings`, `/sell/confirm`, and `/sell/attach` all render 200 for a signed in seller and 307 to `/login?next=…` anonymously

---

### M7 Peer review and resolution, backend, 2026-08-27

**Shipped.**
- EP-27 `GET /api/proposals/mine`, EP-28 `GET /api/proposals/to-review`, EP-29 `GET /api/proposals/{id}`, EP-30 `POST /api/proposals/{id}/vote`
- `ResolutionMatrix`, `ResolutionOutcome`, and `ProposalResolutionService`, which is the only thing in the codebase that resolves a proposal
- `SweepReviewWindows` (`proposals:sweep`), scheduled hourly with `withoutOverlapping`
- `ProposalsQuery`, `ProposalSummaryResource`, `ProposalDetailResource`, `VoteOnProposalRequest`, `ProposalController`
- The `ProposalVote` model and the `proposal_votes` table put to use for the first time

**Contract.**
- Contract version at time of writing: **bumped from 3 to 4**
- Changes made to api-contract.md: added **section 11.8**, the proposal list item, the detail with its change comparison, and the vote request body. No existing shape changed
- Error codes now live from this milestone: `already_voted`, `review_closed`, `not_eligible_to_vote`. All three were registered in section 7 since version 1 and are reachable for the first time now

**The matrix, and where it lives.**

One implementation, in `ResolutionMatrix::decide()`, called from exactly one place: `ProposalResolutionService::resolveIfReady()`. Both the vote endpoint and the sweep reach it through that one method, so a proposal completed by voting and one that expired unvoted cannot be decided differently.

| Confidence | Peers | Outcome |
|---|---|---|
| High | In favour | Approved |
| High | Against | **Escalated**, not rejected |
| Low | In favour | Approved |
| Low | Against | Rejected |

Two rows the table does not have, both escalating: a **tie** (`tie_no_majority`) and **nobody voting at all** (`no_votes_cast`). Neither is a majority, and defaulting either way would mean picking a side the reviewers deliberately did not pick.

**Non voters are excluded from the denominator.** Two in favour and one against out of five eligible reviewers is a majority in favour, not two out of five. Silence is the absence of a position rather than opposition, which is also why there is no third vote value for abstaining: a reviewer with no view simply does not vote, and no row is written.

**Resolution happens early once every eligible reviewer has voted**, rather than always waiting out the three days. Once the answer cannot change, holding the proposing seller blocked serves nobody. A proposal with **zero** eligible reviewers is the exception and never resolves early: there is nobody who could complete it, so it waits for the sweep and escalates.

**Deviations from the plan.**
- **EP-29 answers 404 to an outsider, but EP-30 answers 403.** The asymmetry is deliberate. The contract registers `not_eligible_to_vote` as a 403 for a store that was not attached when the proposal opened, and answering 404 on the vote would make that code unreachable and tell the caller nothing about why they were refused. A 403 there reveals only that some proposal holds that id, where EP-29 would have handed over the product and the whole comparison. There is a test for each half.
- **The vote guards live in `ProposalResolutionService::castVote()`, not in the controller.** Eligibility, the closed window, and double voting are decisions about proposals rather than about HTTP, and a future caller reaching `recordVote` directly would otherwise bypass all three.
- **`castVote` catches the unique constraint violation on `(proposal_id, store_id)` and reports it as `already_voted`.** Two requests from one store arriving together can both pass the check before either inserts. The index is what actually enforces one vote per store; this turns its error into the refusal the caller would have got a moment earlier.
- **EP-28 keeps proposals this store has already voted on**, marked `has_voted`, rather than filtering them out. A reviewer who voted yesterday and comes back to check should find the proposal where they left it rather than conclude it vanished. It does drop proposals whose window has closed, because those cannot take another vote.
- **EP-27 returns every status, not only the blocking ones.** A seller wants to know their submission was approved as much as they want to know what is still outstanding.
- **The proposing store needs no rule of its own to be barred from voting.** It was excluded from its own reviewer set at M6, so it falls out at the eligibility check. Its own proposal is readable at EP-29 with `is_mine: true` and `can_vote: false`.
- **`ProposalDetailResource` omits the proposing store's identity.** The vote is about whether the record is right, not about who said so, and naming the seller invites voting on the competitor rather than on the claim. Nothing in the contract asked for it either way.
- **Two Pint violations in the M7 code committed earlier this day were fixed** (`ResolutionTest.php`, `ProposalVote.php`). They predate this session's endpoint work and were failing `composer test` before any of it.
- **`Proposal`'s `@property` block was incomplete**, which surfaced as PHPStan errors the moment `resolved_at` was serialised. The missing columns are now declared rather than the errors suppressed.

**Known gaps handed to the other side.**
- **Nothing blocking. S-26, S-27, S-28, and S-29 are all unblocked.**
- **No administrator screen resolves an escalated proposal.** EP-41 and EP-42 are M11, so a proposal that ties, that nobody votes on, or that is high confidence with peers against reaches `escalated` and **stops there, with the seller still blocked**. This is now the platform's longest dead end: M6's gap was that nothing resolved a proposal, and M7's is that one of the four outcomes still has nobody to act on it.
- **`resolution_reason` is stored and deliberately not serialised.** It records why the matrix decided as it did, including which confidence band applied, so exposing it would leak the band by another name. The frontend gets `status` and nothing more.
- Votes are **immutable**. No endpoint changes one, and a reviewer who changes their mind has no recourse. A vote that can be revised turns a three day window into a negotiation.
- The reviewer email still renders through the `log` mail driver locally, so it lands in `storage/logs/laravel.log`.
- **The sweep depends on the scheduler actually running.** `php artisan schedule:work` locally, cron in deployment. A missed run leaves a proposing seller unable to trade and nothing else in the platform notices, which is why the build plan puts it under monitoring at M12.

**Verified by.**
- 23 tests in `tests/Feature/Api/PeerReviewTest.php` over HTTP, and 18 in `tests/Feature/Api/ResolutionTest.php` at the decision level, split so a failure points at the rule rather than at the wiring around it
- The build plan's stated M7 list, item by item: each of the four matrix rows, a tie escalating, non voters excluded from the denominator, a sole reviewer's single vote being a majority, approval creating a version and the proposing seller's attachment, rejection creating neither, a store not attached at opening unable to vote, a store that detached mid window keeping its vote, voting twice refused, voting after close refused, and two simultaneous votes resolving exactly once with **one** version written
- The confidence score and band asserted absent from all four endpoints' raw response bodies, read as text rather than by key so a nested or renamed occurrence is caught too. `resolution_reason` asserted absent for the same reason
- Against the live API, with the seeded catalogue and the M6 proposals still pending: a store that attached to the product **after** the proposal opened got an empty EP-28, **404** from EP-29, and **403 `not_eligible_to_vote`** from EP-30, while a frozen reviewer saw both proposals in EP-28, read the comparison `Battery: 4500 mAh -> 5200 mAh` at EP-29 with `can_vote: true`, voted approve for `{"vote_recorded":true,"proposal_status":"pending"}`, and was refused **409 `already_voted`** on the second attempt. `proposals:sweep` ran clean with no window closed. No `confidence_score`, `confidence_band`, or `resolution_reason` in any live payload
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 309 tests with 304 passed and 5 todo

---

### M7 Peer review, frontend, 2026-08-27

**Shipped.**
- S-26 `/proposals`, the seller's own proposals, from EP-27
- S-28 `/proposals/to-review`, the review queue, from EP-28
- S-27 and S-29 `/proposals/[id]`, the change comparison and the vote, from EP-29 and EP-30
- `components/proposal/ChangeComparison.tsx`, `ProposalRow.tsx`, `ProposalStatusBadge.tsx`
- `lib/api/proposals.ts`, `lib/schemas/proposal.ts`, and a rewritten `types/proposal.ts`
- The S-24 outcome panel and X-05 now link to a real review page instead of saying one is still being built
- X-04's `/proposals` entry, a dead link since M0, is live

**Contract.**
- Contract version at time of writing: 4
- Changes made to api-contract.md: none. This side mirrors it
- Error codes handled on screen: `not_eligible_to_vote` (403), `already_voted` (409), `review_closed` (409), and 404 from EP-29
- Section 11.8 is mirrored field for field. Section 11.6 drives what the screen shows after a vote

**S-27 and S-29 are one route, and that is the significant design call.**

The build plan lists them as two screens, and they are two experiences: one seller waiting on an answer, another being asked to give it. But they are **one resource**. EP-29 serves both from the same id and answers `is_mine` to say which the caller is.

Two routes would have meant two URLs for one proposal, and a reviewer following a link a proposer sent them would land somewhere that 404s. So `/proposals/[id]` renders the comparison for both audiences and shows the vote panel only to a reviewer who may still vote. `is_mine` picks the wording; `can_vote` picks whether the buttons exist at all.

**What the vote screen deliberately does not have.**

- **No per field control.** The comparison is a table that renders and does not interact. A checkbox beside each row would turn an all or nothing decision into a partial one, which is what invariant 4 exists to stop.
- **No third button.** Approve and reject, and nothing for abstaining. A reviewer with no view simply leaves, and the backend excludes non voters from the denominator rather than counting them as against. A button would turn that silence into a recorded position.
- **No confidence score, for either audience.** Not rendered, not in the types, and `assertNoConfidence` reads the raw payload of all four endpoints as text and throws if one appears. zod ignores keys it was not told about, so without that check a backend regression would validate silently and sit in memory waiting for somebody to render it. `resolution_reason` is refused alongside the two obvious names, because values like `high_confidence_peers_against` leak the band under a different name.
- **No resolve control on an escalated proposal.** EP-41 and EP-42 are M11. The screen says an administrator is deciding and that there is no deadline on that step.

**Deviations from the plan.**
- **`types/proposal.ts` was rewritten rather than extended.** The M0 file guessed at `proposing_store`, `vote_summary`, `comments`, `my_vote`, `escalation_reason`, and `current_values`, none of which any endpoint returns. It was imported nowhere, so it was a second and wrong definition of shapes the contract now defines properly.
- **`ProposalStatus` and `proposalStatusSchema` are re-exported from the M6 confirmation modules, not redeclared.** Two copies of one union drift the moment a status is added to one of them.
- **The detail resource does not name the proposing store, and the screen does not ask for it.** The backend omits it deliberately, and the screens are worded to match: a reviewer decides whether the catalogue is right, not who asked.
- **The vote count is shown as cast out of eligible, never as for against.** A running tally would let a late reviewer vote with the crowd rather than on the product.
- **An escalated proposal is never described as decided**, even though it carries `resolved_at`. The window closing is what set that timestamp; the proposal is still unresolved and the seller is still blocked. Both the row and the detail say "went to an administrator" and show the date, rather than "decided". This was caught during verification rather than by design, and it would have told a blocked seller their wait was over.
- **X-05 keeps its inline detail and gains a link.** A seller seeing the notice on their dashboard should not have to navigate to learn what is under review and when it closes. The link is for the vote count and the outcome, which are the parts that change.
- **`/proposals` and `/proposals/to-review` read the page number from the URL** rather than holding it in component state, so a paginated view is shareable and survives a reload. Both need a Suspense boundary above `useSearchParams`, otherwise the whole route opts out of static rendering.

**Known gaps handed to the other side.**
- **Nothing blocking on the backend.**
- **An escalated proposal is a dead end for the seller, and the interface now says so plainly.** It reads as blocked, awaiting an administrator, with no deadline. That is honest but it is not an answer, and until EP-41 and EP-42 land at M11 there is no screen anywhere that can give one. This is the platform's longest standing unresolved state.
- **A rejected proposal tells the seller they may try again, and nothing enforces or assists that.** They go back through `/sell/attach` and answer the questions afresh. There is no shortcut from the rejected proposal into a new confirmation, because no endpoint offers one.
- **No reviewer sees another reviewer's comment.** Comments are collected and, per the backend, read by an administrator on escalation. Nothing in this milestone displays them, and nothing should: a reviewer reading the others' reasoning before voting is the anchoring problem the confidence score was hidden to avoid.
- X-04 still links to `/analytics`, which is M10 and remains a dead link. `/proposals` is live as of this milestone.

**Verified by.**
- `npm run docs:check`, `npx tsc --noEmit`, `npm run lint`, and `npm run build` all clean
- Against the live API through the authenticated proxy:
  - A frozen reviewer's EP-28 returned **two proposals**, one with `votes_cast: 1` of 8, both with `has_voted: false`
  - EP-29 on one of them returned the comparison `Battery: 4500 mAh -> 5200 mAh` with `can_vote: true`, `is_mine: false`, and **no confidence field**
  - A reject vote returned **`{"vote_recorded":true,"proposal_status":"pending","resolved_at":null}`**, and a second vote from the same store returned **409 `already_voted`**
  - The proposing store's EP-27 showed its own proposal, and EP-29 returned **`is_mine: true`, `can_vote: false`**, including the zero reviewer case the screen has its own wording for
  - A store that attached to the product **after** the proposal opened got **404** from EP-29 and **403 `not_eligible_to_vote`** from EP-30
  - Expiring a window and running `proposals:sweep` escalated a proposal with `no_votes_cast`. It then reported **`can_vote: false`**, was refused with **409 `review_closed`**, **dropped out of the review queue**, and appeared to its proposer as escalated
  - No `confidence_score`, `confidence_band`, or `resolution_reason` in any authenticated M7 payload, and no occurrence of "confidence" in the rendered HTML of any of the three screens
- `/proposals`, `/proposals/to-review`, and `/proposals/{id}` all render 200 for a signed in seller and 307 to `/login?next=…` anonymously

---

### M8 Listing management and alerts, backend, 2026-08-27

**Shipped.**
- EP-25 `PATCH /api/attachments/{id}`, EP-26 `DELETE /api/attachments/{id}`
- EP-36 `GET /api/wishlist`, EP-37 `POST /api/wishlist`, EP-38 `DELETE /api/wishlist/{id}`
- `ListingService` and `WishlistService`, `ListingController` and `WishlistController`
- `NotifyPriceDrop` and `NotifyNearbyAvailability`, both queued, with the `PriceDropped` and `NearbyAvailability` mail notifications
- `WishlistItem` model over the `wishlist_items` table, which had existed unused since M0
- `config/alerts.php`, holding the nearby radius

**Contract.**
- Contract version at time of writing: **bumped from 4 to 5**
- Changes made to api-contract.md: added **section 11.9**, the attachment update request and response, the detach response carrying `store_is_live`, the wishlist item, and the wishlist add request. No existing shape changed
- Error codes now live from this milestone: **none**. `validation_failed`, `forbidden`, and `not_found` already covered everything these endpoints refuse

**No migration was needed, and that is worth recording.**

`wishlist_items` was created at M0 with `unique(user_id, variant_id)` and a `last_notified_price_minor` column, written before any endpoint existed. Both turned out to be exactly right: the unique index is what makes a repeated save safe to answer with the existing row, and `last_notified_price_minor` is the entire repeat suppression mechanism. M8 added a model over the table and nothing else.

**The two alerts, and the rules that decide them.**

**Price drop.** Dispatched from `ListingService::update` only when the new price is **strictly lower** than the previous one, which is read inside the same transaction and under a row lock so two concurrent updates cannot both decide they were the drop. Dispatched `afterCommit`, because an email is the one side effect in this platform that cannot be withdrawn and a rolled back price must not have announced a discount.

The suppression rule lives in the job: a buyer already told about this price **or a lower one** hears nothing. Without it a seller moving a price up and down around a threshold sends an email on every downswing, and the buyer learns to ignore all of them. The notified price is stamped **after** sending, not before: sending twice because a job retried is a nuisance, but stamping a price the buyer was never told about would silence every future alert down to that figure, which is a fault they could never diagnose.

Two further refusals in the job, both about not sending a useless email: an unavailable listing is not an offer, and a price that moved again before the job ran is not the price that was queued.

**Nearby availability.** Hung off `Attachment::created` rather than off any controller, so **every** path that creates a listing is covered: confirmation, the wizard, and an approved proposal releasing a withheld listing. A future path is covered without anyone remembering to add it.

Distance is decided in PostGIS with `ST_DWithin` against the buyer's own coordinates, matching how the seller list already works, so it can use the spatial index rather than measuring every wishlist row in PHP. The radius is `config/alerts.php`, defaulting to 25 km, because the right figure is a question about geography rather than about code.

**A buyer who never shared a location receives this alert for nothing.** That is the documented cost of declining the location prompt, not a fault. It deliberately does not fall back to notifying everybody, which would turn a useful alert into a marketing email.

**Deviations from the plan.**
- **The live flag needed no new work.** `Attachment::booted` has hooked `created` and `deleted` to `recomputeLiveFlag()` since M4, so invariant 12 was already enforced structurally. What M8 added is the test coverage the build plan asked for, and one rule in `ListingService::detach`: the row is deleted **through the model**, never by a bulk query, because a bulk delete skips model events and would leave a store selling to buyers with nothing on its shelves.
- **EP-26 answers `store_is_live` rather than 204.** A seller removing their last listing has just made their store invisible, and that is the one thing they need to be told at that moment. A bare 204 would make the interface fetch the store again to find out.
- **A repeat wishlist save answers 200 with the existing item rather than 409.** A buyer pressing save twice expressed the same intent twice. Recorded in the contract so the client does not code an error path for it.
- **`lowest_price_minor` is null when nobody carries the variant**, and unavailable listings are excluded from it. A null there is a normal state rather than missing data: saving a combination no seller stocks is exactly what the nearby alert exists for.
- **`last_notified_price_minor` is not serialised on EP-36.** It is bookkeeping for the alert job, and showing a buyer the price they were last told about invites them to read it as a price history, which it is not.
- **EP-25 and EP-26 sit behind the `writes` limiter, not `attach`.** Neither costs a provider call, and a seller repricing a shelf of stock should not burn an attach quota that exists to protect the AI budget.
- **Ownership is checked in the service, not the controller**, and answers **404 rather than 403**. Confirming that attachment 901 exists but belongs to somebody else tells a competitor something about their inventory.

**Known gaps handed to the other side.**
- **Nothing blocking. S-21's editing controls, S-14, and X-06 are all unblocked.**
- **The alerts are invisible in the interface, by design.** Invariant 10 holds: email only, no bell, no notification centre. There is no endpoint to read past alerts from and none should be added. A buyer who does not read the email does not find out.
- **Locally the mail driver is `log`**, so both alerts land in `storage/logs/laravel.log` rather than an inbox. The queue worker must be running or nothing sends at all.
- **Nothing recomputes `lowest_price_minor` for a cached catalogue response.** Catalogue caching is M12, so this is only a note for when it lands: a price change invalidates a product's cached seller list.
- **A detached seller keeps nothing.** There is no undo, no soft delete on attachments, and re-listing means going back through confirmation. That is intended, and worth the interface warning about before the last listing goes.
- **Escalated proposals are still a dead end until M11**, unchanged by this milestone.

**Verified by.**
- 36 tests in `tests/Feature/Api/ListingManagementTest.php`
- The build plan's stated M8 list, item by item: a price decrease queuing alerts and an increase not doing so, a price set to what it already was queuing nothing, repeat alerts suppressed by the last notified price and still firing when the price falls below it, the live flag recomputed on both creation and deletion, zero, negative, and decimal prices rejected, an empty update rejected rather than reported as success, and the product still answering at its own URL with `seller_count: 0` after its last seller leaves
- Invariant 1 asserted directly: a PATCH carrying `name`, `category`, and `attribute_values` alongside a price changes the price and leaves the product untouched
- Ownership refusals on both endpoints, wishlist isolation between buyers, and one wishlist per user rather than per role
- The nearby alert asserted at three distances: a buyer 2 km away is told, one 300 km away is not, and one with no coordinates is not
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 345 tests with 339 passed and 5 todo

---

### M8 Listings and wishlist, frontend, 2026-08-27

**Shipped.**
- S-21 completed: `components/seller/ListingRow.tsx`, an editable price, an availability toggle, and detach on `/listings`
- S-14 `/wishlist`, the buyer's saved variants
- X-06 `components/system/RequiresLogin.tsx`, wrapping the save action on the product page
- The wishlist affordance on S-04 is live and targets the **selected variant**
- `lib/api/wishlist.ts`, `lib/schemas/wishlist.ts`, `types/wishlist.ts`
- The M5 copy on the listings screen saying price editing was still being built is replaced by the real controls

**Contract.**
- Contract version at time of writing: 5
- Changes made to api-contract.md: none. This side mirrors it
- Error codes handled on screen: `validation_failed` (422, keyed on `price_minor`), and 404 for a listing that is not the caller's
- One nullability the contract does not state is handled and raised as an open request, below

**`currency` is nullable on a wishlist item, and 11.9 does not say so.**

The example in section 11.9 shows `"currency": "LKR"` beside a populated price. The API returns **null for both** when nobody carries the variant, which is the state the same section describes in prose two paragraphs later. The schema mirrors what the API actually does, because refusing null there would break the wishlist's most ordinary state, and it is raised for the backend to write down rather than left as a silent divergence.

**How the dark store warning works, which is the part worth getting right.**

A store is visible to buyers only while it holds at least one attachment, so a seller removing their last listing makes their own store invisible. That is warned about **twice, deliberately**:

- **Before**, in the detach dialog, computed from the listings already on screen. The count is over **attachments across all products**, not products, because a seller removing the second of two versions of one product is removing their last attachment just the same.
- **After**, from `store_is_live` in the EP-26 response. This is the authoritative answer and it is why the endpoint returns the flag at all. A notice appears at the top of the screen saying the store has gone dark, rather than the seller discovering it later from a dashboard that happens to reload.

A warning that only arrives after the fact is not a warning, and one that only arrives before it is a guess. Both, from different sources, is the honest arrangement.

**Deviations from the plan.**
- **Only what changed is sent to EP-25.** The row tracks price and availability separately and sends whichever the seller actually touched. Restating an untouched price would look like a price change to the alert logic behind it, and a seller marking something out of stock would send buyers an email about a price that never moved.
- **Zero and negative prices are refused before the round trip**, by the existing `parseMoneyToMinor`, which already returned null for them. The server side refusal is handled too and rendered from `errors.price_minor`, as a backstop rather than the normal path.
- **X-06 wraps the action rather than guarding the route.** The public catalogue is browsable with no account, and turning a product page into a login wall because it carries one saveable control would trade the whole public catalogue for one button. An anonymous visitor sees the same control and choosing it signs them in and brings them back.
- **The intent survives the login round trip.** The return path carries `?save=<variant>`, and the product page finishes the save once on arrival. A visitor who picked the 256GB and then signed in gets the 256GB saved, not the default.
- **A repeat save is reported as saved, never as a conflict.** EP-37 answers 200 with the existing item, so there is no error path in the interface for it and none should be added.
- **The listings screen says what a seller may not change, once, at the bottom.** Price and stock are theirs; the description, the specifications, and the versions are shared by everyone selling the record and move only through a proposal. Saying so is better than leaving the seller to infer it from an absence.
- **Removal on the wishlist is not optimistic.** It invalidates and refetches. An optimistic remove that failed would put the row back with no explanation, and the list is small enough that the round trip is not felt.

**Known gaps handed to the other side.**
- **Nothing blocking on the backend.**
- **The alerts have no screen and never will.** Invariant 10 holds: email only, no bell, no notification centre. A buyer who does not read the email does not find out, and the wishlist page says so plainly rather than implying a history exists somewhere.
- **A nearby stock alert silently does nothing for a buyer with no location.** The wishlist page links to the account screen to add one, but nothing on screen tells a buyer their saved items are only half working. Worth revisiting if it proves confusing.
- **Detach has no undo.** Re-listing means going back through the confirmation questions, and the dialog says so.
- **A price the seller changes is not reflected in a cached catalogue page** until M12 wires revalidation. Locally there is no cache, so this is a note for later rather than a current fault.
- X-04 still links to `/analytics`, which is M10 and remains a dead link. `/wishlist` and `/listings` are both live.
- Escalated proposals are still a dead end until M11, unchanged by this milestone.

**Verified by.**
- `npm run docs:check`, `npx tsc --noEmit`, `npm run lint`, and `npm run build` all clean, with `/products/[slug]` still statically generated and no Suspense or deopt warnings
- Against the live API through the authenticated proxy:
  - A seller changed a listing to **2500 and out of stock**, and both persisted on a refetch of EP-19
  - **0 and -5 were refused with 422** and `errors.price_minor` reading "A price must be greater than zero"
  - Detaching one of several listings answered **`store_is_live: true`**; detaching a store's **last** listing answered **`store_is_live: false`**, after which the store answered 404 publicly and the product still answered with `seller_count: 8`
  - A wishlist add and an immediate repeat both answered **200 with the same item id**
  - EP-36 returned both states in one list: a priced item at `235000 LKR` from 8 sellers, and one with **`lowest_price_minor: null`, `currency: null`, `seller_count: 0`**
  - Removal answered `{"removed": true}`
- `/wishlist` and `/listings` render 200 for a signed in user and **307 to `/login?next=…`** anonymously, while `/products/{slug}` stays **200 with no token**, so invariant 7 is intact

---

### M9 Community and verification, backend, 2026-08-27

**Shipped.**
- EP-31 `GET /api/products/{slug}/community/posts`, EP-57 `GET .../posts/{id}/replies`, EP-32 `POST .../posts`
- EP-33 `GET /api/products/{slug}/verification`, EP-34 `POST .../verification/start`, EP-35 `POST .../verification/submit`
- `VerificationService`, `CommunityService`, `VerificationController`, `CommunityController`
- `AiProvider` grew `verifyOwnership` and `summariseCommunity`, implemented in both adapters, with an `OwnershipAssessment` value object
- `CompleteVerification` for the queued path, `SummariseCommunity` for the summary, and the `verification:cleanup` command scheduled daily
- `VerificationAttempt` and `CommunityPost` models over tables that had existed unused since M0

**Contract.**
- Contract version at time of writing: **bumped from 5 to 6**
- Changes made to api-contract.md: added **section 11.10**, the post shape, the post creation request, and the three verification shapes. Also stated in 11.9 that a wishlist item's `currency` is null alongside `lowest_price_minor`, which **closes the M8 open request** the frontend raised
- Error codes now live from this milestone: `not_verified` and `attempts_exhausted`. Both were registered in section 7 since version 1 and are reachable for the first time now. `verification_result` was already in the `result_type` union and needed no change

**No migration was needed, and that is the second time M0 paid off.**

`verification_attempts` and `community_posts` were both created at M0 with exactly the right columns, including `photo_deleted_at`, `attempt_number`, and soft deletes on posts. The M0 migration comment even specifies the design this milestone had to implement: *"No column holds the photograph path… the path lives transiently in the queued job payload only."* That is precisely what `VerificationQueued` and `CompleteVerification` do.

**The photograph, which is the invariant that matters most here.**

Deleted the moment verification concludes, **on a pass and on a failure alike**, with `photo_deleted_at` stamped. There is exactly one method that concludes an attempt and exactly one that deletes a photograph, and both the synchronous and the queued path go through them, so the two cannot drift.

Three further paths were closed rather than assumed:

- **The provider never recovers.** `CompleteVerification::failed()` deletes the photograph anyway. It was collected for one purpose, that purpose can no longer be served, and keeping it because the judgement failed would be the one way a photograph outlives its verification. The attempt is left `pending`, so the buyer has not spent one of their five on the platform's outage.
- **A worker dies mid job.** `verification:cleanup` sweeps orphans older than six hours, scheduled daily. Age is read from the file rather than from any row, because the row deliberately does not know the path.
- **A retry finds the file already gone.** Deletion is tolerant of a missing file. The goal is that it is gone, and it being gone already satisfies that.

No response on any path carries a path, a URL, or the file. A test reads the raw bodies of all three verification endpoints as text and refuses `attempts/`, `verification-photos`, `photo_path`, and `.jpg`.

**Deviations from the plan.**
- **Starting an attempt consumes nothing; only a concluded submission does.** A buyer who starts, cannot photograph the product today, and comes back tomorrow has lost none of their five. Restarting returns the **same code** rather than issuing a new one, so a refresh does not invalidate what they have already written on paper.
- **A failed verification answers 200, not a 4xx.** The request succeeded and the answer was no. A buyer who photographed the wrong thing has not made a bad request, and the client should not have to treat a normal outcome as an error.
- **The upload uses the shared `ImageUpload::assertAcceptable` rather than Laravel validation rules.** The contract registers `unsupported_media_type` and `file_too_large` as codes in their own right, and a `mimetypes:` or `max:` rule would return `validation_failed` instead. The client branches on the code, so a wrong sized photograph and a missing field must not look identical to it.
- **`CommunityPostResource` carries a display name and nothing else.** No user id, no email, and **no store**. A user who runs a store posts as a verified buyer, and naming their store would turn a discussion into advertising. There is a test asserting the store name appears nowhere in the body.
- **There is no `is_verified` flag on a post.** An unverified author cannot post at all, so the field would always be true, and a field that is always true is one that will eventually be false by accident.
- **Threads are one level deep.** A `parent_id` naming a reply is refused. A tree on a product discussion is harder to read than a flat list and nothing asked for one.
- **EP-57 refuses when the parent is soft deleted.** Eloquent hides the parent on its own but would happily serve its children, leaving half a conversation with its subject missing. The parent lookup is what makes "deleted posts are hidden along with their replies" true rather than half true.
- **The summary is regenerated on each new post rather than on a schedule**, so it follows the discussion rather than the clock. Queued and never awaited: a provider outage must not fail a post that is already written, and a summary a few posts behind is a perfectly good state. Below three posts it writes nothing, because summarising two comments produces a sentence longer than the thing it summarises.
- **A provider failure during summarisation leaves the previous summary in place** and returns no error anywhere. Nobody is waiting on it, and yesterday's summary beats none. This is the one AI path in the platform that neither degrades nor queues, because there is no user in the loop to tell.
- **The summary prompt forbids a rating explicitly.** The platform has no star score and no sentiment number, and a model left to itself reaches for one. A test asserts no summary response contains `rating`, `score`, or `stars`.

**Known gaps handed to the other side.**
- **Nothing blocking. S-06 and S-15 are both unblocked, and X-01 has a fifth flow to cover.**
- **EP-33 is the only thing the composer should branch on.** It carries `is_verified`, `attempts_used`, `attempts_remaining`, `can_attempt`, `latest_outcome`, `pending_code`, and `pending_job_id`, which between them answer every state without the client inferring anything. `can_attempt` is a rendering hint: EP-34 and EP-35 re-check and refuse regardless.
- **`pending_code` exists so a buyer who closed the page can be shown their code again.** Without surfacing it they would reasonably assume they had to start over.
- **Exhausting five attempts is final.** No appeal, no administrator reset, no way to buy more, and none of those should be built: they are on the list of things deliberately absent.
- **A verification photograph is never shown back to the buyer**, including immediately after upload. There is nothing to preview, by design.
- **Administrator post deletion is M11.** Soft deletes work and are respected by every read here, but EP-44 does not exist, so nothing can currently remove a post.
- **The mail driver is still `log` locally**, and the queue worker must be running for a queued verification to ever conclude. The scheduler must be running for the photograph sweep: `php artisan schedule:work` locally, cron in deployment.
- Escalated proposals remain a dead end until M11, unchanged by this milestone.

**Verified by.**
- 27 tests in `tests/Feature/Api/CommunityTest.php`
- The build plan's stated M9 list, item by item: posting refused with `not_verified` without verification of that specific product, verification of one product granting nothing on another **and** one buyer's exhausted attempts leaving another buyer free, the ceiling of five enforced per user per product with `attempts_exhausted` afterwards, **the photograph deleted on both the passing and the failing path** with `photo_deleted_at` set and the disk empty in each case, no photograph path in any response body read as raw text, and a soft deleted post hidden along with its replies
- The queued path proven end to end: a provider failure returns **503 `ai_unavailable`** with the job id at the top level per section 8, the photograph survives, the attempt stays `pending` and unspent, `pending_job_id` is reported by EP-33, and `failed()` deletes the photograph when the provider never recovers
- The sweep proven to leave a recent file alone and remove one older than the threshold
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 372 tests with 366 passed and 5 todo

---

### M9 Community and verification, frontend, 2026-08-27

**Shipped.**
- S-06 `/products/[slug]/community`, the discussion, **server rendered and indexable**
- S-15 `/verify/[slug]`, proving ownership, with the five attempt counter
- X-01's fifth flow, on the verification submit
- `components/community/PostComposer.tsx` and `PostThread.tsx`
- `lib/api/community.ts`, `lib/schemas/community.ts`, and a rewritten `types/community.ts`
- The product page now links to the discussion whether or not a summary exists

**Contract.**
- Contract version at time of writing: 6
- Changes made to api-contract.md: none. This side mirrors it
- Error codes handled on screen: `not_verified` (403), `attempts_exhausted` (403), `unsupported_media_type` (422), `file_too_large` (422), `ai_unavailable` (503)

**The photograph, which is the rule this milestone turns on.**

It is never displayed, at any point. Not as a preview after choosing a file, not on the outcome panel, not anywhere. The file input is cleared on submit rather than turned into a thumbnail, and the screen says outright that the photograph is deleted as soon as it has been checked.

That is enforced rather than trusted. `assertNoPhotograph` reads the raw payload of **all six** endpoints as text and throws on `photo_path`, `photo_url`, `photograph`, `attempts/`, `verification-photos`, and the four image extensions. Zod ignores keys it was not told about, so without that check a backend regression would validate silently and sit in memory waiting for somebody to render it.

**EP-33 is the only source of composer branching**, as the backend asked. Nothing about whether a person may post is inferred from the post list, from a flag on a post, or from anything in the browser:

| State | From EP-33 | Renders |
|---|---|---|
| Anonymous | no session, so no call at all | Read only, sign in link |
| Not verified | `is_verified: false`, `can_attempt: true` | Prompt linking to `/verify/{slug}` |
| Verified | `is_verified: true` | The composer |
| Exhausted | `is_verified: false`, `can_attempt: false` | Final, with no way out offered |

`can_attempt` is treated as a rendering hint throughout. **403 `not_verified` on POST is handled anyway**, and points at the fix rather than dead ending, because the endpoint decides and the window can move between the read and the write.

**Deviations from the plan.**
- **A failed verification is rendered as an outcome, not an error.** It arrives as 200, and the panel states what was wrong and how many attempts remain in the same register as a pass. A buyer who photographed the wrong thing has not made a bad request.
- **`pending_code` is shown on every visit.** A buyer who closed the page sees the code they already wrote down. Without it they would reasonably assume it was lost and that getting another cost them one of five, and the screen says explicitly that getting a code spends nothing.
- **The exhausted state offers nothing.** No appeal, no reset, no support link. Those do not exist by design, and a link would send somebody looking for help that cannot arrive. It does say the ceiling is per product, which is the one useful thing left to tell them.
- **X-01 resumes from EP-33's `pending_job_id`, not only from `localStorage`.** A buyer can have verifications queued on two products at once, and one browser key cannot tell them apart. The API's answer seeds the panel; the stored id is a convenience for the common single case.
- **`AttachFlow` was renamed `QueuedFlow`** and gained `verification`. Verification has nothing to do with attaching, and the old name would have made this union look like the wrong home for it. Six usages, all inside `lib/jobs/`.
- **Replies load on demand rather than with the page.** A product with a long discussion would otherwise pay for every reply on every visit, and most readers open none.
- **A soft deleted post is simply absent.** No tombstone, no "removed by an administrator" line, no placeholder. Inventing one would advertise a moderation feature that does not exist until M11 and would leave a conversation stub with its subject missing.
- **`types/community.ts` was rewritten rather than extended.** The M0 file guessed at `author.id`, an `active_attempt` object, and a `WishlistEntry` that M8 later defined properly elsewhere. None of those shapes are real, and it was imported nowhere.
- **The community page is server rendered on demand rather than statically generated.** It carries no `generateStaticParams`, because a discussion changes on a timescale a build cannot anticipate. It revalidates at 30 seconds and a crawler receives the full thread in the HTML, which is what the indexing rule actually requires.

**A bug this session found and fixed.**

`proxy.ts` listed `/verification` among its protected prefixes, an M0 guess at a route name. The screen that shipped is `/verify/{slug}`, so **the verification screen was reachable without a token** until this was corrected. Caught by walking the anonymous case rather than by any type or test. `/verify-email` is unaffected: the prefix check matches an exact path or one followed by a slash, and `/verify-email` is neither.


**One thing the queued walk exposed about the environment, not the code.**

A long running `queue:work` bootstraps its configuration once and does not see `php artisan config:clear`. The first attempt at this walk had the worker judging jobs with the pre flip configuration while the web process used the new one, so a submission that correctly returned 503 was then completed normally a moment later. Nothing was wrong with either side. It is worth knowing before anyone tries to reproduce the queued path: **restart the worker after changing `AI_FAKE_SHOULD_FAIL`**, or stop it entirely to observe the queued state.

**Known gaps handed to the other side.**
- **Nothing blocking on the backend.**
- **The 5 per minute verification limiter and the five attempt ceiling interact.** Each attempt costs two requests, so a buyer working through all five in a hurry trips `rate_limited` after roughly two and a half. Both limits are correct on their own and neither is a fault, but the screen does not currently explain the pause. Worth a sentence on S-15, or a wider limiter, if it proves confusing in practice.
- **Alerts and the summary remain invisible by design.** Email only, no notification surface anywhere, and the summary is generated in the background with nothing waiting on it.
- **Administrator post deletion is M11.** Soft deletes are respected everywhere here, but nothing can remove a post yet, so there is no moderation affordance to build against.
- **A queued verification is the one AI flow with no local draft to preserve.** The photograph is already with the platform, so there is nothing to retain on screen and the panel simply reports progress.
- X-04 still links to `/analytics`, which is M10 and remains a dead link.
- Escalated proposals remain a dead end until M11, unchanged.

**Verified by.**
- `npm run docs:check`, `npx tsc --noEmit`, `npm run lint`, and `npm run build` all clean, with `/products/[slug]` still statically generated and no Suspense or deopt warnings
- Against the live API through the authenticated proxy:
  - Anonymous read of EP-31 returned the cursor paginator with no token
  - A signed in but unverified buyer was refused with **403 `not_verified`**, and EP-33 reported `can_attempt: true` with five remaining
  - Starting twice returned the **same code**, and `attempts_used` stayed at **0**, so starting spends nothing
  - A photograph without the code returned **200 `failed`** with the reason and four remaining
  - A photograph carrying the code returned **200 `passed`**, after which the post was created and appeared in the public thread
  - The same buyer posting on a second product was refused **403 `not_verified`**, so verification of one grants nothing on another
  - Five failed attempts on that second product drove `attempts_remaining` to **0** and `can_attempt` to false, after which starting again was refused
  - **The queued path, walked with `AI_FAKE_SHOULD_FAIL=true` and no worker running:** submit answered **503 `ai_unavailable`** with `queued_job_id` at the top level per section 8; EP-33 then reported **`pending_job_id` identical to it** with `latest_outcome: pending`, the attempt **not yet spent**, `pending_code` still shown, and the photograph retained on the private disk; EP-50 answered `status: queued` with **`result_type: null`**, as section 8 requires until a job completes. With the provider restored and the worker run, EP-50 answered `status: completed`, `result_type: verification_result` carrying the outcome and reason, EP-33 **cleared `pending_job_id`** and recorded the attempt, and the photograph was **gone from disk**
  - No `photo_path`, `photo_url`, `attempts/`, or `verification-photos` in any payload, in the job result the panel reads, or in the rendered HTML
- `/products/{slug}/community` renders **200 with no token** and its posts are present in the server rendered HTML, so a crawler sees the discussion; `/verify/{slug}` redirects **307 to `/login?next=…`** anonymously

---

### M10 Analytics and version history, backend, 2026-08-28

**Shipped.**
- EP-52 `POST /api/products/{slug}/views`, public, recording a product page view
- EP-39 `GET /api/stores/mine/analytics`, date ranged view counts for the calling store
- EP-46 `GET /api/products/{slug}/versions` and EP-47 `GET /api/products/{slug}/versions/{number}`
- `ViewRecordingService`, `StoreAnalyticsQuery` with a `StoreAnalytics` value object, `VersionHistoryService` with a `VersionEntry` value object
- `AnalyticsController`, `ProductViewController`, `ProductVersionController`, and the resources for each
- `ProductView` model and factory, over a table that had existed unused since M0
- Seeded version chains for every product and 740 product views across 45 days

**Contract.**
- Contract version at time of writing: **bumped from 6 to 7**
- Changes made to api-contract.md: added **section 11.11**, the view recording request and response, the seller analytics shape, and the two version history shapes. Recorded in section 9 that EP-52 shares the public catalogue limiter
- Error codes now live from this milestone: **none are new**. `not_attached` and `store_required` were registered in section 7 since version 1 and are reachable from the version endpoints for the first time now

**No migration was needed, and that is the third time M0 paid off.**

`product_views` and `product_versions` were both created at M0 with the right columns and the right indexes, including the `(store_id, viewed_at)` index that the analytics query turns out to need and the nullable `store_id` that makes an unattributed view expressible at all. The M0 migration comment even anticipates this milestone's shape: *"user_id is nullable because the catalogue is fully public, so most views carry no account at all."*

**Deviations from the plan.**

- **EP-46 and EP-47 are registered in the Auth group, not the Seller group.** The route file's M0 placeholder put them under Seller alongside EP-39. The contract grants them to a seller attached to the product **or an administrator**, and an administrator holds no store, so the seller middleware would have refused them with `store_required` before the request reached the controller. The real check lives in `VersionHistoryService::assertReadable()` instead.
- **A caller with no store gets `store_required`; a caller with a store that does not carry the product gets `not_attached`.** Both are 403 and collapsing them into one code would have been simpler, but a seller then cannot tell "you need a shop" apart from "you do not stock this", and those have completely different fixes.
- **The access check runs before the version lookup on EP-47.** A caller who may not read a history gets `not_attached` for a version number that exists and for one that does not, so the chain's length cannot be probed by watching which numbers answer 404.
- **`changed_fields` is computed rather than stored, and is coarse.** A version holds a whole snapshot rather than a diff, so what changed is worked out by comparing against the version before. It names `specifications` rather than describing which specification moved, because a truthful field level diff of nested attribute and variant lists is a much bigger thing than a history list needs, and the full snapshot is one request away. **Version 1 reports an empty array**: it created the record, and there was no earlier state to differ from.
- **No administrator is named on a version.** An administrator edit carries `is_admin_originated: true` and a null store. Attribution for a change applied to a shared record is what an audit trail is for, but naming the moderator who applied it serves no seller and gives a disgruntled one a target. The frontend's M0 guess at `causing_admin: { id, name }` is not what shipped.
- **No proposal id on a version either.** EP-29 answers 404 to any store that was neither the proposer nor a frozen reviewer, which is most of the audience for this list, so the id would have been a link that mostly does not open.
- **A `store_id` on EP-52 that names a store not carrying the product is recorded as null rather than refused.** A seller detaching between the page rendering and the view arriving is an ordinary race, and a 422 into a public page render would turn it into a visible error for a visitor who did nothing wrong. The view still happened and still counts at product level. The response echoes back what was actually attributed, so the difference is visible to a client that expected otherwise rather than being discovered in an analytics screen weeks later.
- **EP-39 answers two counts, not one.** `store_views` is what reached this seller and `product_views` is all the interest in the same products. A single number with nothing to compare it against does not tell a seller whether forty views is good. Both totals are the sum of the `products` rows, so the breakdown and the totals cannot disagree.
- **The daily series is zero filled server side**, covering every date in the range. A chart with holes in it is the commonest way a quiet week gets mistaken for a broken endpoint, and the server already knows exactly which days it was asked about.
- **A product the seller has detached from stays in the breakdown**, carrying its historical views and flagged `is_carried: false`. Dropping it would make a seller's total shrink retrospectively every time they removed a listing.
- **A range longer than 366 days is pulled forward rather than refused.** The seller asked for a period, and answering the most recent year of it beats a validation error about a ceiling they had no way to know. A range that ends before it starts is still a 422, because that is a mistake rather than an ambitious request.
- **EP-46 paginates at 20 per page.** Section 2 says every list endpoint returns the length aware paginator, and version chains being short today is not a reason to ship the one list shape that would have to change later.

**A column that is deliberately never written.**

`product_views.user_id` is null on every row this platform creates. EP-52 is a public route, invariant 9 forbids resolving a session on one, and so there is nobody to attribute a view to even when the visitor happens to hold a token. There is a test asserting the column stays null on a request made with a valid token. The column stays for a future authenticated path rather than for this one, and **no analytics figure anywhere is per user**.

**Known gaps handed to the other side.**
- **Nothing blocking. S-30 and S-31 are both unblocked, and S-04 has one call to add.**
- **S-04 should POST to EP-52 once per product page render**, passing `store_id` only when the visitor arrived through that store's context, meaning from S-07 or from a seller list entry. **Omit the field entirely otherwise.** Sending a store id that the visitor did not actually arrive through would attribute a view to a seller who did not earn it, and the endpoint cannot tell the difference.
- **EP-52 answers 201, not 204**, and its body carries the store the view was actually attributed to. A null there after sending a store id is not an error and should not be surfaced to the visitor.
- **`changed_fields` names parts of the record, not fields of the product.** Expect `specifications`, `attributes`, `variants`, `name`, `slug`, `description`, and `category`, and expect an empty array on version 1.
- **The 403 on the version endpoints has two codes and they want different copy.** `store_required` means the reader has no shop; `not_attached` means they have one but do not carry this product. The plan calls out the second as the explanation S-31 has to render when a seller detaches mid session, and it is a real 403 rather than a rendering hint: there is no flag to read in advance.
- **There is no rollback control and none is planned.** History is read only. An administrator wanting an old value edits forward through EP-43 at M11, which writes a further version.
- **Analytics days are UTC days.** A seller in Colombo will see an evening's traffic land on the following day's bar. That is a real cost of a single fixed reckoning and is worth a line on S-30 rather than a client side correction, which would make the daily series and the totals disagree.
- **Seeded analytics are deterministic**, with a weekend dip and a slow decline going back in time, so the date range control visibly does something. Northern Supplies receives no attributed views at all, so the state where a seller has listings and real interest in what they stock but nothing attributed to them is reachable on screen. Lumen Desk Lamp and Orbit Wireless Earbuds have views but no sellers, so every one of theirs is unattributed.
- **`X-04` can stop treating `/analytics` as a dead link.**
- Escalated proposals remain a dead end until M11, unchanged by this milestone.

**Verified by.**
- 21 tests in `tests/Feature/Api/AnalyticsTest.php` and 20 in `tests/Feature/Api/VersionHistoryTest.php`
- The build plan's stated M10 list, item by item: version history refused with `not_attached` for a seller who holds a store and carries two other products but not this one, access re-read per request so a detach between two calls on **the same token** turns 200 into 403, a rejected proposal absent from the chain, anonymous access refused with 401 on both version endpoints, and view counts attributed to the right store with two sellers reading the same product level rows and each seeing only their own
- The rejected proposal case driven through `ProposalResolutionService` with two real reject votes rather than asserted against the code that writes versions, then read back through EP-46: still one version, no row carrying that proposal id, and the product's specification untouched
- Walked against the live API on seeded data: EP-52 recording anonymously with `X-Access-Level: public` and no `Set-Cookie`, attributing to two different carrying stores, and dropping a store that does not carry the product; EP-39 read by two sellers over the same rows returning 1 view each out of 3; EP-46 returning the three step chain with the administrator edit carrying a null store and version 1 carrying an empty `changed_fields`; EP-47 returning the earlier battery figure from version 1 and the current one from version 3; the administrator reading a history with no store of their own, including on a product nobody sells; and the detach walk above
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 413 tests with 407 passed and 5 todo

---

### M10 Analytics and versions, frontend, 2026-08-28

**Shipped.**
- S-30 `/analytics`, date ranged view counts with the daily chart and the per product breakdown
- S-31 `/versions/[slug]` and `/versions/[slug]/[number]`, the version chain and one snapshot
- The EP-52 view recording call added to S-04, and the store context links on S-07 that feed it
- `components/product/ViewRecorder.tsx`, `components/product/VersionAccessNotice.tsx`, `components/seller/ViewsChart.tsx`
- `lib/api/analytics.ts`, `lib/api/versions.ts`, `lib/schemas/analytics.ts`, `lib/schemas/version.ts`, `types/analytics.ts`
- UTC day helpers in `lib/format/dates.ts`, and a rewritten version section in `types/product.ts`
- `/versions` added to the proxy's protected prefixes; "Record history" links added to S-21 and to the analytics table

**Contract.**
- Contract version at time of writing: 7
- Changes made to api-contract.md: none. This side mirrors it
- Error codes handled on screen: `not_attached` (403) and `store_required` (403). Both were registered since version 1 and are reached by these screens for the first time

**X-04's `/analytics` link needed no change and never did.**

The entry has been in `AccountNav` since M0, pointing at a route that did not exist. Building the route is the whole of "wiring it up". Worth recording because the obvious reading of the task was to add a link, and adding a second one would have produced a duplicate.

**Version history is not under `/products/`, and that is load bearing.**

The proxy matcher deliberately excludes `products`, `search`, and `stores` so public catalogue traffic never resolves a session. A protected page at `/products/{slug}/versions` would have inherited that exclusion and silently lost its login redirect, and it would also have meant two route groups owning the same `products/[slug]` subtree. S-31 lives at `/versions/{slug}` instead, with `/versions` added to `PROTECTED_PREFIXES`. Neutral between the two audiences the endpoint serves: an administrator has no listings, so anything under `/listings/` would have been wrong for them.

**Deviations from the plan.**
- **EP-52 goes straight to Laravel, not through `/api/proxy`.** The proxy attaches the Bearer token from the httpOnly cookie, and sending one to a public route is what invariant 7 rules out. The call uses `credentials: 'omit'` cross origin, so no cookie and no Authorization header reach it. There is precedent: `SellerListPanel` already fetches the public seller list the same way. Confirmed the backend answers the preflight with `Access-Control-Allow-Methods: POST`.
- **The recorder is a client effect, not a server call.** S-04 is statically generated and served from the cache, so a view recorded during the render would count builds rather than people, and reading anything request scoped there would deopt the route out of static generation. It is wrapped in `<Suspense>` because it reads the query string, which is the same pattern `proposals/page.tsx` uses, and a clean rebuild confirms the route is still `●` SSG afterwards.
- **A ref guard makes it fire once per render.** React invokes effects twice under StrictMode in development, and without the guard every development view would be counted twice. Keyed by slug, so a client side navigation to another product records again while a re-render of the same one does not.
- **Every EP-52 failure is silent.** No retry, no error boundary, no visible state. A visitor came to read about a product and nothing they came for depends on this call. `store_id: null` coming back after sending an id is likewise not surfaced: the seller detached between the page rendering and the view arriving, which is an ordinary race rather than anything the visitor did.
- **Only S-07 sends a store context.** It is the one place in this application where a visitor arrives at a product *through* a store. The seller list on S-04 links to `/stores/{id}`, so nobody arrives at the product from it, and attributing a view to a seller because their row happened to render would push every seller's numbers wrong in the same direction. No other product link anywhere carries the parameter.
- **The daily chart is inline SVG rather than a charting library.** Thirty bars and two rectangles each does not justify a dependency, and this way it inherits the page's own colours in both themes. No dependency was added for this milestone.
- **The two bars are overlaid, not side by side.** `store_views` is a subset of `product_views`, and drawing them as neighbours would invite a seller to add them together.
- **The UTC day reckoning is stated on screen rather than corrected.** Shifting labels to local time would make the daily bars disagree with the totals the API computed, and a seller comparing the two would be right to trust neither. The screen says an evening's traffic may land on the next day's bar.
- **`store_required` and `not_attached` get completely different copy** even though both are 403. One means "you have no shop", the other "you have one but do not stock this", and they have nothing in common except the status code.
- **Neither refusal is styled as an error.** Nothing failed. The answer was no. Both are rendered as a notice or an empty state, and both are `retry: false` on the query, because retrying a decision three times only delays the explanation.
- **`types/product.ts` was rewritten rather than extended**, exactly as the backend's entry warned. The M0 file guessed `causing_store` and `causing_admin`; the real shape is `caused_by_store` with `is_admin_originated`, no administrator named and no proposal id. It was imported nowhere, which is the second time an M0 speculative type has turned out to be wrong in every field.
- **`assertNoVersionLeak` refuses `caused_by_user`, `causing_admin`, and `proposal_id`.** None is forbidden by section 6, but all three were left out of 11.11 on purpose, and zod ignores keys it was not told about, so a backend regression that started emitting one would validate silently and sit in memory waiting for somebody to render it.
- **No rollback control anywhere on S-31**, per section 2.3, and not a disabled one either. A greyed out button would imply the capability exists and is merely unavailable to this reader. The snapshot page says outright that a correction goes forward as a new version.
- **Version 1 renders "the first version, where the record began" rather than "changed nothing".** `changed_fields` is empty there because it created the record, and saying it changed nothing would be the wrong reading of an empty array.
- **The analytics zero state and the empty state are separate.** "Nobody looked in this period" and "you are not carrying anything" both produce zeros, and they need different things done about them.

**A stale build cache, which is worth knowing before anyone else sees it.**

The first production build listed two prerendered product pages for slugs that are not in the database. `generateStaticParams` had been served an old catalogue out of `.next/cache`, which also explains a revalidate window that did not match the page. Deleting `.next` and rebuilding produced the correct five. Nothing was wrong with the code, but **a build output is only trustworthy from a clean `.next`**, and the phantom pages would have been read as a routing bug by anyone who found them first.

Unrelated but noticed while checking: the product route reports a 30 second revalidate rather than the 300 the page declares, because `getSellers` fetches with `revalidate: 30` and Next takes the shortest window across a route. That is M2 behaviour and predates this milestone.

**Known gaps handed to the other side.**
- **Nothing blocking.**
- **`user_id` is null on every view row, and the interface says so.** S-30 states plainly that nobody is identified. If an authenticated view path is ever added, that copy has to change with it.
- **The store context is one parameter on one screen.** If a future screen lists sellers in a way that navigates to a product, it has to opt in explicitly by adding `?store={id}`, and it should only do so when the visitor genuinely arrives through that store. There is no automatic behaviour to inherit and that is deliberate.
- **S-30 offers three range presets and no free date entry.** The endpoint accepts any `from` and `to`, so a custom range works by URL today. A date picker is worth adding if sellers ask for one; nothing is blocked without it.
- **The analytics screen makes no claim about sales**, because the platform has none. Views are the only signal, and the copy says a view is somebody opening a product page and nothing more.
- **Escalated proposals remain a dead end until M11**, unchanged by this milestone.
- **Administrator screens are M11.** An administrator can read any version history through these same screens today, which is correct per the contract, but there is no administrator entry point to them: they reach one through a URL or through analytics, neither of which an administrator has. S-32 to S-37 should link version history where it helps.

**Verified by.**
- `npm run docs:check`, `npm run lint`, `npx tsc --noEmit`, and `npm run build` all clean, the build run from a **cleared** `.next`
- `/products/[slug]` still prerendered: five paths marked SSG, `x-nextjs-prerender: 1` on the served page, no `Set-Cookie`, and the product name present in the HTML
- The `ViewRecorder` confirmed present in the client bundle rather than assumed, by finding the view path in the emitted chunks
- EP-52 walked with the exact request the browser sends, `Origin` set and no cookie: **201** with `X-Access-Level: public` and no `Set-Cookie`; a no context call attributed to null; a call through store 1 attributed to store 1; a call naming a store that does not carry the product attributed to **null rather than refused**; every new row carrying `user_id: null`
- `?store=1` confirmed present on S-07's product links and on no other product link in the application
- EP-39 through the running app's proxy with a real session cookie: 62 of 312 over 28 days, and the range control changing the totals, 19 of 90 over 7 days against 66 of 336 over 30
- EP-46 and EP-47 through the same path: the three step chain with the administrator edit carrying a null store, version 1 carrying an empty `changed_fields`, and version 1's snapshot showing the earlier battery figure
- **The detach walked through the running app on one unchanged cookie**: 200, detach, then **403 `not_attached`** on the next request, with a product the same store still carries answering 200 throughout
- A signed in buyer with no store refused with **`store_required`** from both the version and the analytics endpoints
- All three new routes redirecting **307 to `/login?next=…`** anonymously, answering 200 signed in as a seller, and `/versions/{slug}/0` answering 404 before reaching the API

---

### M11 Administration, backend, 2026-08-28

**Shipped.**
- EP-40 `GET /api/admin/escalations`, EP-58 `GET /api/admin/proposals`, EP-59 `GET /api/admin/proposals/{id}`
- EP-41 `POST /api/admin/proposals/{id}/resolve`, EP-42 `POST /api/admin/proposals/{id}/override`
- EP-60 `GET /api/admin/products`, EP-61 `GET /api/admin/products/{id}`, EP-43 `PATCH /api/admin/products/{id}`
- EP-44 `DELETE /api/admin/community/posts/{id}`, EP-45 `GET /api/admin/metrics`
- EP-49 `DELETE /api/products/{slug}/images/{id}`, admin gated but outside the admin prefix
- `AdminProposalService` with an `AdminDecision` value object, `AdminProductService`, `AdminModerationService`
- `AdminProposalsQuery`, `AdminProductsQuery`, `PlatformMetricsQuery`, four resources, two form requests
- `AttributeService`, extracted so option widening has one implementation
- An escalated proposal in the seeder, blocking a seller for nine days

**Contract.**
- Contract version at time of writing: **bumped from 7 to 8**
- Changes made to api-contract.md: added **section 11.12**, the administrator proposal list and detail, the resolve and override request and shared response, the direct edit request, the post and image deletion responses, the metrics shape, and the two administrator product shapes
- Error codes now live from this milestone: **`proposal_not_escalated`** and **`proposal_not_resolved`**, both new and both registered in section 7 before any code returned them

**The open request from M7 is closed.**

*"Nothing resolves an escalated proposal. A seller whose proposal escalates stays blocked with no route out."* Raised at M7 and open for four milestones. EP-41 is the route out, and it is the only one: nothing else in the platform can unblock a seller whose proposal escalated.

**No migration was needed, and that is the fourth time M0 paid off.** `proposals.resolved_by_user_id` was created at M0 and had sat unused ever since, waiting for exactly this.

**Deviations from the plan.**

- **An administrator resolving an escalation writes an ordinary proposal version, not an administrator one.** `caused_by_store` names the proposing store and `is_admin_originated` stays false. The change is the seller's; the administrator only decided it. Flagging it as an administrator edit would null the store and erase the seller who actually proposed it, and section 11.11 already promises sellers that `caused_by_store` names whoever's proposal produced a version.
- **`resolution_reason` is never overwritten by an administrator decision.** It stays `tie_no_majority` or `no_votes_cast`, and the administrator goes in `resolved_by_user_id`. The reason records *why this escalated* and the administrator records *who settled it*: those are two facts, and overwriting one with the other would trade a fact for a fact rather than adding one. A resolved escalation therefore reads as "approved, after escalating on a tie, by administrator X".
- **EP-41 accepts no free text reason.** `resolution_reason` is a coded audit vocabulary the system can query, and prose in that column would destroy it. If a note is wanted later it needs a column of its own, not this one.
- **Reversing an approval never removes an attribute option or a combination.** Invariant 2 forbids removing a combination, by anyone, an administrator included, so a reversal restores scalar fields and specifications and leaves any options the approval added in place. A reversal that stranded generated combinations could never be cleaned up afterwards, which would make the reversal worse than the thing it undid.
- **Reversing an approval leaves the proposing seller's attachment alone.** Reversing a claim about what a product *is* says nothing about whether that shop stocks it, and "nothing is deleted or rolled back" points the same way.
- **A specification whose `from` was null is removed on reversal**, rather than restored as an empty string. The record did not hold that key before the approval invented it.
- **A name or category with a null `from` is not restored on reversal.** Those columns are not nullable and the record has to say something, so the approved value stands. This is only reachable for a proposal that invented a field the record never had, which the wizard does not produce.
- **EP-42 on a proposal that already holds the requested outcome is allowed and is a no op in everything but the audit trail.** It records that an administrator looked and let the decision stand, which is worth more than a refusal that leaves no trace of the review. No second version and no duplicate attachment.
- **EP-43 widens existing attributes only. Naming an attribute the record does not define is refused with `validation_failed`.** Adding a new dimension to a record that already has combinations would leave every one of them with no value for it, permanently, since nothing can remove a combination. The build plan's stated test is about adding an *option*, and that is what shipped. **This is raised as an open request below** rather than treated as settled.
- **EP-43 does not accept `slug`.** It is the record's public address, every static page and inbound link is keyed by it, and a rename would break all of them for a cosmetic gain. It is absent from the rules rather than validated and ignored, so a client that sends one finds out.
- **EP-43 does not accept `variants` either**, and that absence is invariant 2. Accepting the array would be the shape a future mistake needed.
- **EP-43 replaces `specifications` wholesale**, so a key left out is removed. A specification has nothing generated from it, which is exactly why it can be removed where an attribute option cannot.
- **EP-59 names the proposing store, where EP-29 hides it.** A reviewer judging a claim should not know which competitor made it; an administrator settling an escalation cannot decide fairly without knowing who is blocked and what the votes said. The confidence score stays off both, and there is a test asserting it against all six administrator reads.
- **EP-59 carries `intended_listing`.** No attachment row exists while a proposal blocks a seller, so an administrator should see the listing they are about to release before releasing it.
- **A post is soft deleted; an image is destroyed.** Deliberately different. A post is somebody's words and the row survives with every read path hiding it; an image is not evidence of anything and keeping a moderated one on disk serves nobody.
- **The administrator catalogue is keyed by id, unlike every public product route.** A slug is a public address derived from a name and could be wrong about the record. An administrator correcting that name should operate on the row, not on a string derived from the thing they are about to change. EP-49 is the exception and stays on the slug path, because it sits beside EP-48 which added the image.
- **EP-58 ignores an unrecognised `?status=` rather than refusing it.** It can only come from a hand written URL, and the unfiltered list is a more useful answer than a validation error.

**One refactor, and it was necessary rather than tidy.**

`applyApproval` was private on `ProposalResolutionService`. EP-41 and EP-42 both have to cause exactly what a peer approval causes, so it is now public and returns what it did. Two implementations of "what approval means" would drift, and the drift would surface only as a seller who was unblocked and never listed. Option widening moved to a new `AttributeService` and combination regeneration to `VariantGenerationService::regenerateFor()` for the same reason: an administrator widens the same lists, and two implementations of "additive" would eventually disagree about case or whitespace. **The 41 M7 tests were run against the refactor before anything else was built**, and stayed green.

**The invariants test caught the new routes, and was strengthened rather than relaxed.**

Invariant 1's assertion walked every registered write route and refused any whose path mentioned `products`, `variants`, or `attributes`. EP-43 and EP-49 tripped it. The invariant is not that nothing writes to a record, it is that **no seller** does, and invariant 6 explicitly contemplates an administrator edit. So the assertion moved: any write route addressing a canonical record must now carry the `admin` middleware, which is a stronger claim than the one it replaced. A new seller route touching a record fails it, **and so does an administrator route that forgot its gate.** A seller is also refused on EP-43 by request, not merely by middleware inspection.

**Known gaps handed to the other side.**
- **Nothing blocking. S-32 to S-37 are unblocked, and S-06 gains an administrator remove.**
- **The seeder now produces one escalated proposal**, on `meridian-14-laptop`, proposed by Northern Supplies, escalated on a **tie** with one vote each way and real comments on both. `review_opens_at` is nine days back, so the queue has something with genuine age at the top rather than a row that only looks urgent if you read the timestamp carefully. Northern Supplies holds no attachment on that product, and that absence is the block.
- **`seller_unblocked` is true on both outcomes of EP-41, and the confirmation copy must say so.** Approval releases the listing they were waiting on; rejection releases them to try again. Copy that describes rejection as leaving a seller blocked is wrong, and this is the field to key it off.
- **Rejection through EP-41 creates no version and no attachment**, and the record is untouched. The seller may start a fresh attempt immediately.
- **Overriding an approval writes a *further* version.** Nothing is deleted, no version leaves the chain, and the version count goes up rather than down. A screen that describes this as "undo" or "rollback" would be describing something the platform does not do.
- **There is no restore for a deleted post and none is planned.** Soft deletion is how the row survives, not a step towards an undelete.
- **No response anywhere names an administrator to a seller.** `resolved_by` on EP-59 is administrator to administrator; a version never names the administrator who caused it. Do not surface an administrator identity on any seller facing screen.
- **`has_pending_proposal` covers pending and escalated**, because both mean somebody is blocked on that record right now.
- **`oldest_escalation_opened_at` on EP-45 is the one metric that names an obligation.** While it is set, a seller is waiting. Null when nothing is escalated.
- **EP-45 carries nothing per user.** The closest is a count of people who have verified something, which is a number and not a list.
- **The M9 verification limiter question is still open**, unchanged by this milestone.

**Open requests raised by this milestone.**
- **EP-43 cannot add a new attribute to a product that already has one.** Refused with `validation_failed`, because adding a dimension would leave every existing combination without a value for it and invariant 2 means those could never be cleaned up. If an administrator genuinely needs to add an attribute to a live record, it needs a design decision about what happens to the combinations generated under the old set, not a relaxed validation rule.

**Verified by.**
- 37 tests in `tests/Feature/Api/AdministrationTest.php`
- The build plan's stated M11 list, item by item: **both escalation outcomes unblocking the proposing seller**, asserted through `isBlocking()` and through the attachment appearing on approval and staying absent on rejection; a **direct edit creating a version with `is_admin_originated` true and `caused_by_user_id` set to the acting administrator** with no causing store; an **added attribute option generating combinations additively** with the existing attachment unchanged in variant and price; **reversing an approval creating a further version** with both versions present afterwards and the record moved forward rather than back; and a **post soft deleted rather than removed**, still present through `withTrashed` and absent from the public thread with its replies gone
- The invariants file strengthened and green: every write route touching a canonical record carries the `admin` middleware, and a seller is refused on EP-43 by request
- A test reading the raw bodies of all six administrator reads and refusing `confidence_score`, `confidence_band`, and `created_by_store_id`. **Administrators are not an exception to section 6**
- Walked against the live API on seeded data:
  - **UF-35.** EP-40 returned the nine day old escalation with the proposing store named, the tie visible as one for and one against out of two, and `tie_no_majority` recorded. EP-59 showed the change comparison, both reviewer comments, and the withheld listing at 431000 LKR. Approving through EP-41 answered `seller_unblocked: true`, `attachments_created: 1`, `version_number: 2`; the proposal stopped blocking, the attachment appeared, the record read 1.24 kg, and the version was attributed to **Northern Supplies with `is_admin_originated: false`** while `resolution_reason` stayed `tie_no_majority` and `resolved_by_user_id` recorded the administrator
  - **UF-36.** Overriding that approval answered `version_number: 3`; the chain then held **three** versions with the reversal marked administrator originated, the weight was back to 1.3 kg by moving forward, and the seller kept their listing
  - **UF-37.** A direct edit adding a `64GB` memory option answered version 4, took the option list from three to four and the combinations from three to four, and left **all four existing attachments on their original variants at their original prices**. The version carried `is_admin_originated: true`, `caused_by_store: null`, and named no administrator in the response body
  - EP-45 answered the platform snapshot with `oldest_escalation_opened_at` null once the queue was cleared, and EP-60 answered the edited product with its new counts
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, 450 tests with 445 passed and 5 todo

---

### M11 Administration, frontend, 2026-08-28

**Shipped.**
- S-32 `/admin/escalations`, the queue, oldest blocked first
- S-33 `/admin/escalations/[id]`, settling one escalation with EP-41
- S-34 `/admin/products`, the searchable catalogue
- S-35 `/admin/products/[id]/edit`, direct editing with EP-43 and image removal with EP-49
- S-36 `/admin/proposals/[id]`, reversing a resolved decision with EP-42
- S-37 `/admin/metrics`, the platform snapshot
- S-06 gains an administrator remove on every post and reply, through EP-44
- `components/admin/`: `BlockedFor`, `ResolutionReason`, `VoteTally`, `SpecificationEditor`, `AttributeWidener`, `RemovePostButton`
- `lib/api/admin-proposals.ts`, `lib/api/admin-products.ts`, `lib/api/admin-moderation.ts`, `lib/schemas/admin.ts`, `types/admin.ts`
- `app/(admin)/layout.tsx`, wider than the seller group because these screens carry comparisons and tables side by side

**Contract.**
- Contract version at time of writing: 8
- Changes made to api-contract.md: none. This side mirrors it
- Error codes handled on screen: **`proposal_not_escalated`** (409) and **`proposal_not_resolved`** (409), both new this milestone, plus `forbidden` (403) and `validation_failed` (422)

**Nothing needed adding to the navigation or the proxy.**

`AccountNav` has carried `/admin/escalations`, `/admin/products`, and `/admin/metrics` since M0, and `/admin` has been in `PROTECTED_PREFIXES` just as long. Building the routes is what made them live. Worth recording because the obvious reading of "wire up the admin screens" is to add links, and adding them would have produced duplicates. **M0's `queryKeys.admin` factory was also exactly right**, all six keys, which is the first time an M0 speculative artefact has survived contact unchanged.

**Deviations from the plan.**
- **The blocked duration is computed here, not read from the API.** The backend deliberately shipped no `blocked_days` field and sends `review_opens_at` instead, so `BlockedFor` does the arithmetic in one place. It counts from when the proposal **opened**, not from when it escalated: the seller was blocked the moment they submitted, and the days spent waiting on peers count as much as the days spent waiting on an administrator.
- **That number is the largest thing on an escalation row**, and turns red past seven days. Every other figure on S-32 describes a proposal; this one describes a person who cannot sell something, and it is the reason to act today rather than tomorrow.
- **S-33 and S-36 are separate routes reading the same endpoint.** S-33 only ever calls EP-41 and S-36 only ever calls EP-42. Keeping them apart is what stops "this creates a further version" copy appearing in front of somebody whose decision creates the first one, and the two endpoints refuse each other's states anyway.
- **S-33's confirmation keys off `seller_unblocked` rather than off which button was pressed**, so the copy and the API cannot disagree. Both outcomes say the seller is unblocked, because both are.
- **S-36's "later versions exist" warning is derived, not invented.** EP-59 carries no version number of its own, so the proposal's version cannot be named. What can be said honestly is that versions exist whose `created_at` is later than the proposal's `resolved_at`, and that a reversal will not touch them. That is EP-46 doing work it was not built for, and it is the truthful version of the warning that was asked for.
- **S-36 offers a "let it stand" action.** EP-42 accepts the outcome a proposal already holds and records that an administrator reviewed it, which is worth more than a review that leaves no trace. It is offered as a distinct, non destructive choice rather than hidden.
- **S-35 offers no add-attribute control at all**, and not a disabled one. The API refuses it, it is a live open request rather than a settled design, and a greyed out button would say the capability exists and is merely unavailable to this person. The screen states the rule instead.
- **`SpecificationEditor` is a full editor with a remove per row**, because EP-43 replaces the map wholesale. An add-only form would have made removal impossible while appearing to work, which is the kind of mismatch that only surfaces when somebody tries to delete a wrong specification and cannot.
- **The combination preview is arithmetic on data already in hand**: the cross product of the option lists after widening, minus the combinations that exist. No endpoint was needed and none was asked for.
- **S-37 has an explicit slow state.** After 2.5 seconds the skeleton gains a line saying every view ever recorded is being counted with no rollup behind it. The backend flagged this as a screen that will get slower; a skeleton that just sat there would read as broken.
- **`oldest_escalation_opened_at` leads S-37** rather than sitting in a grid of counts, and renders as a reassurance when null. It is the only figure on that endpoint that names an obligation.
- **No administrator identity appears on any seller facing screen.** `resolved_by` is rendered on S-36 only, which is behind the admin gate. Nothing on S-06, the version history, or any proposal screen names who moderated or decided anything.
- **The admin remove on S-06 required no change to how a removed post reads.** The thread already handles an absent post by it being absent, which is what M9 built, so this milestone added a control and nothing else.

**A bug TypeScript caught that a test would not have.**

S-36 compared `confirming === data.status`, a `Decision` against a `ProposalStatus`. The two vocabularies deliberately differ, `approve` against `approved`, so the comparison was false forever and the "let it stand" dialog would have shown the reversal warning to somebody who asked to leave the decision alone. It was found by `tsc`, not by clicking, and is now a named `standingDecision` variable with the reason written beside it.

**Known gaps handed to the other side.**
- **Nothing blocking.**
- **The seeder produces no community posts, so S-06's administrator remove cannot be demonstrated on seeded data.** The walk below needed a thread created by hand. Everything else in M11 had seeded data waiting for it, and this is the one state that did not. **Raised as an open request below.**
- **S-34's search is a plain name match, deliberately**, not the buyer's relevance ranked catalogue search. An administrator is finding one known record, and a stale index would be actively misleading about what exists. There is no category filter control on screen yet, though the endpoint accepts one and the URL parameter is honoured.
- **S-32 does not poll.** Its staleness is 30 seconds, which is short for this application, but two administrators working the same queue will still race. That is handled where it matters: EP-41 answers `proposal_not_escalated` and S-33 renders it as "another administrator settled this first" with a link to see what they decided, rather than as a failure.
- **There is no bulk action anywhere.** Each escalation is a separate judgement about a separate product, and a "resolve all" would be the control that made that stop being true.
- **No screen restores anything.** No post restore, no version rollback, no image undelete. All three are absent by design and none is rendered as a disabled control.
- **The M9 verification limiter question is still open**, unchanged by this milestone.

**Open requests raised by this milestone.**
- **The seeder creates no community posts.** S-06's administrator remove, and the four composer states M9 built, have no seeded thread to render against. Verifying ownership to create one by hand costs a photograph upload per product, so a seeded verified buyer with a short thread on one product would make both milestones demonstrable. Not blocking: the control is built and proven against a hand made thread.

**Verified by.**
- `npm run docs:check`, `npm run lint`, `npx tsc --noEmit`, and `npm run build` all clean, the build run from a **cleared** `.next`
- `/products/[slug]` still prerendered afterwards: five paths marked SSG. Adding the moderation control to `PostThread` did not deopt anything, because the community route was already server rendered on demand
- All six administrator routes redirecting **307 to `/login?next=…`** anonymously and answering 200 signed in as an administrator
- Walked against the live API through the running app on seeded data:
  - **UF-35.** EP-40 returned the nine day old tie, Northern Supplies, one vote each way of two reviewers, with **no confidence field anywhere in the body**. EP-59 showed both reviewer comments and the withheld listing at 431000 LKR. Approving answered `seller_unblocked: true`, `attachments_created: 1`, `version_number: 2`; the proposal stopped blocking, the attachment appeared, and the queue went to its empty state
  - **UF-36.** Overriding that approval answered `version_number: 3`. The chain then held **three** versions with the reversal marked administrator originated, the weight was back to 1.3 kg by moving forward, and **the seller kept their listing**
  - **UF-37.** S-34 found the laptop by name; EP-43 added a `64GB` option and answered version 4 with the option list at four and the combinations at four. **All four existing attachments were unchanged in variant and price.** The preview arithmetic predicted exactly one new combination, which is what was generated
  - **UF-38.** An administrator removed a top level post: `replies_hidden: 1`, the thread went to zero posts with **no tombstone**, the reply endpoint answered 404, and the row survived as a soft delete, one visible against two with trashed
  - **UF-39.** EP-45 answered the snapshot with `oldest_escalation_opened_at` null once the queue was clear
  - **Refusals:** a new attribute refused with `validation_failed` naming the attribute, `proposal_not_escalated` on resolving a settled proposal, `proposal_not_resolved` on overriding an escalated one, and `forbidden` for a signed in seller reaching an administrator endpoint
- The database was reseeded afterwards, so the escalation is back and the next session starts where this one did

---

### M12 Caching, revalidation, and hardening, backend, 2026-08-28

**Shipped.**
- **EP-51** dispatched as a queued job on every version creation, and on nothing else
- Catalogue response caching across EP-08, EP-09, EP-10, EP-12, EP-13, and EP-53, invalidated by the writes that make it wrong rather than by a timer
- The **live flag reconciliation job**, closing the hole M8 named
- **Horizon installed and configured**, with two supervisors, three named queues, and a wait threshold on each
- The two existing scheduled commands moved onto a monitored queue, and a third added
- **`maintenance:health`**, which is the monitoring the build plan asked for on the review window sweep
- `app/Jobs/`: `RevalidateProductPage`, `ResolveExpiredReviewWindows`, `DeleteOrphanedVerificationPhotographs`, `ReconcileStoreLiveFlags`
- `app/Services/Catalogue/CatalogueCache.php`, `app/Concerns/InvalidatesCatalogueCache.php`
- `config/frontend.php`, `config/catalogue.php`, `config/maintenance.php`, `config/horizon.php`

**Contract.**
- Contract version at time of writing: **9**, bumped by this milestone
- Changes made to api-contract.md: **EP-51's path corrected from `/api/internal/revalidate` to `/api/revalidate`**, and the request, response, and refusal shapes written out. The `internal` segment existed only in that table. The frontend build plan specifies `app/api/revalidate/route.ts`, the client has hosted the handler there since M0, and M0's own verification step calls `/api/revalidate`, so the contract was describing a path nothing had ever served. Corrected rather than implemented, because one table disagreeing with two documents and a shipped route handler is the table being wrong
- Error codes now live: **none new.** EP-51's refusals are `unauthenticated`, `validation_failed`, and `misconfigured`, all answered by the client rather than by this API

**What EP-51 fires on, and why it cannot fire on anything else.**

The dispatch is inside `ProductVersionService::record()`, which is the single place a version row is written. Invariant 6 says a version exists for an accepted proposal, an administrator edit, and the wizard creating version 1, and for nothing else. Hanging the dispatch off that one method rather than off each of the four callers is what makes "fires only on a version" a property of the code instead of a rule four call sites have to remember. **A rejected proposal writes no version, so it reaches nothing that could fire.** There is no branch to get wrong.

Dispatched with `afterCommit`, so a version that rolls back never triggers a rebuild, and queued, so a client that is slow or down cannot fail the request that created the version.

**Deviations from the plan.**
- **The catalogue cache uses generation counters, not cache tags.** Tags are a Redis and Memcached feature. A tagged implementation would work in production and silently stop invalidating anywhere Redis was not configured, and the symptom would be a product page serving last week's specifications with nothing in any log to say why. A generation counter needs only get and put, so it behaves identically on the database store used here and on Redis in production. **The cache that is tested locally is the cache that runs**, which matters more than tag ergonomics.
- **Generations are microsecond stamps rather than an incrementing count.** An evicted counter restarts at zero and hands out namespaces that already hold entries, which serves genuinely stale data. An evicted stamp produces a number larger than every one before it, so the worst case is a rebuild.
- **Invalidation hangs off the models, not the services.** The same reasoning `recomputeLiveFlag` already used: a future write path is covered without whoever writes it knowing this layer exists. `Product`, `Attachment`, `Store`, `ProductImage`, and `CommunitySummary` each say when a catalogue read has gone wrong.
- **EP-11, the seller list, is not cached and will not be.** Its ordering depends on the buyer's coordinates, so a shared entry would be wrong for somebody and an entry keyed by coordinates would never get a hit. There is a test asserting two buyers in different cities get different orderings, so this cannot be quietly changed later.
- **The store profile's visibility check sits outside the cache.** A cached 200 would keep a dark store reachable by anybody holding its URL, which is the one thing EP-13 must get right.
- **The category list moved onto the catalogue generation and lost its private key.** It used to expire on a timer and nothing else, so a new category could be up to an hour late appearing in the filter. That was a small existing bug and this closes it.
- **The two scheduled commands became queued jobs, and the commands became thin callers of them.** `proposals:sweep` and `verification:cleanup` still exist, still take the same options, and run the same code inline. What changed is that the scheduled path now goes through a queue Horizon watches, so a failure is a row in the failed jobs list rather than a line in a log nobody reads.
- **`withoutOverlapping` on the schedule became `ShouldBeUnique` on the jobs.** Dispatching moved the overlap risk rather than removing it: the scheduler can no longer overlap, but two sweeps could sit in a backlog and be taken by two workers at once. That is the race the row lock in the resolution service defends against, and not starting it is cheaper than winning it.
- **The commands call `handle` through the container rather than using `dispatch_sync`.** `dispatch_sync` hands the job to the sync connection, which serialises it and runs a copy, so everything the run recorded about itself is lost with that copy. This was found by a failing test rather than by reading, and it would have made all three commands report "nothing to do" while quietly doing the work.
- **Monitoring checks outcomes, not whether jobs ran.** This is the largest deviation and the one worth reading. The obvious design is a heartbeat per job with an alarm when it goes stale. It answers the wrong question: a sweep that ran on time and resolved nothing because of a bug leaves a perfectly fresh heartbeat and a seller who is still blocked. `maintenance:health` asks instead whether a proposal is sitting past its review window, whether a photograph has outlived its verification, and whether a live flag disagrees with its attachments. Those are true regardless of cause, so a stopped scheduler, a dead worker, a queue pointed at nothing, and a mistake in the matrix all surface the same way.
- **Horizon and the health check cover different halves and neither replaces the other.** Horizon can say a job threw, how long it waited, and how many are queued. It cannot say that a job which is not being dispatched at all should have been, because there is nothing to see.
- **`default` has the tightest Horizon wait threshold, not `maintenance`.** Not the ordering of importance, deliberately. `default` carries the AI jobs X-01 polls for, so a wait there is a person watching a spinner. The sweep matters more and runs hourly, so a ninety second wait on it means nothing, and alarming on that would train somebody to ignore the alert that does matter. The sweep's consequence is covered by the health check instead.
- **Two Horizon supervisors, not one.** Revalidation is the only work in the platform that waits on an external service, so an unreachable client can produce a long backlog of it. Sharing a supervisor with the sweep would put sellers waiting to be unblocked behind a queue of cache invalidations. There is a test asserting the two never share a supervisor.

**Redis and Horizon: what works without them and what does not.**

The open request from M5 asked for this to be settled. It is now settled in the only honest way available on this machine, which is that **it is partly closed**.

| | Without Redis, as this machine runs today | With Redis |
|---|---|---|
| EP-51 revalidation | **Works.** Queued on the database driver, dispatched after commit, retried and failed gracefully | Same behaviour, faster queue |
| Catalogue caching | **Works.** Database cache store, same keys, same invalidation, same tests | Same behaviour, much faster reads. `CATALOGUE_CACHE_STORE=redis` moves only the catalogue |
| Live flag reconciliation | **Works** | Same |
| `maintenance:health` | **Works.** Reads the database and the disk, and needs no queue at all | Same |
| Scheduled dispatch of all three jobs | **Works.** They land in the `jobs` table and a worker takes them | Same |
| Horizon dashboard, metrics, failed job list, wait thresholds | **Does not run** | Works |

**Horizon cannot run on this development machine at all, and that is a platform limit rather than a configuration gap.** It requires the `pcntl` and `posix` extensions, which do not exist on Windows, and it requires a Redis server, which is not installed and has no phpredis or predis client to reach one with. `php artisan horizon` and `horizon:status` fail here with a Redis connection error, which is the correct and expected outcome.

What was done about it: the package is installed and its configuration is real rather than invented, so it is live the moment it runs somewhere that can run it. `composer.json` declares `ext-pcntl` and `ext-posix` under `config.platform`, which is Composer's documented mechanism for "the machine I install on differs from the machine I deploy to". That is why `composer install` still works here without flags, and it is worth knowing it is there.

`horizon:snapshot` is scheduled but skips itself while `queue.default` is not `redis`, so this machine does not accumulate a failed scheduled command every five minutes for work it was never going to do.

**Known gaps handed to the other side.**
- **Nothing blocking.**
- **EP-51's path in the contract changed.** The client was already right; it is the contract that moved to match. No client change is needed, but the frontend copy of the contract must be refreshed, which the shared folder copy does.
- **EP-51 is off by default in the test suite** (`REVALIDATE_ENABLED=false` in `phpunit.xml`). The suite runs on the sync queue, so a dispatched revalidation would make a real HTTP request to a frontend that is not running, on every test that creates a version.
- **The catalogue cache serves pagination links built from the request that filled the entry.** Only relevant if the API were reached on more than one host name, which it is not.
- **The catalogue listing invalidates wholesale on any product change.** It aggregates a lowest price and a seller count across products, so working out which page went stale would cost more than rebuilding all of them. Its TTL is 300 seconds against 3600 for the rest, for the same reason.
- **The M9 verification limiter question is still open**, unchanged by this milestone.
- **EP-43 cannot add a new attribute to a product that already has one**, unchanged by this milestone.
- **The seeder still creates no community posts.** Not touched this milestone; it is a seeder improvement rather than infrastructure.

**Verified by.**
- **34 new tests** across `tests/Feature/Api/RevalidationTest.php` (9), `CatalogueCacheTest.php` (11), and `MaintenanceTest.php` (14)
- The build plan's stated M12 list, item by item:
  - **The webhook rejecting a wrong secret.** The client answers 401 and the job treats it as a failure worth retrying rather than swallowing it, because the usual cause is the two sides holding different secrets mid deployment. A missing secret is treated differently and deliberately: logged once and given up on, because retrying a deployment fault five times produces five identical failures and buries the cause
  - **Revalidation firing on version creation only and never on a rejected proposal.** Asserted in both directions: a version dispatches with the right slug, and a low confidence proposal voted down by its peers resolves to `rejected`, writes zero versions, and dispatches nothing
  - **A slow frontend not failing the request that created the version.** The queue is switched to the database driver for that one test, because the suite runs on `sync` where every dispatch executes inside the caller, and asserting against `sync` would prove the opposite of what the test is for. With the client faked as a connection failure, the version exists, the product points at it, **nothing was sent**, and the work is sitting on the `revalidation` queue. A second test then fails the job outright and confirms the version is untouched afterwards
- The cache asserted by writing and re-reading rather than by inspecting keys: a new version, a new seller, a price edit through `updated` rather than `created`, a detach, a catalogue listing price, a new category, a store going dark, and a soft deleted store disappearing from **every** product page it appeared on
- One test asserts a cache **hit**, so the invalidation tests cannot pass by accident. With caching switched off, that is the only one that fails
- One test runs the whole public catalogue with `catalogue.cache.enabled` false, so the layer is removable rather than load bearing
- Two tests guard the queue configuration against silent drift: every queued job lands on a queue some supervisor actually watches, and the sweep never shares a supervisor with revalidation. A job dispatched to an unwatched queue is never processed and nothing raises anything
- Run by hand against the development database: `stores:reconcile-live` reported every flag already matching, and `maintenance:health` exited 0 with nothing overdue
- `composer test` green: Pint passed, PHPStan level 7 with **0 errors**, **484 tests with 479 passed and 5 todo**, up from 450

---

### M12 Caching and hardening, frontend, 2026-08-28

**Shipped.**
- EP-51 verified end to end against the running backend, from an administrator edit through to the rebuilt page
- `components/product/StructuredData.tsx`, holding both public JSON-LD blocks, with the store schema new
- `app/robots.ts`, the site wide indexing rules from section 6.2
- `lib/site.ts` and `metadataBase`, so the canonical URLs the pages have emitted since M2 resolve to something real
- Indexing audited across every route, and the one gap closed
- The M10 `revalidate` mismatch settled
- An accessibility pass on the public group: a skip link, a visible focus ring, and a keyboard usable variant selector

**Contract.**
- Contract version at time of writing: **9**
- Changes made to api-contract.md: none from this side. Version 9 was the backend correcting EP-51's path to `/api/revalidate`, which is where this client has hosted the handler since M0. **No client change was needed.** The contract moved to match what was already built
- Error codes handled on screen: none new

**EP-51, walked end to end rather than asserted.**

The route handler needed no change. What it needed was proof, and this is the sequence that was run with both servers up and a queue worker to hand.

1. The webhook answers **401** with no header and **401** with a wrong one, **422** for a body carrying no slug, and **200** with `{"revalidated":true,"slug":…}` for a correct secret. The 500 `misconfigured` path is reachable only by unsetting the secret on this side, which is a deployment fault rather than a caller one, and is why it is not a 401
2. The page was warmed so it held a known specification, then an administrator edit through EP-43 changed the weight to 1.21 kg and answered `current_version_number: 3`
3. **The page still served 1.28 kg**, and exactly one job was sitting on the `revalidation` queue. This is the important step: the request that created the version had already finished, and nothing had spoken to this application yet
4. The worker ran the job in 98 ms, and the page then served **1.21 kg**, with `/products/{slug}/sellers` rebuilt alongside it
5. An escalated proposal was then **rejected** through EP-41. It answered `version_number: null`, the version count stayed at three, and **zero** revalidation jobs were queued. The only jobs in the table were on `default`

Step 5 is the one the build plan asks for and step 3 is the one worth keeping. Together they say the webhook fires on a version, never on a rejection, and never inside the request that caused it.

**Deviations from the plan.**
- **`/products/[slug]/sellers` is `noindex, follow` with its canonical pointing at the product page.** Section 6.2 names three indexable routes and this is not one of them, which is the right call rather than an oversight: it shows the same seller list the product page already carries, and two indexable URLs describing one product compete with each other. The one that should win is the static, revalidated page rather than a `force-dynamic` route a crawler cannot cache. `follow` stays on, because the point is to stop it ranking, not to hide the store links that lead to pages which are indexable.
- **`/` and `/products` stay indexed** although section 6.2 does not name them. That section lists the indexable *product family* and then names its exceptions; section 4 describes the whole public group as "anonymous, indexable". A home page and a catalogue browse that no crawler may index would leave the site with no entry point at all, which is not a reading anybody intended.
- **`robots.txt` was added, and page level `robots` metadata kept.** They do different jobs. A `noindex` tag is read *after* the page is fetched, so it stops a page ranking but not the request; a disallow stops the request. The authenticated routes redirect anonymously, so a crawler wandering into `/dashboard` learns nothing and costs a redirect anyway.
- **`/search` is deliberately not disallowed in `robots.txt`.** It carries `index: false, follow: true`, meaning "do not rank this, but do walk the product links on it". Disallowing it would stop the crawler reading the page and therefore stop it following those links. **A rule that blocks a crawl is not a stronger version of a rule that blocks an index**, and treating it as one is how sites accidentally hide their own catalogue.
- **The disallow list is derived from `PROTECTED_PREFIXES`**, which `proxy.ts` now exports. Two hand written lists would drift the first time a route was added, and the failure would be silent in both directions.
- **Each prefix is emitted as `/x$` and `/x/` rather than as `/x`.** This was a bug found by reading the generated file rather than by writing it. A robots.txt rule matches by prefix and nothing else, so a bare `Disallow: /store` also blocks **`/stores/1`**, which section 6.2 requires to be indexed. It would have hidden every store page on the platform from search, and the only symptom would have been traffic that never arrived. The anchored pair reproduces exactly the "exact match or followed by a slash" rule the proxy applies.
- **`metadataBase` was missing and is the reason canonical URLs were being published wrong.** The pages have emitted `alternates.canonical` and Open Graph images as paths since M2, and Next resolves those against `metadataBase`, which was unset and so defaulted to localhost. A deployed page would have published a canonical URL pointing at the machine that built it, which is worse than emitting none: the one tag whose whole job is to say where a page really lives would have said somewhere unreachable.
- **JSON-LD `@id` is absolute, everything else in metadata stays relative.** Next resolves metadata paths against `metadataBase` for us, but JSON-LD is a string written by hand, and a schema.org `@id` is an identifier rather than a link. A relative one identifies a different thing on every host that serves the page.
- **Structured data emits nothing the page does not also show.** No invented rating, no availability claimed for a product nobody stocks, no field padded to look complete. A store with no rating gets no rating block rather than a zero, which would read as a bad review instead of an absence of reviews. Availability on the product is read off the listings, so a product every seller has marked unavailable says out of stock.
- **The M10 `revalidate` mismatch was settled by raising the fetch to meet the page, not the other way round.** Next takes the **shortest** revalidate across every fetch in a route, so the seller list's 30 seconds was silently dragging S-04 from its declared 300 down to 30, and the most important static page in the system was being rebuilt ten times more often than anybody had asked for. Product content is invalidated by EP-51 the moment a version exists, so the timer is only a backstop for prices, and a price at most five minutes old in the *server rendered fallback* is acceptable when anybody with JavaScript sees the live list within a second. **The build output now reads `5m` against `/products/[slug]`, where it used to read `30s`.**
- **S-05 states `revalidate: 0` explicitly.** `force-dynamic` governs how a route renders, not whether an individual fetch is cached, so without it that screen would have kept serving a five minute old price. A preview on S-04 may be slightly stale; the screen whose entire purpose is live prices may not.
- **The variant selector now handles arrow keys, and this was an accessibility bug rather than a missing nicety.** The group has announced itself as a `radiogroup` with `radio` children since M2, which is the right description. But that role is a promise: a screen reader says "radio button, 3 of 6" and the convention is that arrows move within the group while Tab leaves it. Announcing the role without implementing the keys is worse than plain buttons would have been, because it describes behaviour the component does not have. It now has a roving tabindex, arrow keys that wrap, and Home and End.
- **A skip link and a global `:focus-visible` ring were added.** `Button` and `Input` defined their own focus styles, so the components built as components were covered. What was not covered is every plain link, and the public pages are largely made of those.
- **No `aria-current` was added to the main navigation.** It would mean converting a deliberately server rendered component to a client one to read the pathname, and the note on `Navigation` explains why it is server rendered. The catalogue's category chips already carry `aria-current`, which is where it earns more.
- **No sitemap was built.** A complete one needs to enumerate every product and every live store, and there is still no endpoint listing live stores, which is the open request from M2. A sitemap covering the first hundred products and no stores would be a worse signal than none, because it tells a crawler that is all there is.

**A shape bug the production build caught, and the backend fix it forced.**

The first build of this milestone **failed**, on `GET /api/products/{slug}/variants returned an unexpected shape at "0.attribute_values": expected record, received array`.

The cause was in the backend's new catalogue cache, not here. It stored `->getData(true)`, which decodes a response into an associative array and turns every empty `stdClass` into an empty array, so a product with no attributes came back with `attribute_values: []` instead of `{}`. Every resource in that application casts those maps to `object` on purpose. The uncached response was right and the cached one was wrong, which is the worst shape a caching bug can take: it passes every test that reads an endpoint once.

Two things are worth recording. **The zod schemas caught it and nothing else would have** — this is the first time the boundary parsing described at M0 as insurance has actually paid. And it was caught by a *production build*, because prerendering reads every product, including the two seeded ones with no attributes at all. The backend now caches the finished response bytes, and has a test asserting a cached and an uncached response are byte identical across the whole public catalogue.

**Known gaps handed to the other side.**
- **Nothing blocking. This is the last milestone.**
- **`NEXT_PUBLIC_SITE_URL` must be set in any real deployment.** It defaults to `http://localhost:3000`, which is right for development and wrong everywhere else, and a wrong value publishes wrong canonical URLs and a wrong `robots.txt` host.
- **S-07 is still server rendered on demand rather than prerendered**, because no endpoint lists live stores. Unchanged since M2 and still low priority: it affects build time prerendering, not correctness.
- **The root stylesheet sets `font-family: Arial, Helvetica, sans-serif` on `body`**, which overrides the Geist variables the layout puts on `<html>`. Both fonts are therefore downloaded and unused. Noticed during this milestone and deliberately not changed, because it alters how every page looks and that is a design decision rather than a hardening one.

**Verified by.**
- `npm run docs:check`, `npm run lint`, `npx tsc --noEmit`, and `npm run build` all clean, the build run from a **cleared** `.next` each time
- **Build plan section 7, the checks it names before M12 may be called done:**
  - **A production build from a cleared `.next`.** 37 pages generated, five product paths marked SSG
  - **View source on a product page, with no JavaScript running.** The name is in the `h1`, the description is in the body and in the meta tag, all three specifications render as a definition list, and **all six variant combinations are in the HTML**, one of them labelled "No sellers yet". Five seller contact emails are in the static markup, which is the disclosure the platform exists for
  - **Revalidation fires only on version creation.** Walked live, in five steps, above
  - **No confidence score, verification photograph path, or product creator field.** Every public page fetched and searched for `confidence_score`, `confidence_band`, `created_by`, `created_by_store_id`, `creator`, `verification_photo`, `photograph`, and `storage_path`. The only hit anywhere was the word "photographing" in the sentence explaining that owners proved ownership, which is copy rather than a leak
- Indexing checked by fetching each public route and reading the tags it actually served: `/`, `/products`, `/products/[slug]`, `/products/[slug]/community`, and `/stores/[id]` indexed; `/products/[slug]/sellers` and `/search` `noindex, follow`; every authenticated route `noindex, nofollow`
- `robots.txt` read as generated, and checked specifically against `/stores/1`, which the first version of it would have blocked
- Accessibility checked in the rendered HTML rather than in the source: the skip link is the first focusable element and targets a `<main id="main">`, `html lang="en"` is set, both `nav` landmarks are labelled, the radiogroup is labelled by its heading, exactly one radio is `tabindex="0"` and the rest are `-1`, and the `:focus-visible` rule ships in the stylesheet

**Outstanding browser checks, and what became of them.**

Seven were carried into this milestone. Five are settled and two still need a person at a keyboard.

- **1, 2, 3, the view recording calls.** Settled without a browser, at the level the questions were actually about. EP-52 answers **201** with `{"recorded":true,"store_id":null}` for a bare `{}` body and `store_id: 1` when arriving through a store. The request `ViewRecorder` sends carries `credentials: 'omit'` and only `Accept` and `Content-Type`, so there is **no cookie and no Authorization header by construction**, and its ref guard is keyed by slug, so a re-render records nothing and a client navigation to a different product records once
- **7, the administrator remove on a seeded thread.** **Closed.** The seeder gap it was waiting on is gone, see below. Removing the seeded top level post answered `replies_hidden: 1`, the public thread went to zero posts with no tombstone, and both rows survived as one visible against two with trashed
- **6, S-36 "let it stand".** The code is right: `standingDecision` maps the status vocabulary onto the decision vocabulary and the reversal warning keys off it, so the dialog cannot show a reversal warning to somebody who asked to leave the decision alone. **Still wants one look in a browser**, because that is exactly what the original bug was: something true in the types and wrong on the screen
- **4, the analytics presets and the back button**, and **5, two tabs on `/versions/{slug}` with a detach between them.** **Neither was performed.** Both are about browser behaviour that has no HTTP level equivalent: whether the back button moves between date ranges, and what a second tab shows after the first one detaches. They need a person with two tabs open

**An open request closed on the way past.**

The seeder now creates a verified owner and a two post discussion on the phone, which closes the request raised at frontend M11. S-06 shipped at M9 and its administrator remove at M11, and neither had any seeded data to render against: every demonstration needed a thread made by hand, at the cost of a photograph upload and an AI call per product.

The verification is written as the state a real one **leaves behind** rather than faked: a passed attempt with `photo_deleted_at` already set, because invariant 7 says a photograph does not outlive its verification and a seeded attempt still holding one would be a state the platform never allows to exist. The buyer is `test@example.com`, the account already seeded for signing in, so the composer's verified state is reachable by logging in rather than by working out which buyer is the verified one.

**M0 to M12 are complete.**

---

## 4. Open requests

Things one side needs from the other that are not yet built. Remove a row only when it has shipped and been recorded in section 3.

| Raised by | Date | Need | Status |
|---|---|---|---|
| Backend | 2026-08-26 | A Meilisearch server must be installed and running before M3 search work | **Closed 2026-08-26.** M3 shipped against it: the seeded catalogue is indexed and both search endpoints answer from it |
| Backend | 2026-08-26 | Redis must be available before queued AI work needs Horizon's visibility, or the queue driver decision revisited | **Partly closed 2026-08-28.** M12 settled the decision: everything the platform actually does works on the database driver, including EP-51, catalogue caching, reconciliation, and the health check, and the M12 entry carries the full table of what does and does not. Horizon is installed and configured, and **cannot run on this machine at all**, because it needs `ext-pcntl` and `ext-posix`, which Windows does not have, as well as a Redis server that is not installed. The remaining gap is a host, not code |
| Frontend | 2026-08-26 | No endpoint lists live stores, so S-07 cannot be prerendered at build time through `generateStaticParams`. It renders on demand and caches for 300 seconds instead | Open, low priority. Only affects build time prerendering, not correctness |
| Backend | 2026-08-27 | The confidential endpoint specification writes EP-22's outcome with `attachments` and `proposal` objects, while section 11.4 of the contract writes it with `attachment_ids`, `proposal_id`, and `review_closes_at`. The contract is what the client mirrors, so the contract was implemented. Worth deciding whether 11.4 should carry `review_opens_at` and the attachment prices as well, once S-24 is built and it is clear what the screen actually needs | Open. Not blocking: the current shape is sufficient to render both outcomes |
| Backend | 2026-08-27 | EP-19 is not paginated. A store's listings are bounded in practice, but a seller carrying hundreds of products would return one large payload | Open, low priority. Revisit if it becomes a real shape rather than a hypothetical one |
| Frontend | 2026-08-27 | **S-26 and S-27 could not be built at M6.** The build plan lists them under this milestone, but S-26 needs EP-27 and S-27 needs EP-29, both of which are M7. Building either would have meant inventing a shape the backend has not defined. They should be built alongside M7's own screens once those endpoints land, and the "still being built" copy on the S-24 outcome panel and in X-05 replaced with real links then | **Closed 2026-08-27.** Both shipped at frontend M7 against EP-27 and EP-29. S-27 shares the `/proposals/[id]` route with S-29, because EP-29 serves the proposer and the reviewers from one id. The "still being built" copy on the S-24 outcome panel and in X-05 is replaced by real links |

| Backend | 2026-08-27 | **Nothing resolves an escalated proposal.** The matrix escalates on a tie, on no votes at all, and on high confidence with peers against, and EP-41 and EP-42 that act on that are M11. A seller whose proposal escalates stays blocked with no route out | **Closed 2026-08-28.** EP-41 ships at backend M11 and is the only route out: both outcomes unblock the proposing seller, and the seeder now produces an escalated proposal to demonstrate it against |

| Frontend | 2026-08-27 | **Section 11.9 does not state that a wishlist item's `currency` is nullable.** The example shows a populated pair, and the API returns `lowest_price_minor: null` and `currency: null` together when nobody carries the variant, which the same section describes in prose. The frontend schema mirrors the API. Worth writing the null case into the example or a sentence so the next client does not refuse it | **Closed 2026-08-27.** Contract version 6 states it in section 11.9: `lowest_price_minor` and `currency` are always null together and never one without the other |

| Frontend | 2026-08-27 | **The 5 per minute `verification` limiter and the five attempt ceiling interact.** Each attempt costs two requests (start plus submit), so a buyer working through all five in one sitting trips `rate_limited` after roughly two and a half. Both limits are correct in isolation. Worth deciding whether the limiter should be widened, or whether S-15 should explain the pause | Open. Not blocking: the ceiling is enforced correctly and the refusal is a registered code the client already handles |

| Backend | 2026-08-28 | **EP-43 cannot add a new attribute to a product that already defines one.** Options can be added to an existing attribute, but naming a new one is refused, because every combination generated under the old attribute set would be left without a value for it and invariant 2 means those could never be cleaned up. Needs a design decision about what happens to those combinations, not a relaxed validation rule | Open. Not blocking: the milestone's stated requirement is adding an option, which works |

| Frontend | 2026-08-28 | **The seeder creates no community posts.** S-06 and its new administrator remove have no seeded thread to render against, and neither do the four composer states M9 built. Verifying ownership by hand costs a photograph upload per product, so a seeded verified buyer with a short thread on one product would make both milestones demonstrable | **Closed 2026-08-28.** The seeder creates a verified owner on `vertex-one-smartphone` and a two post thread with one reply. The verification is written as the state a real one leaves behind, a passed attempt with `photo_deleted_at` set, rather than a photograph the platform would never let outlive its verification. The administrator remove was demonstrated on it: `replies_hidden: 1`, the thread empty, both rows surviving as soft deletes |

| Backend | 2026-08-28 | **The contract listed EP-51 at `/api/internal/revalidate`, which nothing has ever served.** The frontend build plan specifies `app/api/revalidate/route.ts`, the client has hosted the handler there since M0, and M0's own verification calls `/api/revalidate` | **Closed 2026-08-28.** Contract version 9 corrects the path to `/api/revalidate` and writes out the request, response, and refusal shapes. No client change was needed; the contract moved to match what was built |

Use this table rather than guessing. A frontend screen that needs a field the contract does not define adds a row here. It does not invent a field name and hope.
