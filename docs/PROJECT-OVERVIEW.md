# RED PRIME RENTALS CRM: Project Overview

**Client:** RED PRIME RENTALS (Dubai): luxury car rental, yacht charters and yacht trips
**Built by:** Crew Innovations
**Last updated:** 21 October 2026

This file explains the whole project in one place: what the CRM does, how staff use it, how it is built, how it is deployed, and what is still open.

---

## Contents

1. [What the CRM is](#1-what-the-crm-is)
2. [Contract scope and milestones](#2-contract-scope-and-milestones)
3. [Modules](#3-modules)
4. [The full workflow, step by step](#4-the-full-workflow-step-by-step)
5. [Statuses](#5-statuses)
6. [Money rules](#6-money-rules)
7. [Business settings](#7-business-settings)
8. [Roles and permissions](#8-roles-and-permissions)
9. [Security and data safety](#9-security-and-data-safety)
10. [Design and user interface](#10-design-and-user-interface)
11. [Technical stack and code map](#11-technical-stack-and-code-map)
12. [Database](#12-database)
13. [Deployment (Hostinger staging)](#13-deployment-hostinger-staging)
14. [Testing](#14-testing)
15. [Project history](#15-project-history)
16. [Current status](#16-current-status)
17. [Open decisions for RED PRIME](#17-open-decisions-for-red-prime)
18. [Not built, by agreement](#18-not-built-by-agreement)
19. [Other documents](#19-other-documents)

---

## 1. What the CRM is

An internal web app the RED PRIME team uses to run the business day to day:

- capture and follow up enquiries (leads)
- send quotations and turn them into bookings
- collect customer documents through a secure link
- hand cars and yachts over and receive them back
- record payments, deposits and refunds
- see the fleet, today's work and the numbers on one dashboard

It runs at `/crm` on the RED PRIME domain. It opens in a dark theme, and a light theme is also available. Staff log in with their own accounts, and each role sees only what it needs.

---

## 2. Contract scope and milestones

The agreement is `CRM_Agreement_REDPRIME.pdf` (clause 7.3, Schedule A1–A14).

| Milestone | What it covers | Status |
|---|---|---|
| **M1** | Architecture, database, plus five modules: Car Rental, Yacht Rental, Yacht Trips, Customers, Leads | Complete |
| **M2** | All remaining CRM modules (pipelines, bookings, operations, payments, quotations, documents, roles, dashboard) and CRM-side website controls | Mostly built. Bookings, operations, payments, pipelines and the dashboard were delivered early, at the client's request. Quotations, documents, readiness, drivers and history were added on 16–17 Oct 2026. |
| **M3** | Website, the website-to-CRM connection, social and communication integrations, acceptance testing, deployment and handover | Not started. No website exists yet. |

Every change is mapped to M1, M2 or M3, or labelled a "change request" if the contract does not cover it.

---

## 3. Modules

### Sales
- **Leads:**
  - Created by hand, or by the website through the public API (`POST /api/v1/booking-requests`, source "Website"). No website is connected yet.
  - Duplicate warning by phone (last 9 digits, so `050…`, `+971 50…` and `00971…` all match) and by email.
  - Lead source, interest (car, yacht trip or trip/package), dates, budget, priority, salesperson and next follow-up.
  - Activity log for calls and WhatsApp.
- **Pipeline board:**
  - Three pipelines: Car Rental, Yacht Trips and B2B (Schedule A3). Package enquiries use the Yacht Trips pipeline.
  - On 21 Oct the yacht pipeline was renamed from "Yacht Rental" to "Yacht Trips" and two stage labels changed ("Trip Selected", "Trip Completed"). Stage keys and behaviour are unchanged.
  - Move a card to another stage by dragging it, or with its ••• menu.
  - Lost needs a reason.
  - Won stages create or link the customer, never a duplicate.
- **Quotations:**
  - Can be created from a lead or a customer, and are pre-filled.
  - Include the price, discount, extras, VAT, security deposit, advance and terms.
  - Sending locks the price.
  - The customer opens a branded web page that can be printed to PDF.
  - Accepting does not hold the car. "Convert to booking" checks availability again.
- **Bookings:**
  - Self-drive or with driver. Price suggestions come from the car's hourly, daily, weekly and monthly rates.
  - Add-ons, discount, VAT, deposit and advance.
  - Overlapping bookings are refused, with a clear message.
- **Customers:**
  - Profile, type (individual, corporate, agency) and VIP.
  - Tabs for enquiries, bookings, quotations, documents, upload links, accounts and history.

### Finance
- **Accounts overview:** revenue, expenses and profit, revenue by service, top earners, and outstanding payments.
- **Transactions:** income and expenses with editable categories. Security deposits are kept separate from revenue.
- **To collect:** every unpaid booking balance, with a WhatsApp reminder.

### Operations
- **Cars:**
  - Specs, rates (hourly, daily, weekly, monthly and with-driver), km limit, extra-km rate, deposit.
  - Documents with expiry dates, ownership (own or partner), maintenance, and usual drivers.
  - Website publishing fields.
- **Yacht Trips:**
  - RED PRIME sells trips; it does not own or rent out yachts.
  - Shared trips with dates, times, capacity, prices, operator name (internal) and customer instructions. No yacht record is needed.
  - Old yacht records and charter bookings stay in history. New yachts and new charters cannot be added, and the yacht menu is hidden.
- **Trips & Packages** (new, change request):
  - Multi-day or one-day packages: category, type, duration, destinations, meeting point.
  - A day-by-day itinerary that can be added to, edited, reordered and removed.
  - Pricing per person or per package, sale price, child price and group prices. A departure can have its own price.
  - Fixed departure dates with places (no overbooking, even when two staff book at once), or "on request" with a booking cut-off.
  - Inclusions, exclusions, photos, website publishing and SEO, and a Preview page that explains why an item is not live.
  - Quotations and bookings work for packages exactly as for trips.
- **Drivers:**
  - Simple profiles: name, phone, notes, active or inactive, usual cars.
  - Suggested first on bookings.
  - A driver cannot be on two jobs at the same time.
  - No pay, payroll or app.
- **Readiness:**
  - Each car is Ready, Needs cleaning or Needs inspection, separately from its booking status. The menu has a "Car readiness" shortcut.
  - Handover is blocked until it is Ready. A manager can override, and the override is logged.

### Documents
- Staff can upload a file, or create a **secure upload link** for the customer: no account, it expires, and it can be switched off.
- Review states: To review, Approved (with an expiry date), Rejected, Replacement requested.
- Files are private and open only through short-lived signed links, for logged-in staff.
- Handover can be blocked until the required documents are approved. This is a setting, off by default.
- **Administration › Documents** lists every customer's documents in one place, with tabs for To review, Expired and Waiting for a new upload.

### Administration
- **Documents** (above) and **History** (below).
- **Business settings:** everything the business decides. See [section 7](#7-business-settings). Includes "Website connection" (the website addresses allowed to call the API).
- **Team & roles,** **Lead sources** and **Website sections.**

### Website API
- Versioned public API at `/api/v1`: published Yacht Trips and Trips & Packages, and website enquiries / booking requests that create leads (never bookings).
- Full contract: `docs/API.md`. The website itself is not built or connected.

### Dashboard
- **"Today" console:**
  - Cars going out today, due back today (with the late count), cars ready to rent, and money received this month (finance roles only).
  - Alerts that link to the matching list.
- **Today's handovers and returns,** next to **fleet status** (status bars plus documents and services due).
- **Departures this week:** yacht trip and package departures in the next 7 days.
- **Lead stats, leads trend, leads by source, my follow-ups and latest enquiries.**

### History (audit log)
Every change to prices, statuses, bookings, payments and settings is recorded with who made it, when, and the old and new values. Admins and Managers see it in a History tab on each record, and in **Administration › History** for the whole CRM (filter by record type, event, person and date).

---

## 4. The full workflow, step by step

The example is a car. Yachts follow the same steps; differences are listed at the end.

### 1. Lead comes in
- Staff add the lead under **Sales › Leads**.
- The pipeline is picked from the interest:
  - car → Car Rental
  - yacht trip or package → Yacht Trips
  - companies → B2B
- If the phone or email already exists, a warning names the customer or lead.

### 2. Work the lead
- Log calls and WhatsApp messages as activities.
- Move the card on the **Pipeline** board.
- Car Rental stages: New Lead → Contacted → Requirements Confirmed → Car Selected → Quotation Sent → Negotiation → Documents Received → Payment Pending → Confirmed → Vehicle Delivered → Completed.
- **Lost** asks for a reason.

### 3. Quotation
1. On the lead, click **Create quotation**. The car, dates, rate, price and deposit are filled in.
2. Add the discount, extras (delivery and so on), the advance and notes. Then set **Valid until**.
3. **Send to customer:**
   - The price is locked.
   - A WhatsApp message with the link is ready to send.
   - The lead moves to *Quotation Sent*.
4. The customer opens the quotation page: branded, with a **Download PDF** button and a **Reply on WhatsApp** button (shown when the company phone is set).
5. **Revise** unlocks the price. The change is logged.
6. **Customer accepted** locks the agreed price. The car is **not** held yet.
7. **Convert to booking:**
   - Availability is checked again. If the car was taken meanwhile, the quote stays Accepted and nothing is booked.
   - Choose Confirmed or Pending payment, and record the advance if received.
8. Quotes past their valid-until date expire automatically.

### 4. Booking is created
- The customer profile is created or linked.
- The lead moves to *Confirmed*, and the car becomes *Reserved*.
- The unpaid balance appears under **Finance › To collect**.

### 5. Documents
1. On the customer, open **Documents › Request documents**, tick the documents needed, and set how long the link works.
2. Send the link by WhatsApp. The customer uploads JPG, PNG, WebP or PDF files, up to 10 MB each. Other types are refused.
3. Staff **Approve** (with an expiry date), **Reject** (with a reason) or ask for a replacement.
4. The booking page shows a checklist of required documents.

### 6. Payments
- **Record payment** on the booking (card, cash, bank transfer…).
- Paid, balance and payment status update everywhere.
- A double click within 30 seconds is ignored, so the same payment is never recorded twice.

### 7. Hand over
Click **Hand over car** and record:
- the date and time
- odometer and fuel
- the driver, if with driver
- the security deposit received
- any rental payment
- condition photos and notes

Handover is blocked if:
- the car is not Ready
- the car is in maintenance or unavailable
- the car is still out on another booking
- required documents are missing (when that setting is on)

A manager can override, and the override is logged. After handover, the car becomes *Rented* and the lead moves to *Vehicle Delivered*.

### 8. Receive back
Click **Receive car** and record:
- the return time (late is shown after the grace time)
- odometer, fuel, condition, photos and notes

**Charges:**
- Extra km, from the car's km limit and rate.
- Late return, from the rule in settings.
- Fuel, Salik, damage, cleaning and other lines.
- Suggested amounts are marked as estimates. They are charged only on **Complete return**.

**Readiness after return:** Ready, Needs cleaning, Needs inspection, or send to maintenance.

**Settle up:**
- take payment now
- deduct from the deposit
- refund the deposit now, or hold it for Salik and fines and use **Refund deposit** later

### 9. Close
- The lead moves to *Completed*.
- Any unpaid amount stays in **To collect** until it is marked paid.

### Yacht trips and packages
- **Yacht trips:** pick a trip date and the number of guests. The CRM checks the seats left, even when two staff book at the same moment.
- **Packages:** pick a departure (or a date, for "on request" packages) and the number of guests. Places are checked the same way, and the price comes from the package or the departure.
- **Old yacht charters** (history only): **Start charter** and **End charter** replace handover and return.

---

## 5. Statuses

| Thing | Statuses |
|---|---|
| Booking | Inquiry, Quotation, Pending payment, Confirmed, In progress, Completed, Cancelled, No-show |
| Booking holds the car | Pending payment (a setting, currently on), Confirmed, In progress |
| Quotation | Draft, Sent, Negotiation, Accepted, Rejected, Expired |
| Car | Available, Reserved, Rented, Maintenance, Unavailable |
| Yacht | Available, Booked, Maintenance, Unavailable |
| Readiness (car / yacht) | Ready, Needs cleaning, Needs inspection |
| Customer document | To review, Approved, Rejected, Replacement requested |
| Payment | Paid, Partially paid, Pending, Overdue, Refunded |

Car and yacht status updates itself from bookings. Maintenance and Unavailable are never overwritten automatically. If a car is put into maintenance while it has bookings ahead, staff are warned and the warning is logged. Bookings are never moved automatically.

---

## 6. Money rules

- **Security deposits are not revenue,** and deposit refunds are not costs.
- **Each booking has one "balance" row,** holding what is still unpaid. It feeds **Finance › To collect**. Marking it paid turns it into a normal payment.
- **All money figures come from one calculation:** `Booking::syncBalance()` works out paid, balance and payment status, so every screen agrees.
- **Cancelling never refunds anything automatically.** Deposit refunds are tracked separately. A refund of rental money after a cancellation is recorded by Finance by hand until a refund policy is agreed.
- **VAT is off by default.** When switched on, it uses the rate and the inclusive or exclusive mode from settings, and is stored on each quotation and booking.

---

## 7. Business settings

Nothing the business decides is hard-coded. Everything is in **Settings › Business settings** (Admin by default), and every save is written to the History with old and new values.

| Tab | What can be changed |
|---|---|
| Company & messages | Company name, address, phone, email, TRN, bank details; every WhatsApp message template (greeting, quotation, document request, booking details, payment reminder) with `{first_name}`, `{company}`, `{link}` placeholders |
| Bookings & returns | Pending payment holds the car (on); gap between bookings (0 min); default booking length (car 24 h, yacht 4 h); default trip length (120 min); late grace (30 min); late-charge rule (staff decide / fixed per hour / item hourly rate / one day per started late day); return charge lines; price-suggestion thresholds (hourly under 8 h, weekly from 7 days, monthly from 28 days, yacht half day 4 h, full day from 8 h) |
| VAT & quotations | VAT on/off, rate, inclusive; default quote validity; standard terms; quote extras; decline reasons |
| Documents | Document types (add, rename, which ones customers can upload); required types per booking type (none by default); block handover; upload link hours; rejection reasons |
| Lists | Lost reasons, cancel reasons, pick-up places, car types, brands and locations, yacht types and builders, marinas |
| Money categories | Income and expense categories (system ones come back if removed) |
| Roles & permissions | Which role can do each ability (see section 8) |

The values in brackets are the starting values. They keep the CRM behaving as before until someone changes them. Renaming an item never breaks old records, because each item keeps a fixed key.

---

## 8. Roles and permissions

Roles: Super Admin, Admin, Manager, Sales, Operations, Finance, Staff, Driver.

| Ability | Default roles |
|---|---|
| Manage leads | Admin, Manager, Sales, Staff |
| Manage bookings | Admin, Manager, Sales, Operations, Staff |
| Operate fleet (handover, return, readiness) | Admin, Manager, Operations, Staff |
| Record payments | Admin, Manager, Sales, Operations, Finance |
| Manage finance (accounts, revenue) | Admin, Manager, Finance |
| Review customer documents | Admin, Manager, Sales, Operations, Staff |
| Manage quotations | Admin, Manager, Sales, Staff |
| Edit fleet and prices | Admin, Manager |
| Delete records | Admin, Manager |
| Override availability / readiness | Admin, Manager |
| View history | Admin, Manager |
| Manage team and business settings | Admin |

- Super Admin can always do everything, and Admin always keeps Business settings.
- Drivers see only their own bookings.
- The whole matrix can be edited in Business settings.
- Permissions are enforced on the server, not only by hiding buttons.

---

## 9. Security and data safety

- **Permissions:**
  - Every resource and action checks the role's ability.
  - Saving without permission returns 403, even if the request is sent directly.
- **No double bookings:**
  - Availability is checked on the server on every save, inside a database transaction, with locking reads.
  - Tested with two processes saving at the same moment: the second is refused.
  - The same protection covers the last seats on a yacht trip.
- **Late returns:**
  - A car that is not back yet still counts as busy.
  - New bookings that start before it is back are refused.
- **Private files:**
  - Customer documents and handover or return photos are stored outside the public folder.
  - They open only through 10-minute signed links, for logged-in active staff.
  - The command `crm:secure-booking-files` moves old public files.
- **Upload links:**
  - Only a hash of the token is stored.
  - Links expire and can be switched off.
  - File type and size are checked, and links are rate-limited.
- **Price lock:** a sent quotation cannot be edited without "Revise". This is enforced in the model, not only in the form.
- **Audit log:** prices, statuses, bookings, payments, overrides and settings.
- **Deploy safety:**
  - The deploy script takes a database backup first, and stops if the backup fails.
  - Migrations are additive and reversible; no column is removed and no data is deleted.

---

## 10. Design and user interface

- **Brand:** red `#d7141a` for primary actions and the active page, gold `#d4a62a` only as a rare accent, Poppins font. Neutral surfaces everywhere else (redesign of 21 Oct).
- **Themes:** dark by default, light fully designed. Dark uses deep slate surfaces (workspace `#101216`, sidebar `#151820`, cards `#1B2029`). A light/dark switch sits in the top bar.
- **Menu:** Overview, Sales, Operations, Finance, Administration. Shortcuts for Car readiness and To collect. Items show only for roles that may open them.
- **Sidebar:** white in light theme, slate in dark. The active page has a soft red tint and a short red bar. It works collapsed (icons with tooltips) and on phones, where each page starts with the menu closed.
- **Page headers:** smaller titles, one-line descriptions, actions on the right. Secondary buttons (WhatsApp, Call, Record payment) are neutral; the next step is the one red button.
- **Avatars:** initials drawn by the CRM itself; no outside avatar service.
- **Dashboard:** "today first", as in [section 3](#dashboard), as a plain card without gradients or watermark.
- **Sign-in page:** plain neutral background, red top line.
- **Mobile:**
  - Checked at 390 px wide, with no sideways scrolling.
  - Lead stats show two per row on phones.
- **Customer pages:** the quotation and upload pages are light, branded and printable.
- **Styling:** all custom CSS is in `public/css/crm.css`. It is plain CSS with no build step, versioned by file time so browsers pick up changes.

---

## 11. Technical stack and code map

| Part | Choice |
|---|---|
| Framework | Laravel 12, PHP 8.2+ |
| Admin panel | Filament 3.3, at `/crm` |
| Database | MySQL / MariaDB, timezone Asia/Dubai |
| Packages | spatie/laravel-permission (roles), spatie/laravel-medialibrary (photos) |
| Hosting | Hostinger shared hosting (staging) |

**Where things live**

| Area | Path |
|---|---|
| Screens (resources) | `app/Filament/Resources/*Resource.php` |
| Dashboard and pages | `app/Filament/Pages/` (Dashboard, PipelineBoard, AccountsOverview, BusinessSettings) |
| Dashboard widgets | `app/Filament/Widgets/`, views in `resources/views/filament/widgets/` |
| Handover / return logic | `app/Support/BookingFlow.php`; buttons in `BookingResource/BookingActions.php` |
| Availability and clashes | `app/Support/Availability.php` |
| Booking rules from settings | `app/Support/Rules.php` |
| Editable lists, templates, document types, charges | `app/Support/Options.php` |
| Permissions | `app/Support/Access.php` |
| Phone matching and WhatsApp links | `app/Support/Phone.php` |
| VAT | `app/Support/Tax.php` |
| Customer pages (quote, upload) | `app/Http/Controllers/`, views in `resources/views/public/` |
| Routes | `routes/web.php` (private files, upload link, quotation page) |
| Deploy script | `scripts/deploy-hostinger.sh` |
| Custom styling | `public/css/crm.css` |

**Conventions**
- Filament injects closure parameters **by name**. Use `$query`, `$record`, `$get`, `$set`, `$state`, `$data` and `$arguments`.
- Enum form defaults use `->value` strings.
- Morph aliases: car, yacht, trip, customer, lead, user, transaction, booking.
- UI text is plain English for staff.

---

## 12. Database

**Main tables:**

| Group | Tables |
|---|---|
| Staff | users, roles and permissions |
| Fleet and trips | cars, yachts (history only), trips, trip_dates, packages, package_categories, package_departures, car_maintenance, car_driver |
| Sales | lead_sources, leads, lead_activities, customers, quotations |
| Bookings and money | bookings, transactions |
| Documents | customer_documents, document_requests |
| Website | categories |
| System | settings, audit_logs, media |

Migrations run from `2026_10_15_*` to `2026_10_17_000002`. All of them are additive and can be rolled back. Upgrading from the old version and rolling back were both tested on a copy of the old database.

---

## 13. Deployment (Hostinger staging)

- **Live URL:** `https://crm.redprimerentals.com/crm` (the old staging address `darkviolet-llama-836605.hostingersite.com` is no longer used)
- **App path:** `~/domains/crm.redprimerentals.com/public_html` (the root `.htaccess` rewrites to `/public`)

**How an update goes live**
1. **Upload the changed files.** Crew uploads them through the Hostinger File Browser and checks each file on the server by SHA-256. SSH is not used from the agent side.
2. **Bilal runs the deploy script:**
   ```
   cd ~/domains/crm.redprimerentals.com/public_html
   bash scripts/deploy-hostinger.sh          # add --demo for sample data
   ```
   The script:
   - clears stale caches
   - takes a database backup to `storage/app/backups` (and stops if that fails)
   - runs composer (`--no-scripts`, because `proc_open` is disabled), `package:discover` and `filament:upgrade`
   - runs migrations and secures old booking files
   - rebuilds the caches
3. **Check the result:** look at `storage/logs/laravel.log` for new errors and smoke-test the changed pages.

**Rules**
- New routes, pages or widgets need the deploy script (or `optimize:clear`). Otherwise the server keeps using the old cache.
- Ask Bilal before every `git push`. Local commits are fine.

---

## 14. Testing

- **Automated suite:**
  - `php vendor/bin/phpunit`, which needs an empty MySQL/MariaDB database `rp_testing`.
  - **80 tests, all passing** (last run 17 Oct 2026).
  - They cover leads and customers, bookings and clashes, quotations, handover, return and payments, documents and private files, every screen and widget, permissions, and the dashboard for all 8 roles.
- **Race tests:** in `tests/Concurrency/`.
  - `same-car.php`: two saves for the same car and dates. The second is refused.
  - `last-seats.php`: two bookings for the last trip seats. The second is refused.
- **Browser checks:** every screen at 1366 px and 390 px, in dark and light themes.
- **Checklist status:** `docs/CHECKLIST-STATUS.md` gives the status of every item in the development checklist.

**Live test on staging (17 Oct 2026)**

| Step | Result |
|---|---|
| Create lead, duplicate phone in another format | Works ("Existing customer: …" shown) |
| Lost without a reason | Refused (message wording fixed) |
| Pipeline: move a card | Works |
| Quotation from lead (pre-filled), extras, discount, advance | Works (total AED 9,700) |
| Send → price locked → WhatsApp link | Works |
| Customer quotation page | Works (the WhatsApp reply button needs the company phone in settings) |
| Customer accepted → Convert to booking with advance | Works (booking created, customer linked, lead Confirmed, car Reserved) |
| Upload link: wrong file type | Refused |
| Upload link: image upload | Works |
| Approve document | Works |
| View document | **Was broken (403).** Fixed and uploaded; needs a check after deploy |
| Record payment | Works |
| Handover, return, cleaning, balance, deposit refund, yacht trip, clash on live | **Not yet done.** The session timed out during handover |

---

## 15. Project history

| Date | Work |
|---|---|
| 15 Oct 2026 | Laravel 12 setup, Hostinger deployability, logo and brand colours, Cars module, M1 modules (yachts, trips, customers, leads), deploy script |
| 16 Oct 2026 | Accounts; sales pipelines; bookings (self-drive and with driver); handover and return; dashboard; CRM workflow guide; audit and plan |
| 16 Oct 2026 | Security fixes, quotations, customer documents and upload link, readiness, drivers, history |
| 16 Oct 2026 | "Nothing hard-coded": Business settings for all rules, lists, document types, messages and roles |
| 17 Oct 2026 | App installed and tested for real: 9 bugs fixed, price thresholds moved to settings, automated test suite added |
| 17 Oct 2026 | Deploy fix for a stale config cache |
| 17 Oct 2026 | New look: dark cabin sidebar, softer cards and tables, branded sign-in page |
| 17 Oct 2026 | Dashboard redesign: today console, today-first layout, trips strip, ranked lead sources |
| 17 Oct 2026 | Live test fixes: private file links behind the Hostinger proxy, "last payment" date, lost-reason message, menu closed on phones, an old note on the customer page |
| 21 Oct 2026 | Yacht Trips without yacht inventory; Trips & Packages module; public website API v1 (`docs/API.md`) |
| 21 Oct 2026 | Redesign: neutral light and dark themes, new menu groups, global Documents and History lists, theme switch |

All commits are local. Nothing has been pushed to GitHub yet (waiting for Bilal's approval).

---

## 16. Current status

**Done and working**
- All M1 modules.
- All M2 operational modules (pipelines, bookings, operations, payments, quotations, documents, readiness, drivers, history, dashboard, roles).
- Business settings for every business rule.
- Yacht Trips without yacht inventory, Trips & Packages, and the website API (21 Oct).
- Neutral redesign of the shell, dashboard and lists (21 Oct).

**Waiting**
- **Deploy after the latest upload** (21 Oct work: includes a new migration). Bilal needs to run the deploy script once, then confirm.
- **Rest of the live test:** handover, return with cleaning, pay balance, deposit refund, yacht trip seat booking, clash check, and the `laravel.log` review. Needs a fresh CRM login in the built-in browser.
- **Company details in Business settings** (phone, address, email, TRN, bank). They show on the quotation page and enable its "Reply on WhatsApp" button.
- **GitHub push,** after approval.

**Not built yet (planned)**
- **Tasks and automation (A12)** and **Reports (A13)**: M2 items still to do.
- **Booking extension** and **trip departure status**.
- **Email sending:** no mail setup yet. Quotations and links go by WhatsApp.
- **The website itself (M3).** The CRM side of the connection (API) is ready; see `docs/API.md`.

**Known small items**
- **Early handover:** a booking can be handed over days before its start date, with no warning. Needs a business decision.
- **Narrow screens:** in the documents table, row actions (Approve or Reject) need a sideways scroll.
- **Car documents:** the car form still mentions scan uploads "in Milestone 2". Car document scans are not yet uploadable; only the expiry dates are tracked.

---

## 17. Open decisions for RED PRIME

These are not assumed anywhere. Each one can be set in Business settings, or needs a short answer.

| # | Question | Now |
|---|---|---|
| H-1 | When is a returned car available again: straight away, or after cleaning and inspection? | Readiness step built; staff choose at return |
| H-2 | Gap between bookings | Setting, 0 min |
| H-3 | Which documents are required for each booking type, and should missing ones block handover? | Setting, none required |
| H-4 | VAT: on or off, the rate, inclusive or exclusive | Setting, off |
| H-5 | Late-return charge rule | Setting, staff type it |
| H-6 | Does Pending payment hold the car? | Yes (Bilal: "hold karay") |
| H-7 | Website booking requests: always wait for staff? | Built that way: a request is a lead, staff confirm |
| H-8 | Refund approval threshold | Not built; refunds by permission only |
| H-9 | Role matrix | Editable in settings |
| H-10 | Default driver per car | Built ("usual drivers") |
| H-11 | Seasonal or special-date rates | Not built (change request if wanted) |
| H-12 | Wording of the Milestone 1 notice | See the audit, section B.3 |
| New | Cancellation and refund policy for rental money | Not decided |
| New | Should early handover be allowed, warned or blocked? | Allowed, no warning |
| New | Delete old public copies of booking photos (`crm:secure-booking-files --remove-public-copies`)? | Kept until confirmed |

---

## 18. Not built, by agreement

These are excluded from scope, so do not add them without a new agreement:

- customer portal
- driver app
- payroll
- commissions
- general ledger

---

## 19. Other documents

| File | What it is |
|---|---|
| `CLAUDE.md` | Working rules for the developer agent (approval, no invented policy, deploy steps) |
| `docs/MASTER-PROMPT.md` | The full original brief |
| `docs/CRM-Audit-and-Plan.md` | Audit, scope matrix, business rules, phases, decisions H-1 to H-12 |
| `docs/CRM-Workflow.md` | Staff guide to the original booking flow (quotations section now outdated; see section 4 here) |
| `docs/CHECKLIST-STATUS.md` | Status of every development checklist item |
| `docs/DEVLOG.md` | Detailed log of every work session, the bugs found and the tests run |
| `docs/API.md` | Public website API contract (v1) |
| `docs/REDESIGN-AUDIT.md` | Audit behind the 21 Oct redesign and business-model correction |
