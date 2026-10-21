# Redesign, Yacht Trips, Trips & Packages and API: Audit (21 Oct 2026)

**Scope:** an audit of the code as it stands, written before implementing `MASTER-IMPLEMENTATION-PROMPT` (UI/UX redesign, Yacht Trips correction, Trips & Packages, website API).

**Baseline:** `php vendor/bin/phpunit` → **80 tests, 594 assertions, all passing**, on a real local install with Laravel 12, Filament 3.3.57 and MariaDB 10.11.

**Decisions from Bilal (21 Oct):**
1. **New yacht charters stop.** Existing charter bookings stay in history unchanged.
2. **Trips & Packages enquiries use the existing yacht pipeline,** shown as "Yacht Trips". Stage keys and order are unchanged; only two stage labels are corrected.
3. **Website domain:** not known yet. It will be a Business setting used for CORS.

**Contract mapping:**
- The redesign and the terminology correction improve existing M1/M2 screens.
- **Trips & Packages** is not in Schedule A4–A6, so it is a **change request**.
- The **public API and booking requests** are **M3** (website ↔ CRM), built early.

---

## 1. Existing UI problems

- **Decorative shell:**
  - The sidebar is a dark "cabin" with a gold stitch, wine glow and gold group labels, in both themes.
  - The dashboard console uses gradients and a watermark logo.
  - The new brief asks for neutral surfaces, with red used only for primary actions and active states.
- **Theme tokens:**
  - Only Filament's Zinc greys are used, and the dark theme is near black.
  - There is no deliberate light and dark palette (workspace, surface, border and text roles).
  - `crm.css` still has some hard-coded colours (e.g. `.rp-card { background:#fff }`, `.rp-tag` gold).
- **Navigation:**
  - The groups are Sales / Accounts / Fleet / Website / Settings.
  - Yacht inventory ("Yachts") is a main menu item.
  - There is no Overview, Operations, Finance or Administration grouping.
  - There are no direct links to Car readiness, To collect, Documents or History, although the data and tabs exist.
- **Page headers:** headings are large (Filament default `text-2xl/3xl`) and push content down.
- **Badges and tables:** these are mostly Filament defaults. Row actions on narrow screens need a sideways scroll (for example the customer Documents table).

## 2. Forms with the most usability issues

| Form | Problem |
|---|---|
| Booking (892-line resource) | Long single column of sections; price, payment and status are far apart; the yacht charter option is still offered |
| Quotation (596 lines) | Same structure as booking; the pricing summary is below the fold |
| Car (665 lines) | Many tabs with dense fieldsets; documents note is out of date ("Milestone 2") |
| Lead (557 lines) | Contact, interest and pipeline are mixed; the interest offers "Yacht Rental" |
| Trip | Requires picking an owned yacht to prefill capacity; uses the word "Fleet" |
| Handover / return modals | Long scroll; estimate and final charges are visually the same |
| Business settings | Seven tabs, with yacht-only settings (yacht types and builders, yacht half/full-day thresholds, yacht default hours) |

## 3. Shared components to standardise

- **Section headers:** compact icon, title and description.
- **Field labels and helper text:** a single type scale.
- **Inputs:** one height, radius and focus ring.
- **Status badges:** compact, with a text label (never colour only).
- **Money:** AED, tabular numbers, right-aligned in tables.
- **Tables:** header weight, row height, hover, and an empty state with an action.
- **Modals:** padding and width.
- **Dashboard widgets:** the existing `rp-*` classes, made theme-safe.

## 4. Existing trip functionality and yacht dependencies

| Item | Finding |
|---|---|
| `trips.yacht_id` | Already **nullable** (`nullOnDelete`). A trip can exist without a yacht. |
| Trip form | Has a "Yacht" select that prefills capacity and marina. It is optional but presented as core. |
| Trip scheduling | `trip_dates` (date, start time, available slots, open/closed). Seat protection uses `Availability::seatsLeft()` with row locking. **Keep.** |
| Charter ↔ trip clash | `Availability::tripDepartureClash()` and `charterClashForDeparture()` only run when a trip has a `yacht_id`. **Keep** for legacy data; harmless without a yacht. |
| Yacht inventory | `YachtResource` (506 lines): readiness, maintenance, documents, ownership and status. It appears in the menu, in dashboard widgets (Fleet status, Yachts available) and in nav badges. |
| Yacht charters | `ServiceType::Yacht` on leads, quotations and bookings. `Booking::quote()` has yacht pricing. Pipeline label "Yacht Rental". |
| Settings | `yacht_types`, `yacht_brands` (builders), `yacht_default_hours`, pricing `yacht_half_day_hours` / `yacht_full_day_from_hours`. |
| Money category | `yacht_charter` (keep, for history). |

**Plan:**
- Hide the yacht inventory and new charters in the interface.
- Keep tables, records and legacy code paths.
- Relabel "Yacht Rental" to "Yacht Trips" (labels only).
- Make trips fully standalone, with an optional operator name.

## 5. Trips & Packages: what is needed

Nothing exists yet. To be built:

- **Tables:**
  - `package_categories`
  - `packages`, with a dynamic itinerary as an ordered JSON list of days, inclusions and exclusions, pricing (per person or fixed, sale price, guest tiers), duration in days and nights, destinations, booking mode, and website fields
  - `package_departures` (date, end date, time, capacity, price override, open/closed)
- **Bookings, quotations and leads:**
  - new service type `package` with morph alias `package`
  - additive `package_departure_id` columns on bookings and quotations
  - a seat check that reuses the trip seat pattern (row lock and billable statuses)
- **Media:** cover and gallery through spatie/medialibrary (same as trips).
- **Website category toggle:** `packages`.

## 6. Website and API infrastructure

- There are **no API routes** (`bootstrap/app.php` registers web routes only) and **no website code** in the repository.
- **Ready to use:**
  - `PublishesToWebsite` (published flag plus category switch, slug, sort order)
  - SEO fields
  - the `lead_sources` "Website" source
  - `leads.external_id` and the UTM fields
- **To build:**
  - `routes/api.php` with `/api/v1`, API Resources (explicit fields only), a `booking-requests` endpoint that creates a **lead** (never a booking), throttling, an idempotency key, a honeypot, and CORS limited to the domains set in Business settings
  - `docs/API.md` describing the contract

## 7. Security and testing requirements

- **Public responses:**
  - Published and enabled items only; a guessed slug of an unpublished item returns 404.
  - No notes, customers, staff, documents or settings.
- **Booking requests:**
  - Price, publication and capacity are always recalculated on the server; client totals are ignored.
  - Duplicate submissions (same idempotency key, or the same phone and product within 10 minutes) return the first result.
- **Permissions:**
  - Packages use `manage-fleet` to edit, as trips do.
  - New pages (Documents, History) use the existing abilities.
- **New tests:**
  - a trip without a yacht
  - package creation and itinerary order
  - departures and seat limits
  - a booking for a package
  - API listing and detail (published or unpublished)
  - booking-request validation, duplicates and the full-departure case
  - no private data in the API
- **Regression:** the full existing suite must stay green.
