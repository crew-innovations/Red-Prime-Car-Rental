# Development log — RED PRIME RENTALS CRM

## 2026-10-16 · Audit fixes, quotations, documents, readiness, drivers

This round covers the prompt "Audit, fix and implement" and Bilal's request: "first fix all workflow how we make quote and other things docs uploadin system everything".

### Issues found and root causes

| # | Issue | Root cause | Fix |
|---|---|---|---|
| 1 | Any logged-in user could edit fleet, prices, money and delete records | Resources had no permission checks; roles existed but were not used | `App\Support\Access` defines Gates for each ability. `GuardedResource` and `GuardsSave` apply them to every resource, with server-side 403 on save. Actions use `->authorize()`. |
| 2 | Two people saving at the same moment could double-book a car or yacht | The overlap check ran only in the form, with no lock | Panel `databaseTransactions()`. `Availability::lock()` uses `lockForUpdate`. `Booking::saving` runs `guardAvailability()`, so every save path is checked on the server. |
| 3 | A yacht could be chartered over a scheduled trip departure | Trips and charters were checked separately | `Availability::assetConflict()` counts trip departures as yacht occupancy. Adding trip dates warns about charter clashes. |
| 4 | A returned car became bookable before cleaning or inspection | Return set the car straight to Available | New `readiness` field (ready / cleaning / inspection), separate from status. Handover is blocked until it is "ready" (manager override is logged). |
| 5 | Booking photos and agreements were publicly reachable | Stored on the public `uploads` disk | New `private` disk outside `/public`, served only through 10-minute signed links to logged-in active users. `crm:secure-booking-files` moves old files. |
| 6 | Double clicks could record the same payment twice | No duplicate guard | `Booking::recordPayment` returns the existing row when the same payment repeats within 30 seconds. |
| 7 | The same phone number in another format created duplicate customers | Exact string match | `Phone::whereMatches()` compares the last 9 digits. |
| 8 | Late charges were pre-filled automatically | Invented business rule | Removed. Staff see "X hour(s) late" and add a charge only if their terms apply one. |
| 9 | No record of who changed prices, statuses or bookings | No audit trail | `Audited` trait plus `audit_logs` table, shown in a "History" tab (Admin / Manager). |

### What was built

- **Quotations** (Sales › Quotations):
  - Draft → Sent → Negotiation → Accepted / Declined / Expired.
  - The price is locked once sent; this is enforced in the model as well as the form. "Revise" unlocks it, and the change is logged.
  - Accepting does **not** hold the car. "Convert to booking" re-checks availability. If the car is taken, the quote stays Accepted at the agreed price.
  - The booking copies the agreed price and VAT and links back to the quote.
  - Customer page `/quote/{token}`: branded, printable to PDF, with a WhatsApp reply button. "Opened" time is tracked.
  - Can be created from a lead or a customer. Leads move to "Quotation sent".
- **Documents** (Customer › Documents):
  - Staff upload, or a secure upload link for the customer (`/upload/{token}`). Only a hash of the token is stored; the link expires after a set time and can be switched off.
  - Review states: To review, Approved (with expiry date), Rejected, Replacement needed.
  - The booking page shows the required-document checklist.
  - Handover can be blocked until documents are approved, only when this is switched on in Business settings.
- **Business settings** (Settings, Admin only):
  - Company and bank details.
  - VAT: off by default, with no rate assumed.
  - Quote validity and terms.
  - Required documents per booking type, upload link hours.
- **Readiness:**
  - The return flow asks: ready / needs cleaning / needs inspection / maintenance.
  - Cars and yachts get a "Ready?" badge, a "Mark ready" action and a "Needs cleaning / inspection" tab.
  - Today's handovers flags "Not ready" items. Available counts include only ready cars.
- **Drivers** (Fleet › Drivers):
  - Simple profiles: name, phone, email, notes, active, usual cars.
  - On a car you can set its usual drivers; on bookings these are suggested first and can be overridden.
  - Clashing driver assignments are blocked.
  - No pricing, payroll or app.
- **Dashboard:** chips for documents to review, accepted quotes to book, and cars waiting for cleaning or inspection.
- **Deploy script:** takes a DB backup to `storage/app/backups` before migrating and stops if the backup fails. It also secures old booking files.

### Database changes (additive only, reversible)

- `2026_10_17_000001`:
  - cars and yachts: `readiness`, `readiness_note`
  - bookings: `inspection_result`
  - users: `notes`
  - new tables `car_driver` and `audit_logs`
