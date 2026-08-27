# API Contract

**Contract version:** 5
**Owner:** the backend repository
**Status:** authoritative

---

## 0. How this file works

This file is **byte identical in both repositories**, at `development-docs/shared/api-contract.md`. It is the single source of truth for everything that crosses the wire between the Laravel API and the Next.js client.

- The **backend owns it**. A shape change starts here, not in code.
- Changing it means: edit the backend copy, bump **Contract version** at the top, add a line to section 12, copy the file to the frontend repository, and commit both.
- Both repositories check the copies match. See `integration-protocol.md`.
- The frontend **never invents a shape**. If something it needs is missing, it records a request in `milestone-log.md` rather than guessing.

---

## 1. Response envelope

Successful responses wrap their payload in `data`:

```json
{ "data": { "id": 12, "name": "Example" } }
```

Errors always use the same three field envelope. `errors` is present only for validation failures:

```json
{
  "code": "validation_failed",
  "message": "The given data was invalid.",
  "errors": { "email": ["The email has already been taken."] }
}
```

**Clients branch on `code`, never on `message`.** Code values are fixed and must not be reworded. Messages may change freely.

204 responses carry no body at all.

---

## 2. Pagination

Every list endpoint returns Laravel's length aware paginator shape alongside `data`:

```json
{
  "data": [],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "...",
    "per_page": 20,
    "to": 20,
    "total": 94
  }
}
```

`per_page` is capped at 100. The seller list paginates at 20. Community posts use cursor pagination on `created_at` instead, returning a `meta.next_cursor` that is null at the end.

The frontend's shared `Paginated<T>` type models exactly this. Do not add a second list shape.

---

## 3. Access levels

| Level | Middleware | Rule |
|---|---|---|
| Public | none | Works with no token, and **does not change behaviour when a token happens to be present** |
| Auth | `auth:sanctum` | Any authenticated user |
| Seller | `auth:sanctum` plus a store check | 403 `store_required` when the caller holds no store |
| Admin | `auth:sanctum` plus an admin check | 403 `forbidden` when `is_admin` is false |

The seller check reads the `stores` table, not a role column. A user is a seller if and only if a store references them. An administrator is a user whose `is_admin` is true. **There is no roles array anywhere in any payload.** The frontend derives both from `GET /api/user`.

Public catalogue routes bypass session resolution entirely.

---

## 4. Money

Every price crossing the boundary is an **integer in the smallest currency unit**, paired with an ISO 4217 code:

```json
{ "price_minor": 129900, "currency": "LKR" }
```

The API never emits a decimal price and never accepts one. Division for display happens in the client only. A field holding money is always named with the `_minor` suffix.

---

## 5. Timestamps

ISO 8601 in UTC, for example `2026-08-25T14:30:00Z`. The client formats for display. The API never sends a pre-formatted date string.

---

## 6. Never exposed

Three things must never appear in any response body, on any endpoint, at any access level.

| Item | Reason |
|---|---|
| `confidence_score` and `confidence_band` | They drive the resolution matrix server side. Exposing them would anchor reviewer votes on the AI's assessment |
| Verification photograph paths or URLs | Photographs are deleted once verification concludes and are never displayed |
| `created_by_store_id` on a product | Historical attribution only. Exposing it would imply a seller owns the record |

The backend asserts this against every serialiser in a test. The frontend keeps these fields out of its type definitions entirely, so a careless addition fails to compile.

---

## 7. Error code registry

Every code the API can return. **Add a row here before returning a new code from code.**

| Code | HTTP | Meaning |
|---|---|---|
| `validation_failed` | 422 | Request failed validation. `errors` present |
| `unauthenticated` | 401 | Missing, invalid, or expired token |
| `forbidden` | 403 | Authenticated but not permitted |
| `not_found` | 404 | Resource does not exist, or is a dark store, which is the same thing to a buyer |
| `store_required` | 403 | Seller level route, caller holds no store |
| `store_exists` | 409 | Caller already holds a store. One user, one store |
| `proposal_pending` | 409 | Caller has a pending proposal blocking this product |
| `already_attached` | 409 | Caller already carries this product |
| `confirmation_incomplete` | 422 | Not every confirmation question was answered |
| `match_required` | 422 | Wizard called while match candidates were outstanding |
| `already_voted` | 409 | This store already voted on this proposal |
| `review_closed` | 409 | The three day review window has closed |
| `not_eligible_to_vote` | 403 | Store was not attached when the proposal opened |
| `not_attached` | 403 | Version history requested by a seller not carrying this product |
| `not_verified` | 403 | Community post attempted without verified ownership of that product |
| `attempts_exhausted` | 403 | Five verification attempts used for this product |
| `unsupported_media_type` | 422 | Upload was not JPEG, PNG, or WebP |
| `file_too_large` | 422 | Upload exceeded 5 MB |
| `image_limit_reached` | 422 | Product already holds eight images |
| `ai_unavailable` | 503 | AI provider failed. Work is queued. See section 8 |
| `rate_limited` | 429 | Named limiter tripped. `Retry-After` header present |

