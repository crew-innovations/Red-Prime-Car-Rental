# Red Prime CRM: Public Website API (v1)

**Status:** the API exists in the CRM and is tested, but **no website is connected to it yet**. There is no website code in this project. This document is the contract the website developer will build against.

- Base URL: `https://<crm-domain>/api/v1` (staging: `https://darkviolet-llama-836605.hostingersite.com/api/v1`)
- Format: JSON in and out. Send `Accept: application/json`.
- Authentication: none. The API only shows content that is published, and it only accepts enquiries.
- Contract mapping: M3 (website integration), built early. Trips & Packages is a change request.

## What the API does and does not do

| Does | Does not |
|---|---|
| Lists published Yacht Trips and Trips & Packages, with dates and seats left | Show anything unpublished, paused, archived or in a hidden website section |
| Accepts an enquiry or a booking request and creates a **lead** in the CRM | Create, confirm or pay for a booking |
| Calculates a price **estimate** on the server | Accept prices, totals or statuses from the browser (they are ignored) |
| | Expose internal notes, operators, customers, bookings or private files |

A request becomes a new lead (source "Website") in the **Yacht Trips** pipeline (yacht trips, packages) or the **Car Rental** pipeline (cars). Staff then follow the normal process: contact, quotation, booking.

## Catalogue endpoints (read-only)

Rate limit: 120 requests per minute per IP.

### GET /yacht-trips

Published, active yacht trips.

| Query | Type | Notes |
|---|---|---|
| `featured` | boolean | Only featured trips |
| `category` | string | Exact category |
| `per_page` | integer 1–50 | Default 12 |
| `page` | integer | Page number |

Response: `{ "data": [YachtTrip], "links": {…}, "meta": {…} }` (standard Laravel pagination).

### GET /yacht-trips/{slug}

One trip with full detail. `404` if it is not published or not active.

**YachtTrip fields:**

```json
{
  "id": 3,
  "type": "yacht_trip",
  "slug": "dubai-marina-sunset-cruise",
  "name": "Dubai Marina Sunset Cruise",
  "category": "Sunset",
  "badge": "Best seller",
  "featured": true,
  "summary": "Plain-text summary, up to 220 characters",
  "description_html": "<p>…</p>",            // detail only
  "duration_minutes": 120,
  "start_time": "17:30",
  "location": "Dubai Marina",
  "departure_marina": "Dubai Marina Yacht Club",
  "meeting_point": "Gate 2",
  "price": { "amount": 250, "child_amount": 150, "currency": "AED", "unit": "per_person" },   // unit: per_person | per_trip
  "guests": { "min": 1, "max": 25 },
  "included": ["Soft drinks", "Towels"],
  "excluded": ["Transfers"],
  "itinerary": [{ "time": "17:30", "title": "Boarding" }],        // detail only
  "add_ons": [{ "name": "Cake", "price": 200, "unit": "per_trip" }],  // detail only
  "mandatory_charges": [{ "name": "Marina fee", "amount": 20, "unit": "per_person" }], // detail only
  "requirements": "…", "instructions": "…", "terms": "…",       // detail only
  "images": { "cover": Image, "gallery": [Image] },              // gallery in detail only
  "next_departure": TripDeparture,
  "departures": [TripDeparture],                                 // detail only, up to 60
  "seo": { "title": "…", "description": "…" }                    // detail only
}
```

`TripDeparture`: `{ "id": 41, "date": "2026-10-23", "start_time": "17:30", "seats_left": 25, "available": true }`. `seats_left` is `null` when the trip has no seat limit.

Fields with no value are left out of the response.

### GET /packages

Published, active Trips & Packages.

| Query | Type | Notes |
|---|---|---|
| `featured` | boolean | Only featured packages |
| `category` | string | Category slug (from `/package-categories`) |
| `type` | string | Product type, e.g. "Desert safari" |
| `per_page` | integer 1–50 | Default 12 |
| `page` | integer | Page number |

### GET /packages/{slug}

One package with the full day-by-day itinerary. `404` if it is not published or not active.

**Package fields:**

```json
{
  "id": 1,
  "type": "package",
  "slug": "3-day-liwa-desert-safari",
  "name": "3-Day Liwa Desert Safari",
  "category": { "slug": "desert-safaris", "name": "Desert Safaris" },
  "product_type": "Desert safari",
  "badge": "New", "featured": true,
  "summary": "…",
  "description_html": "<p>…</p>",                         // detail only
  "duration": { "days": 3, "nights": 2, "label": "3 days / 2 nights" },
  "destinations": ["Dubai", "Liwa"],
  "start_location": "Dubai (hotel pick-up)",
  "meeting_point": "Hotel lobby, 07:00",                  // detail only
  "booking_mode": "departures",                           // departures = fixed dates; on_request = customer picks a date
  "booking_cutoff_days": 2,
  "guests": { "min": 2, "max": 8 },
  "price": {
    "unit": "per_person",                                 // per_person | per_package
    "amount": 2450, "sale_amount": null, "from_amount": 2450,
    "child_amount": null, "currency": "AED",
    "group_prices": [{ "min_guests": 4, "amount": 2200 }]
  },
  "itinerary": [{                                         // detail only
    "day": 1, "title": "Into the dunes", "description": "…",
    "destinations": ["Liwa"], "activities": ["Dune drive"], "included": ["Lunch"],
    "meals": ["lunch", "dinner"], "accommodation": "Desert camp", "image": "https://…"
  }],
  "itinerary_days": 3,
  "inclusions": ["…"], "exclusions": ["…"],               // exclusions detail only
  "activities": ["…"],
  "accommodation": "…", "meals": "…", "transport": "…", "requirements": "…", "terms": "…", // detail only
  "images": { "cover": Image, "gallery": [Image] },
  "next_departure": PackageDeparture,
  "departures": [PackageDeparture],                       // detail only, bookable dates, up to 60
  "seo": { "title": "…", "description": "…" }
}
```

