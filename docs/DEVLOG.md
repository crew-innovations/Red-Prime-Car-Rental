# Development log — RED PRIME RENTALS CRM

## 2026-10-26 · One shared CRM: agents, driver accounts, mobile driver screens, customer accounts

Brief: "Updated requirements: shared system, driver management and mobile dashboard" (26 Oct). Bilal's answers:
- build drivers **and** customer accounts now
- one driver per booking does delivery, collection and (with driver) the driving
- drivers never record money; the office does
- a new "Agent" role (separate from Sales)

Contract mapping: **change request**. This replaces the earlier "never build customer portal / driver app" rule, because Bilal asked for both inside the same CRM. There is still no separate app.

### One system, role-based views
- Same Laravel app, `users` table, login and design for everyone:
  - Staff, agents and drivers use `/crm`.
  - Customers use `/account` (a second Filament panel in the same app).
- `User::canAccessPanel()` runs on every request:
  - Customer-only logins never reach `/crm`.
  - Drivers are blocked when "Drivers can log in" is off.
  - "Can log in" (`login_enabled`) switches one person off without deleting anything.
- New roles: **Agent** and **Customer**. Agents start with lead, booking, quotation, document and payment rights; Admin can change them.
- "Agents only see their own work" (on by default): leads and customers assigned to them, plus their quotations, bookings, money and documents. This covers the lists, open-by-URL, badges, dashboard widgets and the pipeline.
- Private files (`/crm/private-file`, `App\Support\PrivateFiles`):
  - Staff can open everything, as before.
  - Drivers can open only their own papers and the photos of their own bookings.
  - Customers can open only their own documents.

### Logins without passwords being typed or sent
- "Send login link" on driver and customer pages. The link is signed and works once (it is tied to the current password).
- It expires after "Login links work for" hours (default 72) and can go by WhatsApp or email.
- The same link also works when someone forgot their password.
- The driver form no longer has a password field.

### Driver profiles and documents (Operations › Drivers)
- `driver_profiles` holds the legal name, photo, date of birth, nationality, gender, address, emergency contact, driver ID and joining date.
- Admin chooses which fields are required.
- `driver_documents`: passport, Emirates ID, visa, licence (with classes), permit and other.
  - Each has a number (stored encrypted, lists show the last 4 characters), issuer, dates and a private file.
  - "Replace" keeps the old copy as history.
  - Expiry shows as valid, expiring (within X days) or expired.
- Required documents are configurable. Optionally, drivers with missing or expired required documents cannot be assigned.
- Daily reminder `crm:driver-document-reminders` at 08:00 goes to the driver and to the people who manage drivers. It needs the Hostinger cron (see below).
- Profile, document and job changes go to History. Document numbers are never shown there.
- New abilities: `manage-accounts`, `manage-drivers` and `review-damage`.

### Assignment and jobs
- Any car booking can have a driver:
  - with driver: they drive the whole time
  - self-drive: they deliver at the start and collect at the end
- "Instructions for the driver" field on the booking.
- `booking_jobs` (delivery and collection) are kept in step with the booking automatically:
  - New, changed, moved and cancelled jobs notify the driver (bell, plus email if switched on).
  - Who assigned a job and when is kept.
- Clash rules:
  - With-driver bookings block the whole time.
  - Self-drive bookings block only the delivery and collection.
  - Jobs closer than "Minimum time between two jobs" (default 90 min, **to confirm**) clash.
- The check runs on the form and again on every save. Inactive drivers cannot be given new jobs.
- The driver picker marks busy drivers and does not let staff choose them.

### Driver screens (phone first, inside /crm)
- **My jobs:** today, in progress, upcoming and done, as cards (no wide tables). Document expiry warnings appear on top. It refreshes every minute.
- **Job page:** time, car and plate, address with a maps link, customer with Call and WhatsApp (setting), instructions (setting) and the checklist. Big buttons:
  - Accepted, On the way and Arrived (which ones are allowed is a setting)
  - **Hand over** (odometer, fuel, checklist, photos and notes). The booking becomes In progress; no money is recorded.
  - **Collect** (odometer, fuel, condition, damage, photos and notes). The office then completes the return; the staff Receive form is pre-filled from the driver's check.
  - **Report damage**