---

## 8. AI unavailability

Any endpoint that calls the AI provider handles provider failure identically: queue the work, return **503** with code `ai_unavailable`, and include `queued_job_id` **at the top level of the body**, not inside `data`.

```json
{
  "code": "ai_unavailable",
  "message": "AI service is currently unavailable. Your submission has been saved.",
  "queued_job_id": "9f2c1a80-..."
}
```

The client polls `GET /api/jobs/{id}` on a backoff from 2 seconds widening to 15, persists the job id to `localStorage`, and resumes the flow from the job result.

**The single exception is buyer search** (`GET /api/search`), which never returns this code. It falls back to keyword results and returns 200 with `mode` set to `keyword`. Seller catalogue search is not an exception and does return 503, because a degraded seller search could admit a duplicate canonical record.

Job status payload:

```json
{
  "data": {
    "id": "9f2c1a80-...",
    "status": "queued | processing | completed | failed",
    "result_type": "match_candidates | wizard_questions | confirmation_questions | confirmation_outcome | verification_result | search_interpretation | null",
    "result": null
  }
}
```

`result_type` is **null until the job completes**, including on a job that failed. It names the flow the client is resuming, and there is nothing to resume until there is a result.

`confirmation_outcome` is what a queued confirmation submit completes as. Its result is the section 11.4 outcome, exactly as `POST /api/attach/confirm/submit` would have returned it. **It is not a confidence score**: the provider was asked for one, but what the client resumes from is the attach or proposal outcome, and the score itself reaches no response body.

A job is readable only by the user who created it. A job belonging to somebody else answers **404, not 403**, because distinguishing the two would confirm that an id is real.

---

## 9. Rate limits

Applied as named limiters. Exceeding one returns 429 with the standard envelope and a `Retry-After` header.

| Group | Limit |
|---|---|
| `login`, `password/*` | 5 per minute per IP |
| `register` | 3 per hour per IP |
| Public catalogue reads | 120 per minute per IP |
| `search` | 30 per minute per IP |
| `attach/*` | 20 per hour per store |
| `verification/submit` | 5 per product per user, matching the attempt ceiling |
| Authenticated writes | 60 per minute per user |

---

## 10. Endpoint index

Grouped by the milestone that ships them. The backend ships a milestone's endpoints **before** the frontend builds the screens that consume them.

### M1 Accounts

| EP | Method and path | Access |
|---|---|---|
| EP-01 | `POST /api/register` | Public |
| EP-02 | `POST /api/login` | Public |
| EP-03 | `POST /api/logout` | Auth |
| EP-04 | `GET /api/user` | Auth |
| EP-05 | `POST /api/password/forgot` | Public |
| EP-06 | `POST /api/password/reset` | Public |
| EP-07 | `PATCH /api/user/location` | Auth |
| EP-55 | `POST /api/email/verification-notification` | Auth |
| EP-56 | `GET /api/email/verify/{id}/{hash}` | Signed |

### M2 Catalogue read

| EP | Method and path | Access |
|---|---|---|
| EP-08 | `GET /api/products` | Public |
| EP-09 | `GET /api/products/{slug}` | Public |
| EP-10 | `GET /api/products/{slug}/variants` | Public |
| EP-11 | `GET /api/products/{slug}/sellers` | Public |
| EP-12 | `GET /api/products/{slug}/summary` | Public |
| EP-13 | `GET /api/stores/{id}` | Public |
| EP-53 | `GET /api/categories` | Public |

### M3 Search

| EP | Method and path | Access |
|---|---|---|
| EP-14 | `GET /api/search` | Public |
| EP-15 | `GET /api/seller/catalogue-search` | Seller |

### M4 Seller onboarding

| EP | Method and path | Access |
|---|---|---|
| EP-16 | `POST /api/stores` | Auth |
| EP-17 | `POST /api/stores/mine/pin` | Seller |
| EP-18 | `PATCH /api/stores/mine` | Seller |
| EP-54 | `GET /api/stores/mine` | Seller |

### M5 Wizard