`PackageDeparture`: `{ "id": 7, "start_date": "2026-10-28", "end_date": "2026-10-30", "start_time": "07:00", "price": 2450, "seats_left": 6, "available": true }`. A departure price overrides the package price.

`Image`: `{ "url": "…", "thumb": "…", "large": "…", "alt": "…" }`.

### GET /package-categories

```json
{
  "data": [{ "slug": "desert-safaris", "name": "Desert Safaris", "description": "…", "packages_count": 3 }],
  "product_types": ["Desert safari", "One-day trip"]
}
```

## POST /booking-requests

Sends an enquiry or booking request. Creates a lead; never a booking.

Rate limit: 5 per minute and 50 per day per IP.

| Field | Required | Rules |
|---|---|---|
| `product_type` | yes | `yacht_trip`, `package` or `car` |
| `product_slug` | yes | Slug of a published item |
| `request_type` | no | `enquiry` (default) or `booking_request` |
| `departure_id` | see below | Trip date id or package departure id |
| `date` | see below | `YYYY-MM-DD`, today or later |
| `end_date` | no | `YYYY-MM-DD`, on or after `date` |
| `guests` | no | Integer 1–500 |
| `name` | yes | Up to 120 characters |
| `phone` | yes | 6–30 characters: digits, spaces, `+ ( ) -` |
| `email` | no | Valid email |
| `message` | no | Up to 2000 characters |
| `idempotency_key` | no | Up to 64 characters `A–Z a–z 0–9 _ - : .` (or send the `Idempotency-Key` header) |
| `utm_source`, `utm_medium`, `utm_campaign` | no | Saved on the lead |
| `website` | never fill | Hidden honeypot field. If it has a value the request is treated as a bot. |

**Date rules (checked on the server with the latest data):**
- Package with fixed departures: `departure_id` is required, must be bookable (open, in the future, before the cut-off) and must have enough places for `guests`.
- Package on request: `date` is required and must respect the package's booking cut-off. Multi-day packages get an end date automatically.
- Package minimum and maximum guests are enforced.
- Yacht trip: `departure_id` (a trip date) or `date` is required. A trip date must be available with enough seats. Guests cannot exceed the trip capacity.
- Car: dates are optional and are passed to staff as the preferred date. No price estimate is given.

**201 Created**

```json
{
  "data": {
    "reference": "LD-00042",
    "status": "received",
    "request_type": "booking_request",
    "confirmed": false,
    "duplicate": false,
    "message": "Thank you. Your request is with our team. We will confirm availability and the final price shortly. Nothing is booked or charged yet.",
    "product": { "type": "package", "slug": "3-day-liwa-desert-safari", "name": "3-Day Liwa Desert Safari" },
    "departure_id": 7,
    "guests": 3,
    "price_estimate": { "amount": 7350, "currency": "AED", "unit_amount": 2450, "unit": "per_person", "note": "Estimate only. The final price is confirmed by our team." }
  }
}
```

`confirmed` is always `false`. Show the customer the `message` and the `reference`; never show the request as booked.

**200 OK, duplicate:** the same response with `"duplicate": true` and the first lead's reference. This happens when:
- the same `idempotency_key` is sent again (double click, network retry), or
- the same phone number asks about the same item within 10 minutes.

**202 Accepted:** the honeypot was filled. Nothing is saved.

## Errors

| Status | When | Body |
|---|---|---|
| 404 | Item not found or not published | `{ "message": "…" }` |
| 422 | Validation failed, item not available, date full | `{ "message": "…", "errors": { "field": ["…"] } }` |
| 429 | Too many requests | `{ "message": "Too Many Attempts." }` and a `Retry-After` header |

Example 422 messages: "Please choose a departure date.", "Only 2 place(s) left on this departure.", "This product is not available."

## Browser access (CORS)

Browsers can only call the API from website addresses listed in **Business settings › Website connection**, plus any in the `CORS_ALLOWED_ORIGINS` line of `.env` (comma separated). Enter the full address, e.g. `https://www.example.com`. Until an address is added, browsers on other sites are blocked. Server-to-server calls are not affected.

Allowed methods: `GET`, `POST`, `OPTIONS`. Allowed headers: `Content-Type`, `Accept`, `Idempotency-Key`, `X-Requested-With`. No cookies.

## How to connect the website (for the website developer)

1. Add the website address in Business settings › Website connection. It takes effect on the next request. (Only a change to `CORS_ALLOWED_ORIGINS` in `.env` needs `php artisan optimize:clear`.)
2. Listing pages: `GET /packages`, `GET /yacht-trips`, filters from `/package-categories`.
3. Detail pages: `GET /packages/{slug}`, `GET /yacht-trips/{slug}`. Show `departures` with `seats_left` and hide or disable dates where `available` is false.
4. Enquiry form: `POST /booking-requests`.
   - Generate one `idempotency_key` per form (for example a UUID) and reuse it on retries.
   - Include a hidden `website` input that stays empty.
   - Show the returned `message` and `reference`.
5. Never send or trust a price from the browser. Show `price_estimate` as an estimate only.
6. Cache catalogue responses for a few minutes if traffic is high. Do not cache seat counts for long.

## Tests

`tests/Feature/WebsiteApiTest.php` covers:
- only published items are shown, and internal notes and operators never appear
- a request creates a lead, never a booking, and uses the server price
- repeats and duplicates return the first lead
- invalid, unpublished and full requests are refused
- bots are ignored and requests are rate-limited
- CORS allows only the website addresses in settings
