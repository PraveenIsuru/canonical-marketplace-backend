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
| M6 Confirmation and proposals | Done | Not started |
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

## 4. Open requests

Things one side needs from the other that are not yet built. Remove a row only when it has shipped and been recorded in section 3.

| Raised by | Date | Need | Status |
|---|---|---|---|
| Backend | 2026-08-26 | A Meilisearch server must be installed and running before M3 search work | **Closed 2026-08-26.** M3 shipped against it: the seeded catalogue is indexed and both search endpoints answer from it |
| Backend | 2026-08-26 | Redis must be available before queued AI work needs Horizon's visibility, or the queue driver decision revisited | Open, and now less theoretical. M5 added three queued jobs, two of which a seller is actively waiting on. The database driver still works |
| Frontend | 2026-08-26 | No endpoint lists live stores, so S-07 cannot be prerendered at build time through `generateStaticParams`. It renders on demand and caches for 300 seconds instead | Open, low priority. Only affects build time prerendering, not correctness |
| Backend | 2026-08-27 | The confidential endpoint specification writes EP-22's outcome with `attachments` and `proposal` objects, while section 11.4 of the contract writes it with `attachment_ids`, `proposal_id`, and `review_closes_at`. The contract is what the client mirrors, so the contract was implemented. Worth deciding whether 11.4 should carry `review_opens_at` and the attachment prices as well, once S-24 is built and it is clear what the screen actually needs | Open. Not blocking: the current shape is sufficient to render both outcomes |
| Backend | 2026-08-27 | EP-19 is not paginated. A store's listings are bounded in practice, but a seller carrying hundreds of products would return one large payload | Open, low priority. Revisit if it becomes a real shape rather than a hypothetical one |

Use this table rather than guessing. A frontend screen that needs a field the contract does not define adds a row here. It does not invent a field name and hope.