| EP | Method and path | Access |
|---|---|---|
| EP-20 | `POST /api/attach/match` | Seller |
| EP-23 | `POST /api/attach/wizard/start` | Seller |
| EP-24 | `POST /api/attach/wizard/submit` | Seller |
| EP-48 | `POST /api/products/{slug}/images` | Seller |
| EP-50 | `GET /api/jobs/{id}` | Auth, own job only |

### M6 Confirmation and proposals

| EP | Method and path | Access |
|---|---|---|
| EP-19 | `GET /api/stores/mine/listings` | Seller |
| EP-21 | `POST /api/attach/confirm/start` | Seller |
| EP-22 | `POST /api/attach/confirm/submit` | Seller |

### M7 Peer review

| EP | Method and path | Access |
|---|---|---|
| EP-27 | `GET /api/proposals/mine` | Seller |
| EP-28 | `GET /api/proposals/to-review` | Seller |
| EP-29 | `GET /api/proposals/{id}` | Seller |
| EP-30 | `POST /api/proposals/{id}/vote` | Seller |

### M8 Listings and wishlist

| EP | Method and path | Access |
|---|---|---|
| EP-25 | `PATCH /api/attachments/{id}` | Seller, own only |
| EP-26 | `DELETE /api/attachments/{id}` | Seller, own only |
| EP-36 | `GET /api/wishlist` | Auth |
| EP-37 | `POST /api/wishlist` | Auth |
| EP-38 | `DELETE /api/wishlist/{id}` | Auth |

### M9 Community and verification

| EP | Method and path | Access |
|---|---|---|
| EP-31 | `GET /api/products/{slug}/community/posts` | Public |
| EP-32 | `POST /api/products/{slug}/community/posts` | Auth, verified |
| EP-33 | `GET /api/products/{slug}/verification` | Auth |
| EP-34 | `POST /api/products/{slug}/verification/start` | Auth |
| EP-35 | `POST /api/products/{slug}/verification/submit` | Auth |
| EP-57 | `GET /api/products/{slug}/community/posts/{id}/replies` | Public |

### M10 Analytics and versions

| EP | Method and path | Access |
|---|---|---|
| EP-39 | `GET /api/stores/mine/analytics` | Seller |
| EP-46 | `GET /api/products/{slug}/versions` | Seller attached, or Admin |
| EP-47 | `GET /api/products/{slug}/versions/{number}` | Seller attached, or Admin |
| EP-52 | `POST /api/products/{slug}/views` | Public |

### M11 Administration

| EP | Method and path | Access |
|---|---|---|
| EP-40 | `GET /api/admin/escalations` | Admin |
| EP-41 | `POST /api/admin/proposals/{id}/resolve` | Admin |
| EP-42 | `POST /api/admin/proposals/{id}/override` | Admin |
| EP-43 | `PATCH /api/admin/products/{id}` | Admin |
| EP-44 | `DELETE /api/admin/community/posts/{id}` | Admin |
| EP-45 | `GET /api/admin/metrics` | Admin |
| EP-49 | `DELETE /api/products/{slug}/images/{id}` | Admin |
| EP-58 | `GET /api/admin/proposals` | Admin |
| EP-59 | `GET /api/admin/proposals/{id}` | Admin |
| EP-60 | `GET /api/admin/products` | Admin |
| EP-61 | `GET /api/admin/products/{id}` | Admin |

### M12 Revalidation

| EP | Method and path | Access |
|---|---|---|
| EP-51 | `POST /api/internal/revalidate` | Shared secret header |

EP-51 is the odd one out. It is **hosted by the frontend** as a Next.js route handler and **called by the backend** from a queued job whenever a product version is created. It authenticates with an `x-revalidate-secret` header, not a bearer token.

---

## 11. Shapes that are easy to get wrong

These seven have caused, or would cause, a mismatch. Get them right once.

### 11.1 Search response

`mode` is **required, never optional**, and sits beside `data` at the top level rather than inside it.

```json
{
  "mode": "ai",
  "data": [],
  "links": {},
  "meta": {}
}
```

The backend is the single authority on which path served a query. The frontend reads `mode` and never attempts its own fallback, because a client side fallback would let the two layers disagree.

### 11.2 Seller list entry

```json
{
  "store": {
    "id": 4,
    "name": "...",
    "category": "...",
    "contact_email": "...",
    "contact_phone": null,
    "address_line": "...",
    "city": "...",
    "latitude": 6.9271,
    "longitude": 79.8612,
    "rating": 4.2
  },
  "variant_id": 88,
  "price_minor": 129900,
  "currency": "LKR",
  "is_available": true,
  "distance_km": 3.4
}
```

`distance_km` is **null**, not zero, when the caller supplied no coordinates. The client renders nothing rather than "0 km". Contact details are returned to anonymous callers, which is the purpose of the endpoint. Dark stores never appear.

