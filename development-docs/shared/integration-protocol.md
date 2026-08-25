# Integration Protocol

**Status:** authoritative
**Applies to:** both repositories

---

## 0. How this file works

This file is **byte identical in both repositories**, at `development-docs/shared/integration-protocol.md`. It describes how the Laravel API and the Next.js client stay in step while being built one milestone at a time.

Read it before starting any milestone on either side.

---

## 1. The problem this solves

Two repositories, one system, one developer moving between them. The failure mode is not dramatic. It is quiet: the backend returns `data.items`, the frontend expects `data.results`, and nobody notices until a screen renders empty three days later. Or the backend adds a `409 duplicate_store` code while the frontend is still handling `store_exists`, and the error message says "Something went wrong" forever.

Every mechanism below exists to make that class of mistake **fail loudly and early** instead of quietly and late.

---

## 2. Repository layout assumption

The two repositories sit side by side:

```
C:\MyApps\canonical-marketplace\
├── backend\        Laravel API, owns the contract
└── frontend\       Next.js client, mirrors the contract
```

The sync check resolves the sibling by relative path. If you move either repository, update `scripts/check-shared-docs.mjs` in the frontend and `tests/Feature/SharedDocsInSyncTest.php` in the backend.

---

## 3. The shared folder

```
development-docs/shared/
├── api-contract.md          the wire format, backend owned
├── integration-protocol.md  this file
└── milestone-log.md         the handover record
```

These three files are **byte identical in both repositories**. Not "roughly the same". Identical, enforced by a hash comparison.

The backend is the **owner**. Every change starts in the backend copy and is copied across. The frontend never edits its own copy of `api-contract.md` directly, because doing so creates a version of the truth that no server implements.

The one exception is `milestone-log.md`, which **both sides append to**. The copy rule still applies: whoever appends copies the file across in the same commit.

---

## 4. Copying the shared folder

From the backend, after any change to a shared file:

```bash
cp -r development-docs/shared/. ../frontend/development-docs/shared/
```

From the frontend, after appending to the milestone log:

```bash
cp -r development-docs/shared/. ../backend/development-docs/shared/
```

Then commit in both repositories. Two commits, same message, same day. This is deliberate: the drift you are preventing is exactly the drift that happens when one side is committed and the other is not.

---

## 5. The sync check

Both repositories verify the shared folder matches its counterpart.

**Frontend:**
```bash
npm run docs:check
```

**Backend:** a Pest test that runs as part of the normal suite.
```bash
php artisan test --compact --filter=SharedDocs
composer test          # runs it alongside everything else
```

Both compare a SHA-256 of every file in `development-docs/shared/` against the sibling repository, and fail with the differing filenames listed. Both **skip cleanly** when the sibling repository is not present, so a CI runner that checks out one repository alone is not broken by this.

**Run the check before starting a milestone and before finishing one.** If it fails, resolve it before writing code. A failing sync check means you are about to build against a contract that no longer exists.

---

## 6. Milestone order, and why there are no mocks

Within a milestone the order is fixed:

1. Backend implements the milestone's endpoints and their tests.
2. Backend confirms the endpoints answer correctly against seeded data.
3. Backend appends a milestone log entry.
4. Frontend reads the entry, then builds the milestone's screens against the **real running API**.
5. Both sides walk the milestone's demonstration flow together.

There is no mock API layer anywhere in this project. That is a deliberate choice with a real cost and a real benefit.

**The cost.** The frontend cannot run ahead. A screen cannot be built until its endpoint answers.

**The benefit.** A mock is a second implementation of the contract, and a second implementation drifts from the first. Every screen built against a mock has to be re-verified against the real API anyway, so the mock buys speed now and pays for it twice later. Building against the real endpoint means a mismatch surfaces the moment the screen first renders, while the endpoint is still fresh in mind.