- `2026_10_17_000002`:
  - new tables `settings`, `document_requests`, `customer_documents`, `quotations`
  - bookings: `quotation_id`, `tax_rate`, `tax_inclusive`, `tax_amount`

No existing column was changed or removed, and no data is deleted.

### Tests run

- `php -l` on all changed PHP files: OK.
- Import and method check against the Filament 3.3 and Laravel 12 sources: OK. The remaining warnings are known false positives (namespace imports, Carbon and spatie methods).
- VAT calculation unit test (exclusive, inclusive, off by default, enabled without a rate): 7 of 7 passed.
- `bash -n` on the deploy script: OK.
- **Not yet run:** a live end-to-end test on staging. The app cannot run here because Packagist is blocked. This is needed after the deploy.

### Live test checklist (after deploy)

1. Lead → Create quotation → Send → open the link while logged out → Customer accepted → Convert to booking.
2. Convert while the car is already booked on those dates: expect an error, and the quote stays Accepted.
3. Customer › Documents → Request documents → upload from the link → Approve.
4. Booking › Receive with "Needs cleaning" → the car shows "Not ready" → Hand over is blocked → Mark ready → Hand over works.
5. Log in as Sales: Fleet editing is read-only. Log in as Driver: only their own bookings are visible.
6. Two tabs saving the same car and dates: only one booking is saved.

### Decisions still needed from Bilal

These are not assumed anywhere; they can be set in Business settings when decided.

- VAT: on or off, the rate, and whether prices include it.
- Required documents for each booking type (self-drive, with driver, yacht, trip, corporate), and whether missing documents block the handover.
- Quotation validity (days) and the standard terms text.
- Late-return, extra-km and fuel charge rules.
- Whether "Pending payment" bookings should hold the car (currently they do).
- Buffer time between bookings, and refund and cancellation thresholds.
- Whether to delete the old public copies of booking photos after checking (`php artisan crm:secure-booking-files --remove-public-copies`).

## 2026-10-16 (later) · Nothing hard-coded: business settings for everything

Bilal: "dont do anything hardcoded we have to edit or change everything by ourselves". His answers to the decisions:
- VAT, quote validity and the late-return rule: they decide them themselves, so each is a setting.
- Documents: they ask for documents according to need, through a secure upload link.
- Pending payment: it holds the car.

**Settings › Business settings** now has seven tabs. Admin only by default; the permission itself is editable.

| Tab | What can be changed |
|---|---|
| Company & messages | Company and bank details; every WhatsApp message template (greeting, quotation, document request, booking details, payment reminder) with `{first_name}`, `{company}`, `{link}`… placeholders |
| Bookings & returns | Pending payment holds the car (on); gap between bookings (0 min); default booking length for cars (24 h) and yachts (4 h); default trip length (120 min); late grace (30 min); late-charge rule; return charge lines (rename and add; extra km, fuel and late cannot be removed) |
| VAT & quotations | VAT on/off, rate, inclusive; default quote validity; standard terms; quote extras; decline reasons |
| Documents | The document types themselves (add, rename, which ones the customer can upload); required types per booking type (none by default); block handover; link hours; rejection reasons |
| Lists | Lost reasons, cancel reasons, pick-up places, car types, car brands, car locations, yacht types, yacht builders, marinas |
| Money categories | Rename or add income and expense categories. Ones the CRM relies on come back if removed. |
| Roles & permissions | Which role can do each of the 12 abilities. Super Admin always has everything, and Admin always keeps Business settings. |

**Defaults:** the values in brackets above are the starting values, chosen so behaviour is unchanged until someone edits them.

**Removed:** the hard-coded constants `LeadResource::LOST_REASONS`, `CarResource::BRANDS`/`TYPES`, `YachtResource::MARINAS`/`TYPES` and `Booking::CHARGES`, and every "RED PRIME RENTALS" text in messages. The values now come from `App\Support\Options` (lists, document types, charges, categories, messages) and `App\Support\Rules` (booking and return rules).

**Safety:**
- Document, charge and category keys never change when an item is renamed, so old records keep their names.
- Removed items still show correctly on records that already use them.
- Every save of the settings is written to the audit log, with the old and new values.

**Tests run:**
- `php -l` and the verify script on 30 changed files: OK.
- `Options` unit tests (lists, templates, keys, charges, document types, categories, saved values kept): 16 of 16 passed.
- `Rules` unit tests (grace, the four late-charge modes, with driver, yacht, defaults): 11 of 11 passed.

**Still to test live:** saving the settings page and the effect on the forms, after deploy.
