# Canonical Marketplace API

The Laravel service behind the canonical product marketplace. It is a **JSON API only**.
Every business rule lives here, and it is the only layer that talks to PostgreSQL,
Meilisearch, object storage, the AI provider, the geocoding provider, and email.

The user facing client is a separate Next.js application at `https://github.com/PraveenIsuru/canonical-marketplace-frontend.git`. Neither half is
usable on its own, so see [Connecting to the frontend](#connecting-to-the-frontend) below.

## What the platform does

Sellers do not create their own product pages. The catalogue holds one canonical record
per product, and a seller attaches a listing to it. Getting a listing attached goes
through an AI assisted matching step, an ownership confirmation step, and where the
canonical record itself needs changing, a proposal that other sellers of the same product
vote on. Buyers browse the canonical catalogue, compare the sellers attached to a product,
and see stores on a map.

## Stack


| Part           | Choice                                                               |
| -------------- | -------------------------------------------------------------------- |
| Language       | PHP 8.3                                                              |
| Framework      | Laravel 13                                                           |
| Database       | PostgreSQL with the PostGIS extension, for store location queries    |
| Authentication | Laravel Sanctum, bearer tokens                                       |
| Search         | Laravel Scout with the Meilisearch driver                            |
| Queue          | Database driver in development, Redis with Horizon available         |
| Storage        | S3 compatible object storage, local filesystem driver in development |
| AI provider    | Swappable adapter: `fake`, `anthropic`, or `gemini`                  |
| Geocoding      | Swappable adapter: `fake` or `locationiq`                            |
| Testing        | Pest 4, with Larastan and Pint                                       |


The scaffold was `laravel/react-starter-kit`, used here as an API service. The Inertia
side, the Fortify screens, and the passkey and two factor tables are unused. Their
migrations stay in place and no route is exposed to them.

## Requirements

- PHP 8.3 or newer, with the usual Laravel extensions
- Composer
- Node.js and npm, only for the starter's asset pipeline rather than for the API itself
- PostgreSQL with PostGIS available
- A Meilisearch instance, using its **admin** key rather than a search only key
- Redis, only if you want to run Horizon instead of the database queue



## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Then edit `.env` and set at least:

- `DB_CONNECTION=pgsql` plus the usual `DB_*` values, pointing at a database where PostGIS
can be enabled. The first migration enables the extension.
- `MEILISEARCH_HOST` and `MEILISEARCH_KEY`.
- `FRONTEND_URL` and `REVALIDATE_SECRET`, both covered in
[Connecting to the frontend](#connecting-to-the-frontend).
- `AI_PROVIDER` and `GEOCODING_PROVIDER`. Both default to `fake`, which is enough to run
the whole application without any external account.

Then create the schema, seed it, and build the search index:

```bash
php artisan migrate
php artisan db:seed
php artisan scout:import "App\Models\Product"
```

The seeder fills the catalogue on purpose. The client has no mock data, so empty states
and edge cases are only reachable through seeded records.

## Running it

```bash
composer dev
```

That runs the HTTP server on `http://localhost:8000`, a queue worker, and Vite together.
The queue worker matters more than it looks. AI matching, confirmation, community
summaries, search indexing, notifications, and frontend revalidation are all queued jobs,
so several features simply appear to hang when no worker is running.

To run the pieces separately:

```bash
php artisan serve                  # http://localhost:8000
php artisan queue:listen --tries=1 # or `php artisan horizon` on Redis
php artisan pail                   # live log tail
```

Some maintenance work runs on the scheduler, so `php artisan schedule:work` is worth
running when testing anything that expires. That covers the hourly review window sweep,
the daily clean up of orphaned verification photographs, the daily store live flag
reconciliation, and an hourly health check.

## The fake providers

`AI_PROVIDER=fake` and `GEOCODING_PROVIDER=fake` return believable canned answers, so no
API key is needed for ordinary local use. Each fake also has a deliberate failing mode,
switched on with `AI_FAKE_SHOULD_FAIL=true` or `GEOCODING_FAKE_SHOULD_FAIL=true`.

The failing mode is how you reach the two paths the platform treats as normal outcomes
rather than errors: buyer search falling back to keyword results, and every other AI path
queueing the work and returning 503 so the client can poll for it.

Changing `AI_PROVIDER` needs `php artisan config:clear` **and** a queue worker restart. A
worker that is already running holds the old value and will keep using it.

## Connecting to the frontend

Start this service first, migrated and seeded, then start the client. The client has no
mock data, so an endpoint that does not answer is a screen that cannot render.

Three settings tie the two halves together.

`FRONTEND_URL` must match wherever the client is served, `http://localhost:3000` by
default. It is used for more than reference. Password reset and email verification links
in outgoing email are built against it, so a wrong value sends people somewhere that does
not exist.

`REVALIDATE_SECRET` must be **the same value here and in the client's environment
file**. Whenever a product version is created, a queued job calls a webhook on the client
so it can rebuild the affected product pages. It is sent as the `x-revalidate-secret`
header, and a mismatch means the client returns 401 and pages quietly go stale. Set
`REVALIDATE_ENABLED=false` to turn the call off when running with no client in front.

`API_URL` **on the client** must point back at this service. Authentication then works
like this: the client posts credentials here, receives a Sanctum bearer token, and stores
it in an httpOnly cookie on its own origin. Because that cookie cannot be read by browser
JavaScript and this service is a different origin, the client forwards authenticated calls
through its own server rather than calling here directly from the browser. Nothing extra
needs configuring on this side.

Two things about the wire format are worth knowing when debugging across the two:

- Successful responses wrap their payload in `data`, and errors are always
`{ code, message, errors? }`. The client branches on `code`, never on `message`.
- Prices cross the wire as integers in the smallest currency unit. The API never emits or
accepts a decimal price.



## Tests

```bash
composer test        # clears config, then lint, static analysis, and the test suite
composer lint        # Pint, fixes formatting in place
composer types:check # PHPStan through Larastan
php artisan test --compact --filter=SomeTest
```

Mostly Pest feature tests under `tests/Feature/Api`.

## How the code is laid out

```
app/
  Http/Controllers/Api/   Thin controllers, one per resource area
  Http/Requests/Api/      Validation, one form request per write
  Http/Resources/         Every response shape, so the envelope is applied in one place
  Http/Middleware/        The `public`, `store`, and `admin` route guards
  Services/               Where the business rules actually live
    Ai/                   Provider adapters and the shapes they return
    Attach/               Matching, the wizard, and ownership confirmation
    Catalogue/            Attributes, variants, versions, and the catalogue cache
    Proposals/            The resolution matrix and its effects
    Search/               Buyer search and the keyword fallback
    ...
  Jobs/                   Everything queued, including revalidation and notifications
  Models/                 Kept deliberately thin
  Queries/                Query objects for the more involved reads
routes/api.php            Every platform route, grouped by access level
database/seeders/         CatalogueSeeder builds the demo catalogue
```

Routes are grouped by access level: public catalogue routes, `auth:sanctum` routes, seller
routes that also require a store, and administrator routes. The API is stateless and
carries no session, cookie, or CSRF middleware, because public catalogue routes must never
resolve a session.