**What replaces the mock.** Database seeders. The backend writes seeders early, at M2, covering products with attributes and generated variants, images, stores with coordinates in different cities, and attachments at varied prices. Those seeders are what let the frontend see realistic data. They are part of the backend's milestone work, not an afterthought, and they must produce data rich enough to exercise empty states, zero seller products, dark stores, and long variant lists.

---

## 7. The milestone log

`milestone-log.md` is the handover record. It exists so that whoever picks up the other side of the system can answer "what actually shipped, and does it match what we planned" without reading a diff.

The backend appends an entry when a milestone's endpoints are done. The frontend appends when it has consumed them. Each entry records what shipped, which error codes are now live, and **anything that deviated from the plan**.

Deviations are the important part. A deviation that is written down is a decision. A deviation that is not written down is a bug waiting to be found by the other side.

---

## 8. Rules that prevent the specific failures

**The backend never changes a shape silently.** Change `api-contract.md` first, bump the version, copy across, then write the code. If the change is discovered mid implementation, stop and update the contract before continuing.

**The frontend never invents a shape.** If a screen needs a field the contract does not define, do not guess a name and do not read it optimistically. Append a request to the milestone log, and either implement it on the backend or agree the screen does without it.

**The frontend never invents an error code.** Handle the codes in section 7 of the contract. An unrecognised code falls through to a generic error state, which is correct behaviour, not a gap to be papered over with a guess.

**Every endpoint gets a Pest test asserting its envelope.** Not just its logic, its shape: that success wraps in `data`, that the error code is exactly the registered string, that money is an integer, that no forbidden field appears.

**Every response the frontend consumes gets a zod schema.** A shape mismatch then fails at the fetch boundary with a readable message naming the field, rather than three components later as `undefined is not an object`.

Those last two are the mechanism that actually catches drift. The prose in the contract explains intent; the test and the schema are what fail when intent and code disagree.

**The three never exposed fields get a dedicated test.** One Pest test asserting that no serialiser anywhere emits a confidence score, a verification photograph path, or a product creator field. A single careless resource class breaks that guarantee, and it is not the kind of thing a screen review catches.

---

## 9. Definition of done for a milestone

A milestone is done when **all** of these hold. Not most of them.

- [ ] Backend endpoints implemented, with Pest tests covering the milestone's stated test list
- [ ] Envelope, error codes, and money asserted in tests, not just eyeballed
- [ ] Seeded data sufficient for the frontend to exercise empty, populated, and edge states
- [ ] Backend milestone log entry appended, deviations recorded
- [ ] Shared folder copied across and both sync checks green
- [ ] Frontend screens built against the real API, with loading, empty, error, and blocked states
- [ ] Frontend zod schemas match the shipped responses
- [ ] Frontend milestone log entry appended
- [ ] The milestone's demonstration flow walked end to end by hand

Do not begin the next milestone before this list is complete. The build order exists because each milestone depends on records the previous one creates, and a half finished milestone leaves the next one building on data that does not exist.

---

## 10. Local environment both sides assume

| Service | Purpose | Used from |
|---|---|---|
| PostgreSQL 16 with PostGIS | Primary database, distance calculation, JSONB | Backend only |
| Redis 7 | Cache and queue | Backend only |
| Meilisearch | Product search and the keyword fallback path | Backend only |
| Mailpit or similar | Catching outbound SMTP in development | Backend, inspected by hand |

The frontend talks to **nothing** on this list. It talks to the Laravel API and nothing else. If a frontend task appears to need a database, a cache, or a search index, the task has been misread.

The backend runs on a known local URL, and the frontend's `NEXT_PUBLIC_API_URL` and `API_URL` point at it. Both must be running for any frontend milestone from M1 onward.

An AI provider key is needed for the five AI call types. Build a **fake adapter behind the same provider interface first**, so the platform can be developed and tested without provider calls, then swap it by configuration. The fake adapter also needs a deliberate failing mode, because that is the only way to exercise the `ai_unavailable` path and the keyword search fallback. Note that this is not a mock of the API. It is a test double for one external vendor, behind an interface the application owns.
