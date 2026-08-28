# API Contract

**Contract version:** 9
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
| `proposal_not_escalated` | 409 | Administrator resolution attempted on a proposal that is not escalated |
| `proposal_not_resolved` | 409 | Administrator override attempted on a proposal that has not resolved yet |
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
| Public catalogue reads, and EP-52 view recording | 120 per minute per IP |
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
| EP-51 | `POST /api/revalidate` | Shared secret header |

EP-51 is the odd one out. It is **hosted by the frontend** as a Next.js route handler and **called by the backend** from a queued job whenever a product version is created. It authenticates with an `x-revalidate-secret` header, not a bearer token.

The path is on the frontend origin, not this API. With the client at `http://localhost:3000` the full URL is `http://localhost:3000/api/revalidate`.

**Request.** The header carries the shared secret. The body carries the slug and nothing else, because the handler derives both paths it rebuilds from the slug.

```
x-revalidate-secret: <the shared secret>
```

```json
{ "slug": "vertex-one-smartphone" }
```

**Response.** 200 on success, in the ordinary envelope.

```json
{ "data": { "revalidated": true, "slug": "vertex-one-smartphone" } }
```

**Refusals.** 401 `unauthenticated` when the header is absent or does not match. 422 `validation_failed` when the body carries no slug. 500 `misconfigured` when the client itself holds no secret to compare against, which is a deployment fault rather than a caller fault and is why it is not a 401.

**What fires it.** A product version, and nothing else. A rejected proposal, a failed proposal, a price edit, a wishlist change, and a page view all fire nothing, because none of them writes a version. The backend dispatches it after the transaction commits, so a slow or unreachable client never fails the request that created the version, and a version that rolls back never triggers a rebuild.

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

Saved at **variant level, not product level**, because a price alert is only meaningful for a specific combination. `lowest_price_minor` is the cheapest available listing for that variant right now and is **null when nobody carries it**, which is a normal state rather than an error: a buyer may save a combination no seller stocks yet, and being told when one appears is the point. **`currency` is null alongside it**, on the same rows and for the same reason: with no listing there is nothing to take a currency from. The two are always null together and never one without the other.

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

### 11.10 Community and verification

**Posting requires ownership of the product being discussed**, proven by photograph, and proven **per product**. Verification of one product grants nothing on another. This is the whole reason the community is worth reading: every participant has demonstrably held the thing they are talking about.

**The post**, returned by EP-31 and EP-57. Both use cursor pagination per section 2, not page numbers.

```json
{
  "id": 412,
  "body": "The battery lasts about two days on light use.",
  "author": { "name": "Nadia" },
  "reply_count": 3,
  "created_at": "2026-08-27T09:00:00Z"
}
```

The author is a **display name only**. There is no user id, no email, and no store: a user who happens to run a store posts as a verified buyer like anyone else, and naming their store here would turn a discussion into advertising.

There is no `is_verified` flag on a post, because an unverified author cannot post at all. A flag whose value is always true is a field that will eventually be false by accident.

`reply_count` is present on top level posts so a client knows whether to offer EP-57. Replies do not nest further: a reply carries `reply_count: 0` always, and no endpoint accepts a reply to a reply.

**Soft deleted posts are absent entirely**, and so are their replies. A post removed by an administrator does not appear as a tombstone, and its thread does not survive it.

**EP-32 creates a post.**

```json
{ "body": "Mine has the same rattle.", "parent_id": 412 }
```

`body` is required. `parent_id` is optional: omitted for a top level post, set to a top level post's id for a reply. A `parent_id` naming a reply is refused, because threads are one level deep.

Refused with **403 `not_verified`** when the caller has not verified ownership of that product. The check is against this product, always.

**EP-33 is the state the composer renders from**, and it carries enough to answer every case without the client inferring anything.

```json
{
  "data": {
    "is_verified": false,
    "attempts_used": 2,
    "attempts_remaining": 3,
    "can_attempt": true,
    "latest_outcome": "failed",
    "pending_code": null,
    "pending_job_id": null
  }
}
```

`attempts_used` counts every concluded attempt for this user on this product. The ceiling is **five, per user per product**, and `attempts_remaining` is what is left of it. `can_attempt` is false once the ceiling is reached or the caller is already verified, and it is a rendering hint: EP-34 and EP-35 re-check and refuse with **403 `attempts_exhausted`** regardless of what the client believed.