- Checks are enforced on the server.
- Nothing is marked done until the server has saved it. If saving fails, the form stays open with what was typed. Tapping outside does not close it.
- Photos: camera or gallery, several at once, resized on the phone before upload.
- **My profile:** details and documents (read only), the fields the admin allows them to edit, and a password change.
- Drivers no longer see the bookings list, so they see no prices or payments.

### Damage reports (Operations › Damage reports)
- Reported by drivers, with photos. Staff record "no charge" or "charge added on the booking".
- Charges themselves are still added through the normal return or payment flow.

### Customer account (/account)
- Invite from the customer page (Account › Invite to customer account).
- Pages:
  - Home: next booking, balance due, quotations waiting, documents to fix
  - Bookings, and the detail of each (price, paid, balance, deposit, document checklist)
  - Quotations (sent ones only; they open the existing quote page)
  - Documents (status and upload)
  - Payments and balance (with the bank details from settings)
  - Password change
- Everything is filtered by the customer's own record (`App\Support\CustomerArea`). Another customer's booking returns "not found".
- Settings control the whole area, quotations, payments and uploads.
- There is no tax invoice document yet: payments are shown as received money. A formal invoice needs the VAT/TRN decisions.

### Business settings
- New **Drivers** tab: access, self-service, handover and collection checks (required odometer and fuel, minimum photos, checklists), the clash gap, required fields and documents, expiry warning days and reminders, and email notifications.
- Roles tab: Agent scoping, customer accounts and login link hours.

### Database (`2026_10_26_000001_add_driver_and_customer_accounts`)
- Additive and reversible. Rollback and re-run were tested locally.
- `users` gains `customer_id`, `login_enabled`, `invited_at` and `last_login_at`.
- New tables: `driver_profiles`, `driver_documents`, `booking_jobs`, `damage_reports` and `notifications`.
- `bookings` gains `driver_instructions`.
- Roles Agent and Customer. Agent is also added to saved permissions.

### Deploy notes
- Run `bash scripts/deploy-hostinger.sh` (migration, new routes and the new panel).
- For expiry reminders, add a cron job in hPanel: `php artisan schedule:run` every minute, from the app folder.
- Email for invites and notifications needs the mail settings in `.env`. Until then, use WhatsApp or copy the link.

