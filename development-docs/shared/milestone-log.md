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
| M3 Search | Done | Not started |
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

---

## 4. Open requests

Things one side needs from the other that are not yet built. Remove a row only when it has shipped and been recorded in section 3.

| Raised by | Date | Need | Status |
|---|---|---|---|
| Backend | 2026-08-26 | A Meilisearch server must be installed and running before M3 search work | **Closed 2026-08-26.** M3 shipped against it: the seeded catalogue is indexed and both search endpoints answer from it |
| Backend | 2026-08-26 | Redis must be available before queued AI work needs Horizon's visibility, or the queue driver decision revisited | Open, blocks nothing yet |
| Frontend | 2026-08-26 | No endpoint lists live stores, so S-07 cannot be prerendered at build time through `generateStaticParams`. It renders on demand and caches for 300 seconds instead | Open, low priority. Only affects build time prerendering, not correctness |

Use this table rather than guessing. A frontend screen that needs a field the contract does not define adds a row here. It does not invent a field name and hope.