`latest_outcome` is `passed`, `failed`, `pending`, or null when nothing has been attempted. `pending_code` is set when a verification has been started but not yet submitted, so a buyer who closed the page can be shown the code again rather than burning an attempt to get a new one. `pending_job_id` is set when a submission is queued behind a provider failure, and is what the queued job panel resumes from.

**EP-34 starts an attempt** and issues the code the buyer must photograph.

```json
{ "data": { "code": "VX-7T2K", "attempts_remaining": 3, "expires_at": "2026-08-27T10:00:00Z" } }
```

The buyer writes the code on paper, photographs it beside the product, and submits that photograph. The code is what makes the photograph evidence of present possession rather than an image found online. **Starting does not consume an attempt**; submitting does, so a buyer who starts and walks away loses nothing.

**EP-35 submits the photograph**, as `multipart/form-data` carrying `photo`. Accepts JPEG, PNG, or WebP up to 5 MB, refused with `unsupported_media_type` or `file_too_large` as in section 7.

```json
{ "data": { "outcome": "passed", "reason": "The code matches and the product is visible.", "attempts_remaining": 2 } }
```

`outcome` is `passed` or `failed`. A failure is an ordinary outcome, not an error, and answers **200 rather than 4xx**: the request succeeded, and the answer was no.

**The photograph is deleted the moment verification concludes, whether it passed or failed.** No response on any endpoint at any access level contains a photograph path, a URL, or the file itself. Section 6 lists this alongside the confidence score. `reason` is retained after the photograph is destroyed, so a failure can still be explained to the buyer who is deciding whether to spend another attempt.

Provider failure follows section 8: **503 with `ai_unavailable` and a top level `queued_job_id`**. The photograph survives only until the queued job concludes, which then deletes it on the same terms.

### 11.11 Analytics and version history

Two seller facing reads and one public write. They answer different questions about the same catalogue: how many people looked, and how the record got to be the way it is.

**EP-52 records a product page view.** It is public, so it resolves no session and records no user.

```json
{ "store_id": 4 }
```

`store_id` is **optional** and names the store the visitor arrived through, which is the only store context a product page has. Sent when the visitor reached the page from that store's own page or from its entry in the seller list, and omitted otherwise. A view with no store context is still recorded and still counts at product level; it simply appears in no store's analytics.

Answers **201**:

```json
{ "data": { "recorded": true, "store_id": 4 } }
```

**A `store_id` naming a store that does not carry this product is recorded as `null` rather than refused**, and the response says so by echoing back what was actually attributed. A seller detaching between the page rendering and the view being posted is an ordinary race, and answering 422 into a public page render would turn that race into a visible error for a visitor who did nothing wrong. Dropping only the attribution keeps the view itself, which did happen.

Because it is one call per product page render, EP-52 sits behind the public catalogue limiter rather than a limiter of its own.

**EP-39 is the seller's own view counts**, over a date range given as `from` and `to` query parameters in `YYYY-MM-DD`. Both are optional and default to the last thirty days ending today. Days are **UTC days**, matching section 5, and the range may not exceed 366 of them.

```json
{
  "data": {
    "from": "2026-07-30",
    "to": "2026-08-28",
    "store_views": 40,
    "product_views": 312,
    "daily": [
      { "date": "2026-07-30", "store_views": 2, "product_views": 11 }
    ],
    "products": [
      {
        "id": 7,
        "slug": "vertex-one-smartphone",
        "name": "Vertex One Smartphone",
        "store_views": 12,
        "product_views": 90,
        "is_carried": true
      }
    ]
  }
}
```

**Two counts, and the difference between them is the point.** `store_views` are views attributed to this store. `product_views` are all views of the same products, whoever they were attributed to. A single number with nothing to compare it against says very little; the pair says how much of the interest in a product reached this particular seller.

`daily` covers **every date in the range, including days with no views at all**, so a chart has no gaps to fill in. `products` lists every product this store has a view for in the range, plus every product it currently carries, so a listing with no views appears as a zero rather than vanishing. `is_carried` is false for a product the store has since detached from, whose historical views remain counted. Both totals are the sum of the `products` rows.

**EP-46 is the version chain**, newest first, paginated per section 2.

```json
{
  "version_number": 3,
  "created_at": "2026-08-27T09:00:00Z",
  "is_admin_originated": false,
  "caused_by_store": { "id": 4, "name": "Colombo Audio" },
  "changed_fields": ["specifications"]
}
```