### Tests
- New `DriverWorkflowTest` (14 tests) and `AccountsAndAgentsTest` (9).
- Smoke tests cover the driver and customer screens and the Drivers settings.
- **Full suite: 125 tests, 939 assertions, all passing.**
- Upload fields only accept fresh uploads in the expected folder (a changed path in the browser cannot attach someone else's file).
- Screens checked at 390 px (light and dark) and 1366 px: no sideways scrolling and no JavaScript errors.
- **Not tested here:** real phone cameras, slow mobile networks, email delivery and the Hostinger cron. Check these on staging.

### Starting values to confirm (not business policy, all editable)
- Gap between two jobs of a driver: 90 min.
- Minimum photos: handover 1, collection 1.
- Document warning: 30 days.
- Default checklists.

## 2026-10-21 · Master prompt: business-model correction, Trips & Packages, website API, redesign

Brief: the master implementation prompt of 21 Oct. Bilal's answers:
- no new yacht charters; old charters stay in history
- package enquiries go to the Yacht Trips pipeline, with only labels changed
- the website address is not known yet, so it is a Business setting

Contract mapping:
- Trips & Packages: change request
- Website API: M3, built early
- Redesign and terminology: improvements to M1/M2

### Business model: Yacht Trips without yacht inventory
- RED PRIME sells trips, so trips no longer need a yacht. Trips now have an operator name (internal) and customer instructions instead.
- Yachts: create is blocked (403, even for Super Admin) and the menu is hidden. Old yacht records and charter bookings still open from history.
- The pipeline is now labelled "Yacht Trips", with stage labels "Trip Selected" and "Trip Completed". Stage keys, transitions and won/lost behaviour are unchanged.
- Service pickers offer Car Rental, Yacht Trip and Trip / Package. A record that is already a yacht charter keeps its own option.
- Business settings: yacht-only settings are hidden but kept. Saving no longer wipes values that are not on the screen.

### Trips & Packages (new module, Operations › Trips & Packages)
- Tables: `package_categories`, `packages` and `package_departures`. Departure links were added to bookings, quotations and leads. The migration is additive and reversible.
- Itinerary: a day-by-day repeater (add, edit, reorder, remove), with a "match the number of days" helper.
- Pricing: per person or per package, sale price, child price, group prices, and a per-departure price that wins.
- Booking: fixed departures with places, locked per row so two bookings at once cannot overbook. "On request" packages have a booking cut-off.
- Quotations, bookings, accounts (`travel_package` revenue) and the dashboard departures strip all include packages.
- A Preview page shows an item as the website would, and says why it is not live.

### Website API v1 (`docs/API.md`)
- Read-only catalogue of published Yacht Trips and packages, using explicit public fields only.
- `POST /booking-requests` creates a lead and never a booking:
  - the price is worked out on the server
  - repeats return the first lead (idempotency key)
  - the same phone and item within 10 minutes is treated as a duplicate
  - a honeypot field catches bots
  - limit of 5 per minute and 50 per day per IP
- CORS allows the `.env` list plus the "Website connection" addresses in Business settings.
- **No website is connected.** There is no website code in this project.

### Redesign
- Neutral gray scale for both themes, with the dark tokens from the brief (workspace `#101216`, sidebar `#151820`, cards `#1B2029`). Red is used for primary actions and the active page; gold is used rarely.
- Sidebar: the cabin and gold-stitch style was replaced by a white (light) or slate (dark) sidebar. The active page has a red tint and a left bar. Group labels are muted.
- Menu: Overview / Sales / Operations / Finance / Administration. New "Car readiness" and "To collect" shortcuts.
- New read-only global lists:
  - **Administration › Documents:** review tabs. The approve and reject actions are shared with the customer tab.
  - **Administration › History:** whole-CRM audit log with filters.
- Top bar: light/dark switch.
- Avatars: initials drawn locally. Before this, staff names were sent to ui-avatars.com.
- Smaller page titles, quiet table headers, one card radius.
- The dashboard console is a plain card (no gradient or watermark).
- WhatsApp, Call and Record payment buttons are neutral; the next step is the single red button.

### Tests
- Added `TripsAndPackagesTest` (7 tests) and `WebsiteApiTest` (7).
- Menu and permission tests for Documents and History, and approving a document from the list.
- Smoke tests now cover the new pages and tabs.
- **Full suite: 101 tests, 726 assertions, all passing.**
- Screenshots: 1366, 1024 and 390 px in light and dark. No sideways scrolling and no JavaScript errors.

### Not verified yet
- The new pages on staging. This needs the upload plus `bash scripts/deploy-hostinger.sh`, which runs the new migration.
- The live test from handover onward (pending since 17 Oct).

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

## 2026-10-17 · Checklist tested on a real install

The app was installed here and tested for real for the first time:
- Laravel 12 and Filament 3.3.57, with packages cloned from GitHub because Packagist is blocked.
- MariaDB 10.11.
- Checklist results by item: `docs/CHECKLIST-STATUS.md`.

### Bugs found by running the app, and fixed

1. **Quotations list crashed.**
   - Cause: the tab filter closures used `$q` instead of `$query`. Filament injects parameters by name, so it passed an empty query.
2. **"New transaction" crashed.**
   - Cause: form defaults were enum objects, while the closures expected strings.
   - Fix: every enum form default now uses `->value` (cars, yachts, trips, customers, leads, activities and transactions).
3. **"Cancel booking" on the booking page did nothing.**
   - Cause: the action was named `cancel`, the same as the form's Cancel button.
   - Fix: renamed to `cancelBooking`.
4. **Simultaneous saves could double-book a car, or overbook trip seats.**
   - Cause: inside a long save transaction, the availability queries read an old snapshot of the data.
   - Fix: `Availability::latest()` makes them locking reads.
   - Tested with two processes.
5. **An overdue (not returned) car looked free.**
   - New bookings that start before it is back are now refused.
   - Handover of later bookings is blocked until it is received.
6. **The demo seeder clashed with its own trip.** The guard now refuses that booking, so the demo charter was moved to the morning.
7. **Lost without a reason could be saved outside the form.** The model now refuses it.
8. **Customer documents were visible to every role.** They are now limited to roles with the `review-documents` ability.
9. **Old booking photos on the `uploads`/`public` disks.**
   - `crm:secure-booking-files` handles any old disk and reports the leftover `booking-uploads` temp files.
   - Tested on a copy of the old version's database.

### Also added

- **Booking page:**
  - The driver field shows the driver's usual cars.
  - Return charges marked as estimates.
  - Deposit refund available on cancelled bookings.
- **Settings:** price-suggestion thresholds (hourly / weekly / monthly / yacht half and full day).
- **Fleet:**
  - A warning, recorded in the history, when a car or yacht is put into maintenance while it has bookings ahead.
  - The car and yacht menu badges count only cars and yachts that are available and ready.
- **Tests:** an automated suite (`tests/Feature`, 72 tests) and race scripts (`tests/Concurrency`).

### Tests run

| What | Result |
|---|---|
| PHPUnit | 72 tests, 567 assertions, all passing |
| Race scripts (same car, last seats) | Second save refused |
| `migrate` from the old version, `migrate:rollback --step=3`, and `migrate` again | OK |
| Browser check of 12 screens at 1366 px and 390 px | No horizontal overflow |

**Not testable here:** the Hostinger server itself, real WhatsApp sending, Google Fonts, and the website (not built yet).

## 2026-10-17 · New look for the CRM

Bilal asked for a more attractive UI and sidebar. This is a styling change only: no new module, field or behaviour.
- **Sidebar:** a dark "cabin" in both themes, with a wine glow behind the logo and a gold stitched line down the edge. Group names are in gold, the active page is a red pill, counters are softer, and a signed-in user card sits at the bottom. It also works when collapsed and on phones.
- **Rest of the panel:** a blurred top bar, softer cards and tables, primary buttons with depth, and a branded sign-in page with a larger logo.
- **Files:** `public/css/crm.css`, `resources/views/filament/sidebar-footer.blade.php`, and a `SIDEBAR_FOOTER` hook in `AdminPanelProvider`.
- **Checked:** screenshots in dark and light at 1366 px and 390 px (no sideways scrolling), plus the collapsed sidebar. The smoke and permission tests pass (15 tests).

## 2026-10-17 · Dashboard redesign

Bilal: "dashboard abi be attractive ni ha". This is layout and styling only. The data comes from the same queries; no business rule changed.
- **New `App\Filament\Pages\Dashboard`** (replaces Filament's default page). It sets a fixed widget order, a 12-column grid (7/5 split from xl screens) and no page heading.
- **Order:** today first, then sales.
  1. Today console
  2. Handovers & returns, next to fleet status
  3. Yacht trips this week
  4. Lead stats
  5. Leads trend, next to leads by source
  6. My follow-ups, next to latest enquiries
- **Today console** (WelcomeBanner):
  - dark, like the sidebar
  - four big numbers: going out, due back (with the late count), cars ready of total, and received this month (finance roles only; others see new leads today)
  - "waiting" alerts that link to the matching list tab
  - 4 main actions, plus small Add car / yacht / trip buttons
- **Yacht trips:** a strip of departure cards.
- **Leads by source:** ranked bars instead of a 7-colour donut.
- **Leads trend:** bars in brand red and a gold that passes the colour-blind check.
- **Fleet:** the "Fleet today" stat row (FleetSnapshot) is no longer on the dashboard. Fleet status already shows the same numbers. The class is kept.
- **Fixes:**
  - The balance pill overflowed its card in Today's handovers. The list grid now lets rows shrink.
  - Cards in the same row now share one height.
- **Tests:**
  - New `test_dashboard_opens_for_every_role` (8 roles; revenue is hidden from non-finance roles).
  - Full suite: 80 tests pass.
  - Screens checked in dark and light at 1366 px and 390 px.
- **Deploy note:** because there is a new page class, run the deploy script, so the route and Filament component caches are rebuilt.
