# Development Docs

These documents are the authority on what this repository builds and in what order. **Read them before making any change.**

| Document | Covers | Owned by |
|---|---|---|
| [backend-build-plan.md](backend-build-plan.md) | What this repository is, the invariants, architecture rules, the M0 specification, and the M0 to M12 roadmap | This repository |
| [shared/api-contract.md](shared/api-contract.md) | The wire format: envelope, error codes, money, pagination, endpoint index, and the shapes that are easy to get wrong | **This repository.** The frontend mirrors it |
| [shared/integration-protocol.md](shared/integration-protocol.md) | How this repository and the frontend stay in step | Shared |
| [shared/milestone-log.md](shared/milestone-log.md) | The handover record. Append an entry when a milestone ships | Shared, both sides append |

## The shared folder

`development-docs/shared/` is **byte identical** in this repository and in `C:\MyApps\canonical-marketplace\frontend`. Not roughly the same. Identical, enforced by a hash comparison.

This repository **owns** `api-contract.md`. A shape change starts here, never in the frontend.

After changing anything under `shared/`:

```bash
cp -r development-docs/shared/. ../frontend/development-docs/shared/
```

Then commit in both repositories.

To verify the copies match:

```bash
php artisan test --compact --filter=SharedDocs
```

It also runs as part of `composer test`, and skips cleanly when the frontend repository is not present.

## How to use this folder

1. Read `backend-build-plan.md` in full before the first change of a session.
2. Read `shared/api-contract.md` before writing any controller, resource, or form request.
3. Find the current milestone in `shared/milestone-log.md`. Do not start a later milestone before the current one demonstrates.
4. Check section 3 of the build plan, the invariants, before changing anything that touches products, proposals, versions, or attachments.
5. Append a milestone log entry when the milestone's endpoints are done, **before** the frontend starts its half.

## Quick facts that catch people out

- This repository is a **React starter kit being used as an API service**. The Inertia side, Fortify screens, and passkey tables are out of scope. Leave them in place and expose no route to them.
- The frontend has **no mock API**. An endpoint that does not answer is a screen that cannot be built. Ship endpoints before screens, and seed data richly enough to exercise empty and edge states.
- Prices are integers in the smallest currency unit. The API never emits or accepts a decimal price.
- Three things never appear in any response: the confidence score, verification photograph paths, and a product's creating store.
- Geocoding failure returns **201, not a 4xx**. The store is created either way.
- Buyer search never returns `ai_unavailable`. It falls back to keyword results. Every other AI path queues and returns 503.