`changed_fields` names the top level parts of the snapshot that differ from the version before, from `name`, `slug`, `description`, `category`, `specifications`, `attributes`, and `variants`. It is an **empty array on version 1**, which created the record rather than changing it.

`caused_by_store` is the store whose accepted proposal produced this version, and is **null on an administrator edit**, where `is_admin_originated` is true instead. **No administrator is ever named.** Attribution for a change that was applied to a shared record is what an audit trail is for, but naming the moderator who applied it serves no seller and gives a disgruntled one a target.

**There is no proposal id here**, deliberately. EP-29 answers 404 to any store that was neither the proposer nor a frozen reviewer, which is most of the audience for this list, so the id would be a link that mostly does not open.

**A rejected proposal produces no row at all** and is absent entirely. The chain records what the product became, not what was argued about, and a proposal's own fate is EP-27's business.

**EP-47 is one version**, the same fields plus the full snapshot.

```json
{
  "version_number": 3,
  "created_at": "2026-08-27T09:00:00Z",
  "is_admin_originated": false,
  "caused_by_store": { "id": 4, "name": "Colombo Audio" },
  "changed_fields": ["specifications"],
  "snapshot": {
    "name": "Vertex One Smartphone",
    "slug": "vertex-one-smartphone",
    "description": "...",
    "category": "Phones",
    "specifications": { "Battery": "5200 mAh" },
    "attributes": [{ "name": "Colour", "options": ["Black"], "position": 0 }],
    "variants": [{ "attribute_values": { "Colour": "Black" }, "combination_hash": "...", "is_default": true }]
  }
}
```

The snapshot is the **complete record state at that version**, not a diff, so reading one version costs one row rather than replaying the chain. There is no rollback endpoint and none is planned: history is read only, and an administrator wanting an old value edits forward through EP-43, which writes a further version.

**Both version endpoints are refused to anonymous callers with 401**, and access is re-read on **every request** rather than cached anywhere:

| Caller | Answer |
|---|---|
| Anonymous | 401 `unauthenticated` |
| Authenticated, no store, not an administrator | 403 `store_required` |
| Holds a store that does not carry this product | 403 `not_attached` |
| Holds a store carrying this product | 200 |
| Administrator | 200, store or no store |

**Holding a store is not enough.** A seller who carries forty other products still gets `not_attached` on the one they do not, because the history is a working document for the sellers responsible for that record rather than a catalogue wide privilege. A seller who detaches loses access on their **next request**, mid session, with no grace period.

### 11.12 Administration

The administrator surface exists for one reason above all others: **a proposal that escalates blocks the seller who wrote it, and nothing else in the platform can unblock them.** Everything else here is secondary to that.

Administrators are a flag on a user, not a separate identity. There is no administrator account type, no roles array, and an administrator holds a wishlist and may run a store like anybody else.

**Nothing here exposes a confidence score or band**, at any access level, including to an administrator resolving an escalation. Section 6 has no exceptions and this is not one. An administrator deciding a disagreement between a seller and the incumbents should decide it on the evidence in front of them, and the AI's number would anchor that decision exactly as it would anchor a reviewer's vote.

**The proposal list item**, returned by EP-40 and EP-58. Both paginate per section 2.

```json
{
  "id": 77,
  "status": "escalated",
  "resolution_reason": "tie_no_majority",
  "review_opens_at": "2026-08-20T09:00:00Z",
  "review_closes_at": "2026-08-23T09:00:00Z",
  "resolved_at": "2026-08-23T09:05:00Z",
  "changed_fields": ["Battery"],
  "product": { "id": 12, "slug": "vertex-one-smartphone", "name": "Vertex One Smartphone" },
  "store": { "id": 4, "name": "Colombo Audio" },
  "votes_cast": 2,
  "votes_in_favour": 1,
  "votes_against": 1,
  "reviewer_count": 3
}
```

**This names the proposing store, where EP-29 does not.** The asymmetry is deliberate. A reviewer is asked to judge whether a claim about a product is right, and telling them which competitor made it invites voting on the seller instead. An administrator settling an escalation is doing the opposite job: they need to know who is blocked, for how long, and what the reviewers actually said.

`resolution_reason` is the coded reason the matrix recorded: `high_confidence_peers_favour`, `high_confidence_peers_against`, `low_confidence_peers_favour`, `low_confidence_peers_against`, `no_votes_cast`, or `tie_no_majority`. It is null on a proposal still pending.