### 11.3 Store creation with geocoding failure

Geocoding failure returns **201, not a 4xx**. The store is created and the submitted details are kept.

```json
{ "data": { "id": 7, "geocoding_failed": true, "latitude": null, "longitude": null } }
```

The client treats this as a routing signal into manual pin placement, and **must not style it as an error**.

### 11.4 Confirmation submit outcome

One endpoint, two outcomes, distinguished by a field rather than by a status code.

```json
{ "data": { "outcome": "attached", "attachment_ids": [12, 13] } }
```
```json
{ "data": { "outcome": "proposal_created", "proposal_id": 31, "review_closes_at": "2026-08-28T09:00:00Z" } }
```

No attachment is created alongside a proposal. The absence of an attachment row **is** the block on the proposing seller.

### 11.5 Variants

Every generated combination is returned, including those with a seller count of zero. Omitting them would silently reintroduce variant removal, which the design forbids.

```json
{ "id": 88, "attribute_values": { "Colour": "Black", "Size": "M" }, "is_default": false, "seller_count": 0, "lowest_price_minor": null }
```

### 11.6 Vote response

The vote response carries the post vote proposal status, so the screen shows the outcome directly rather than polling for it.

```json
{ "data": { "vote_recorded": true, "proposal_status": "approved", "resolved_at": "2026-08-26T10:15:00Z" } }
```

### 11.7 Wizard submit outcome

```json
{
  "data": {
    "product": { "id": 88, "slug": "aurora-field-recorder-fr-2", "current_version_number": 1 },
    "variants_generated": 6,
    "attachments_created": 1,
    "store_is_live": true
  }
}
```

`variants_generated` is the full cross product of every attribute option. `attachments_created` counts only the combinations the seller carries, so **the first will usually be larger than the second**. That gap is expected and is not an inconsistency to reconcile or to warn about on screen. The uncarried combinations exist, are permanent, and are shown on the product page as having no sellers yet.

`store_is_live` becomes true here, and this is the first flow in the platform that can turn it on.

---

### 11.8 Proposal shapes

A proposal is the only way a seller's knowledge reaches a canonical record. These are the three shapes the peer review screens read.

**Neither the confidence score nor the confidence band appears in any of them**, at any access level, including to the seller who wrote the proposal. Section 6 explains why: a reviewer who can see the AI's assessment votes on the assessment rather than on what they know about the product.

**The list item**, returned by EP-27 and EP-28. Both paginate per section 2.

```json
{
  "id": 77,
  "status": "pending",
  "review_opens_at": "2026-08-27T09:00:00Z",
  "review_closes_at": "2026-08-30T09:00:00Z",
  "resolved_at": null,
  "changed_fields": ["Battery"],
  "product": { "id": 12, "slug": "vertex-one-smartphone", "name": "Vertex One Smartphone" },
  "votes_cast": 1,
  "reviewer_count": 3,
  "has_voted": false
}
```

`reviewer_count` is the frozen reviewer set recorded when the proposal opened, not the number of stores carrying the product today. `votes_cast` counts votes actually cast, which is the denominator the resolution matrix uses: **a reviewer who does not vote is excluded rather than counted as opposed**. `has_voted` describes the calling store and is what lets EP-28 separate outstanding reviews from finished ones.

**The detail**, returned by EP-29. The list item plus the change comparison.

```json
{
  "id": 77,
  "status": "pending",
  "review_opens_at": "2026-08-27T09:00:00Z",
  "review_closes_at": "2026-08-30T09:00:00Z",
  "resolved_at": null,
  "product": { "id": 12, "slug": "vertex-one-smartphone", "name": "Vertex One Smartphone" },
  "changes": [
    { "attribute": "Battery", "from": "4500 mAh", "to": "5200 mAh" }
  ],
  "votes_cast": 1,
  "reviewer_count": 3,
  "has_voted": false,
  "is_mine": false,
  "can_vote": true
}
```

`changes` is an **array, not an object**, so the order the fields are reviewed in is the order they are displayed in. `from` is the record's current value and is null where the record held nothing. A proposal is accepted or rejected **as a whole**, so there is no per field state here and no endpoint accepts one.

`is_mine` is true for the proposing store, which may read its own proposal but never vote on it. `can_vote` is true only when the caller is in the frozen reviewer set, has not already voted, and the window is still open. It is a rendering hint: the endpoint re-checks all three and refuses regardless of what the client believed.

EP-29 answers **404** rather than 403 to a store that is neither the proposer nor a frozen reviewer. Which products a competitor is arguing about is not public.

**The vote request**, accepted by EP-30.