`votes_in_favour` and `votes_against` are the split, which reviewers never see and an administrator always needs. They sum to `votes_cast`, and `reviewer_count` is the frozen reviewer set, so the gap between the two is the reviewers who said nothing. **Non voters are excluded rather than counted as opposed**, which is why a proposal can be approved on one vote out of five.

**EP-40 returns escalated proposals only, oldest first**, ordered by `review_opens_at` ascending. That order is the queue's whole purpose: the row at the top is the seller who has been blocked longest. **EP-58 returns every proposal**, newest first, and accepts `?status=` with one of `pending`, `approved`, `rejected`, `escalated`.

**The proposal detail**, returned by EP-59. The list item plus the three things a decision needs.

```json
{
  "id": 77,
  "status": "escalated",
  "resolution_reason": "tie_no_majority",
  "review_opens_at": "2026-08-20T09:00:00Z",
  "review_closes_at": "2026-08-23T09:00:00Z",
  "resolved_at": "2026-08-23T09:05:00Z",
  "product": { "id": 12, "slug": "vertex-one-smartphone", "name": "Vertex One Smartphone" },
  "store": { "id": 4, "name": "Colombo Audio" },
  "changes": [{ "attribute": "Battery", "from": "4500 mAh", "to": "5200 mAh" }],
  "votes": [
    {
      "store": { "id": 5, "name": "Pettah Gadgets" },
      "vote": "approve",
      "comment": "Mine says 5200 mAh on the cell itself.",
      "cast_at": "2026-08-21T11:00:00Z"
    }
  ],
  "intended_listing": { "variant_ids": [55], "price_minor": 429900, "currency": "LKR" },
  "votes_cast": 2,
  "votes_in_favour": 1,
  "votes_against": 1,
  "reviewer_count": 3,
  "resolved_by": { "id": 2, "name": "A. Administrator" }
}
```

`changes` is an array in review order, exactly as section 11.8 describes it, and `from` is null where the record held nothing.

`votes` carries each reviewer's comment, which is the most useful thing on the screen: the comments are the argument the administrator is being asked to settle. A reviewer who did not vote is simply absent from the array.

**`intended_listing` is what approval will create.** No attachment row exists while a proposal blocks a seller, so this is the listing being withheld, and an administrator should be able to see what they are about to release before releasing it. `variant_ids` name combinations of the proposal's own product.

`resolved_by` names the administrator who settled it, and is **null until one has.** Administrators are named to other administrators here and **to nobody else**, which is the same rule section 11.11 states from the other side: a version never names the administrator who caused it.

**EP-41 resolves an escalation.** It is the only thing in the platform that unblocks a seller whose proposal escalated.

```json
{ "decision": "approve" }
```

`decision` is `approve` or `reject` and is required. Nothing else is accepted: a proposal is taken or left **as a whole**, per invariant 4, so there is no per field decision here and no endpoint accepts one.

Refused with **409 `proposal_not_escalated`** when the proposal is in any other state, which includes the ordinary race of two administrators working the same queue.

**EP-42 overrides a proposal that has already resolved**, reversing what the peers or the matrix decided.

```json
{ "decision": "reject" }
```

Refused with **409 `proposal_not_resolved`** when the proposal is still pending or is escalated. An escalated proposal has not been decided by anyone yet, so there is nothing to override; that is EP-41's job.

Both answer the same shape:

```json
{
  "data": {
    "proposal_id": 77,
    "status": "approved",
    "resolved_at": "2026-08-28T10:15:00Z",
    "version_number": 4,
    "attachments_created": 1,
    "seller_unblocked": true
  }
}
```

`version_number` is the version this decision wrote, and is **null where it wrote none**. `attachments_created` is `0` on a rejection.

**`seller_unblocked` is true on both outcomes of EP-41, and that is the point of the field.** Approval and rejection unblock the proposing seller equally: what blocked them was an unresolved proposal, not an unfavourable one. A rejected seller keeps no listing and gets no version, and is free to start a fresh attempt immediately. Interface copy that describes rejection as leaving them blocked is wrong.

**What each decision does to the record:**

| Endpoint | Decision | Version | Attachment | Record |
|---|---|---|---|---|
| EP-41 | approve | written, attributed to the proposing store | the withheld listing is created | changes applied |
| EP-41 | reject | none | none | untouched |
| EP-42 | approve, on a rejected proposal | written, attributed to the proposing store | the withheld listing is created | changes applied |
| EP-42 | reject, on an approved proposal | **a further version**, administrator originated | **left alone** | reversible fields restored |

**A version written by EP-41 or by EP-42 approving is an ordinary proposal version.** `caused_by_store` names the proposing store and `is_admin_originated` is false, because the change is the seller's and an administrator only decided it. Section 11.11 promises sellers that `caused_by_store` names whoever's proposal produced a version, and an escalation settled in their favour is exactly that.

**Reversing an approval writes a further version and deletes nothing.** The record moves forward to a state resembling the one before the approval; it does not move backwards, and no version is removed from the chain. Two things survive a reversal deliberately:

- **Attribute options the approval added, and every combination generated from them.** Invariant 2 forbids removing a combination, by anyone, including an administrator. A reversal that stranded generated combinations could never be cleaned up.
- **The proposing seller's attachment.** Reversing a claim about what a product *is* says nothing about whether that shop stocks it.

**EP-43 edits a record directly.** The one path into product data that is not a proposal, and it exists because some corrections have nobody to propose them.

```json
{
  "name": "Vertex One Smartphone",
  "description": "...",
  "category": "Mobile",
  "specifications": { "Battery": "5200 mAh", "Display": "6.1 inch OLED" },
  "attributes": [{ "name": "Colour", "options": ["Black", "Grey", "Sand"] }]
}
```

Every field is optional and at least one must be present. **`slug` is not accepted**: it is the record's public address, every static page and every inbound link is keyed by it, and a rename would break all of them for a cosmetic gain.

`specifications` **replaces** the map wholesale when present, so a key omitted from it is removed. A specification is a free form fact about the record and carries nothing that depends on it.

`attributes` is **additive and merges by name**. An option list is widened, never narrowed: sending `["Black", "Sand"]` against a record holding `["Black", "Grey"]` produces `["Black", "Grey", "Sand"]`. Widening generates the combinations the new options make possible and **leaves every existing combination and every existing attachment exactly as it was**, so a shop carrying Black keeps carrying Black.

**Naming an attribute the record does not already define is refused with `validation_failed`.** Adding a new dimension to a record that already has combinations would leave every one of them missing a value for it, permanently, since nothing can remove a combination. The wizard is where a product's attribute set is decided.

EP-43 writes an **administrator originated version**: `is_admin_originated` is true, `caused_by_store` is null, and the acting administrator is recorded server side and named to nobody. It answers the updated record in the EP-61 shape.

**A pending proposal on the same product does not block an administrator edit**, and the edit does not disturb the proposal. The proposal still applies its own values if it is later approved. The two are independent, and making an administrator wait three days for a peer review to conclude before fixing an obvious error would be the wrong trade.

**EP-44 removes a community post.**

```json
{ "data": { "deleted": true, "replies_hidden": 3 } }
```

**Soft deleted, never removed.** The row survives, and every read path already hides it: the post vanishes from the thread, and so do its replies, which is what `replies_hidden` counts. There is no tombstone anywhere and no "removed by an administrator" placeholder, per section 11.10. Deleting a reply hides that reply alone and reports `replies_hidden: 0`.

There is no endpoint that restores a post, and none is planned.

**EP-49 removes an image from a record.** Administrator only, and it is the only deletion path for one: a seller may add an image through EP-48 and may never remove one, because an uploader who could remove an image could remove one a later seller relies on.

```json
{ "data": { "deleted": true, "images_remaining": 2 } }
```

The file is removed from storage as well as the row. It is keyed by product **slug** like every other product route in the public group, not by id.

**EP-45 is the platform at a glance.**

```json
{
  "data": {
    "products": { "total": 5, "with_sellers": 3, "without_sellers": 2 },
    "stores": { "total": 6, "live": 5, "dark": 1 },
    "proposals": { "pending": 2, "escalated": 1, "approved": 4, "rejected": 1 },
    "community": { "posts": 14, "verified_users": 7 },
    "views": { "last_7_days": 312, "last_30_days": 1204 },
    "oldest_escalation_opened_at": "2026-08-20T09:00:00Z"
  }
}
```

`oldest_escalation_opened_at` is **null when nothing is escalated**, and is the one figure on this endpoint that names an obligation rather than a fact: while it is set, a seller is blocked and waiting. `views` counts UTC days, matching section 11.11.

**The administrator product list**, returned by EP-60, paginated per section 2 and accepting `?q=` and `?category=`.