```json
{ "vote": "approve", "comment": "Mine says 5200 mAh on the cell itself." }
```

`vote` is `approve` or `reject` and is required. `comment` is optional and free text. A vote cannot be changed once cast: EP-30 answers `already_voted` on a second attempt from the same store.

---

### 11.9 Listing management and the wishlist

**The attachment update request**, accepted by EP-25.

```json
{ "price_minor": 429900, "is_available": false }
```

Both fields are optional and at least one must be present, so a seller can change a price without restating availability. `price_minor` is an **integer in the smallest currency unit and must be greater than zero**: a free listing is not a listing, and a negative price is not a discount. There is no `currency` field, because a store's currency is fixed when it registers and is not a per listing decision.

**Nothing else is accepted.** A seller may change what they charge and whether they have stock, and nothing about the product, the variant, or the attribute values. Those belong to the canonical record and reach it only through a proposal.

EP-25 answers the updated attachment:

```json
{
  "data": {
    "attachment_id": 901,
    "variant_id": 55,
    "product": { "id": 7, "slug": "vertex-one-smartphone", "name": "Vertex One Smartphone" },
    "attribute_values": { "Colour": "Black" },
    "price_minor": 429900,
    "currency": "LKR",
    "is_available": false
  }
}
```

**EP-26 detach** answers what the seller most needs to know afterwards:

```json
{ "data": { "detached": true, "store_is_live": false } }
```

`store_is_live` is recomputed from the remaining attachments before the response is built. It goes false when the seller has just removed their last listing, which is the moment their store stops being visible to buyers, and saying so here is what lets the interface warn rather than let them discover it later.

**Detaching does not remove the product.** The canonical record is platform owned and outlives every seller on it. A product whose last seller leaves stays at its own URL, keeps its variants and its version history, and simply reports no sellers. It is not deleted, hidden, or archived.

**The wishlist item**, returned by EP-36, which paginates per section 2.

```json
{
  "id": 14,
  "variant_id": 55,
  "attribute_values": { "Colour": "Black" },
  "product": { "id": 7, "slug": "vertex-one-smartphone", "name": "Vertex One Smartphone", "primary_image_url": null },
  "lowest_price_minor": 429900,
  "currency": "LKR",
  "seller_count": 3
}
```

Saved at **variant level, not product level**, because a price alert is only meaningful for a specific combination. `lowest_price_minor` is the cheapest available listing for that variant right now and is **null when nobody carries it**, which is a normal state rather than an error: a buyer may save a combination no seller stocks yet, and being told when one appears is the point.

**The wishlist add request**, accepted by EP-37:

```json
{ "variant_id": 55 }
```

Adding a variant already on the wishlist is **not an error**. The endpoint answers the existing item with 200 rather than creating a duplicate, because a buyer pressing save twice has expressed the same intent twice.

EP-38 deletes by the wishlist item's own id, not by variant id, and answers `{ "data": { "removed": true } }`.

**Alerts are email only**, like every other notification in this platform. Two exist:

- A **price drop**, when a seller lowers the price of a wishlisted variant. Only a decrease qualifies; raising a price notifies nobody. Repeat alerts are suppressed by the last price a buyer was notified at, so a seller moving a price up and down around a threshold does not generate an alert each time it falls.
- **Nearby availability**, when a store within a buyer's own radius lists a variant on their wishlist. It needs the buyer's coordinates, so a buyer who never shared a location receives this alert for nothing, which is the documented cost of declining location rather than a fault.

Neither alert has an endpoint. There is no notification surface in this platform to read them from.

---

## 12. Change log

| Version | Date | Change |
|---|---|---|
| 1 | 2026-08-25 | Initial contract, written before M0. Sections 1 to 11 established |
| 2 | 2026-08-27 | M5. Added `search_interpretation` to `result_type` in section 8, which seller catalogue search has emitted since M3 and the list omitted. Stated that `result_type` is null until a job completes, and that another user's job answers 404. Added section 11.7, the wizard submit outcome |
| 3 | 2026-08-27 | M6. Added `confirmation_outcome` to `result_type` in section 8, which a queued confirmation submit completes as. Section 11.4 is unchanged and is what EP-22 returns |
| 4 | 2026-08-27 | M7. Added section 11.8, the proposal list item, the detail with its change comparison, and the vote request body. EP-27 and EP-28 paginate per section 2. No existing shape changed |
| 5 | 2026-08-27 | M8. Added section 11.9, the attachment update request and response, the detach response carrying `store_is_live`, the wishlist item, and the wishlist add request. EP-36 paginates per section 2. Recorded that a repeated wishlist add answers the existing item rather than failing. No existing shape changed |