```json
{
  "id": 12,
  "slug": "vertex-one-smartphone",
  "name": "Vertex One Smartphone",
  "category": "Mobile",
  "seller_count": 5,
  "variant_count": 6,
  "image_count": 3,
  "current_version_number": 3,
  "has_pending_proposal": true
}
```

`has_pending_proposal` covers pending **and** escalated, because both mean a seller is blocked on this record and an administrator editing it should know.

**The administrator product detail**, returned by EP-61 and by EP-43.

```json
{
  "id": 12,
  "slug": "vertex-one-smartphone",
  "name": "Vertex One Smartphone",
  "description": "...",
  "category": "Mobile",
  "specifications": { "Battery": "5200 mAh" },
  "attributes": [{ "id": 4, "name": "Colour", "options": ["Black", "Grey"], "position": 0 }],
  "variants": [
    { "id": 88, "attribute_values": { "Colour": "Black" }, "is_default": false, "seller_count": 2 }
  ],
  "images": [{ "id": 7, "url": "...", "mime_type": "image/jpeg", "position": 0 }],
  "current_version_number": 3,
  "seller_count": 5,
  "has_pending_proposal": true
}
```

**Every generated combination appears, including ones no seller carries**, exactly as section 11.5 requires of the public shape. An administrator screen that hid them would be the first place somebody got the idea a combination can be removed.

**`created_by_store_id` is absent here as everywhere else**, per section 6. Administrators are not an exception to the three never exposed fields; the record is platform owned and there is no reader for whom that stops being true.

---

## 12. Change log

| Version | Date | Change |
|---|---|---|
| 1 | 2026-08-25 | Initial contract, written before M0. Sections 1 to 11 established |
| 2 | 2026-08-27 | M5. Added `search_interpretation` to `result_type` in section 8, which seller catalogue search has emitted since M3 and the list omitted. Stated that `result_type` is null until a job completes, and that another user's job answers 404. Added section 11.7, the wizard submit outcome |
| 3 | 2026-08-27 | M6. Added `confirmation_outcome` to `result_type` in section 8, which a queued confirmation submit completes as. Section 11.4 is unchanged and is what EP-22 returns |
| 4 | 2026-08-27 | M7. Added section 11.8, the proposal list item, the detail with its change comparison, and the vote request body. EP-27 and EP-28 paginate per section 2. No existing shape changed |
| 5 | 2026-08-27 | M8. Added section 11.9, the attachment update request and response, the detach response carrying `store_is_live`, the wishlist item, and the wishlist add request. EP-36 paginates per section 2. Recorded that a repeated wishlist add answers the existing item rather than failing. No existing shape changed |
| 6 | 2026-08-27 | M9. Added section 11.10, the community post, the post creation request, and the three verification shapes. EP-31 and EP-57 use cursor pagination per section 2. Clarified that a wishlist item's `lowest_price_minor` and `currency` are null together when nobody carries the variant, closing the M8 open request. `verification_result` and the `not_verified` and `attempts_exhausted` codes were already registered and are unchanged |
| 7 | 2026-08-28 | M10. Added section 11.11, the view recording request and response, the seller analytics shape, and the two version history shapes. EP-46 paginates per section 2. Recorded in section 9 that EP-52 shares the public catalogue limiter. No new error codes: `not_attached` and `store_required` were registered in section 7 since version 1 and are reachable from the version endpoints for the first time now. No existing shape changed |
| 9 | 2026-08-28 | M12. **Corrected EP-51's path from `/api/internal/revalidate` to `/api/revalidate`.** The table was the only place the `internal` segment ever appeared: the frontend build plan specifies `app/api/revalidate/route.ts`, the client has hosted the handler there since M0, and M0's own verification step calls `/api/revalidate`. The contract was describing a path nothing had ever served. Added the request, response, and refusal shapes for EP-51, and recorded that a version creation is the only event that fires it. No other shape changed, and no new error code: `unauthenticated` and `validation_failed` have been registered in section 7 since version 1 |
| 8 | 2026-08-28 | M11. Added section 11.12, the administrator proposal list and detail, the resolve and override request and shared response, the direct edit request, the post and image deletion responses, the metrics shape, and the two administrator product shapes. EP-40, EP-58, and EP-60 paginate per section 2. Registered `proposal_not_escalated` and `proposal_not_resolved` in section 7. Recorded that an administrator resolving an escalation writes an ordinary proposal version attributed to the proposing store, that reversing an approval writes a further version and removes nothing, and that EP-59 names the proposing store where EP-29 hides it. No existing shape changed |
