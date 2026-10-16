# RED PRIME RENTALS CRM: Audit Report and Implementation Plan

**Prepared:** 16 October 2026 · **Status:** Phase A (audit and planning only) · **Awaiting approval**

**Sources reviewed**
- The application source code (Laravel 12, Filament 3.3, MySQL, latest commit `bc6c9f3`)
- All 21 database migrations
- `CRM-Workflow.md`
- `CRM_Agreement_REDPRIME.pdf`: clauses 3.2 and 7.3, Schedule A1–A14
- The staging server's file structure and error log

**Not yet verified**
- **The live CRM after today's deploy.** Testing needs a staff login, which has not been done.
- **The public website.** There is no website in this repository yet; the design will be shared at Milestone 3. Section 18 of the brief (website audit) therefore cannot be carried out, and no website findings are claimed here.
- **The live server's `.env` settings** (debug mode, environment). The file manager session expired before these could be checked.

No code, schema, migration or live data was changed while preparing this report.

---

## Contents

- [Summary](#summary)
- [Section A: Existing system](#section-a--existing-system)
- [Section B: Agreement and scope](#section-b--agreement-and-scope)
- [Section C: Workflow maps](#section-c--workflow-maps)
- [Section D: Business rules](#section-d--business-rules)
- [Section E: Gaps, defects and risks](#section-e--gaps-defects-and-risks)
- [Section F: Proposed technical changes](#section-f--proposed-technical-changes)
- [Section G: Implementation plan](#section-g--implementation-plan)
- [Section H: Decisions required](#section-h--decisions-required)
- [Section I: Approval request](#section-i--approval-request)

---

## Summary

**The core CRM is built and deployed to staging.** It covers:
- cars, yachts and yacht trips
- leads with the three Schedule A3 pipelines
- customers
- bookings, with handover and return
- accounts (income, expenses, money to collect)
- a dashboard

**Built ahead of schedule.** Bookings, Operations, Payments and Pipelines are Milestone 2 modules under clause 7.3(b). They were built early at the client side's request. This affects how the Milestone 1 completion notice should be worded (see B.3).

**Most serious findings (full list in Section E):**

1. **Customer documents are publicly reachable.** Booking documents (passport, Emirates ID, licence) and handover photos are saved in the public `uploads` folder. Anyone with the file URL can open them. *(High)*
2. **There are no permissions.** Every active staff user can do everything except manage the team, including refunds, deletions and price changes. There is also no audit trail. *(High)*
3. **Double booking can still happen under simultaneous saves.** The clash check runs only in the form. Two staff saving at the same moment can both pass. The same applies to yacht trip seats. *(High)*
4. **Yacht trips and private charters do not block each other.** A trip and a charter on the same yacht can overlap. *(High)*
5. **The return is one step.** Receiving the car, inspecting it, settling payment and releasing it all happen in one action. The car becomes Available immediately unless staff tick "Send to maintenance". The brief asks for these to be separate; Bilal's earlier instruction was "available as soon as it is received". This needs a decision (H-1).
6. **Two charges and one hold use rules nobody approved:**
   - The late-return charge is pre-filled as hours late × the car's hourly rate.
   - A "Pending payment" booking holds the car.
   - Both need business approval (H-5, H-6).
7. **Car status mixes two meanings.** "Reserved" is set as soon as any future booking exists, even one weeks away. That status mixes *booking allocation* with *readiness* (E-9).
8. **Missing features:** maintenance does not block bookings, there are no preparation buffers, no tax, no booking extension, and no automated tests beyond the framework examples.

**Recommended next step:** approve **Phase 1** (security and integrity fixes, about 2.5 days). It has no scope impact and protects real data before the client starts using the system. Then decide Section H before Phase 2.

---

## Section A — Existing system

### A.1 Architecture

| Layer | Implementation |
|---|---|
| Framework | Laravel 12 (PHP 8.2+), timezone Asia/Dubai |
| Admin UI | Filament 3.3 panel at `/crm`. Livewire and Alpine. Dark theme by default, light theme supported. Brand colours red `#d7141a` and gold `#d4a62a` |
| Database | MySQL, with soft deletes on business records |
| Auth | Laravel session login through Filament. `User.is_active` controls access |
| Roles | spatie/laravel-permission with 8 roles: Super Admin, Admin, Manager, Sales, Operations, Finance, Staff, Driver. **No permissions are attached.** Super Admin passes every check; only "Team & roles" is restricted |
| Files | spatie/laravel-medialibrary on the `uploads` disk, which is `public/uploads` (public) |
| Hosting | Hostinger shared hosting. Deployed by file upload plus `scripts/deploy-hostinger.sh` (composer, migrate, optimize). No SSH from the developer side |
| Website | Not started. `routes/web.php` only has the default welcome page. **There are no API routes** |
| Integrations | None live. WhatsApp and phone actions are `wa.me` and `tel:` links only. There is no email, Meta, TikTok or payment gateway integration |
| Tests | Only Laravel's two example tests |
| Audit log | None |

### A.2 Database and relationships

```
users ─┬─< leads (assigned_to) ─────┐
       ├─< customers (assigned_to)  │
       └─< bookings (driver_id, assigned_to, delivered_by, received_by)
lead_sources ─< leads, customers
leads ─< lead_activities
leads >─ customers (customer_id)                leads ─(morph interested_in)→ car|yacht|trip
customers ─< bookings ─< transactions           bookings ─(morph bookable)→ car|yacht|trip
customers ─< transactions                       transactions ─(morph related)→ car|yacht|trip
cars ─< car_maintenances                        trips >─ yachts;  trips ─< trip_dates ─< bookings
categories (website sections: cars / yachts / yacht trips, enable/disable)
media (polymorphic: car/yacht/trip gallery; booking delivery/return/documents)
roles, permissions, model_has_roles (spatie)
```

Morph aliases: `car`, `yacht`, `trip`, `customer`, `lead`, `user`, `transaction`, `booking`.

### A.3 Module-by-module audit

**Leads and pipeline (M1, A2/A3)**
- **Exists and works:**
  - capture of contact, source, interest, dates, guests and budget
  - duplicate warning by phone or email (warns only, never merges)
  - activities, which stamp the last-contacted time
  - follow-ups, priority and salesperson
  - the three A3 pipelines; the overall status (New / Contacted / Qualified / Won / Lost) follows the stage
  - a drag-and-drop board and lost reasons
  - automatic customer creation when a deal is won
  - the lead moves forward on its own as its booking progresses
- **Partial:**
  - duplicate matching compares raw text, so `+971 50…` and `050…` do not match
  - "self-drive or with-driver" is not captured on the lead; it is chosen on the booking
- **Missing:** none of the social or WhatsApp channels feed leads in automatically. These are M3 integrations.
- **Affected by change:** the stage and status mapping. Lead history is preserved because status is calculated from the stage.

**Customers (M1, A1)**
- **Exists:**
  - contact details, type (Individual / Corporate / Agency), nationality, residency, language
  - VIP flag, tags, notes, follow-up
  - leads, bookings and payments tabs
  - total spend and last payment
- **Missing:**
  - customer documents (A11, M2); there is no media collection on the customer
  - quotations (A8, M2)
  - communication history beyond lead activities

**Cars (M1, A4)**
- **Exists:**
  - full vehicle data and images
  - daily, weekly, monthly and hourly prices, plus with-driver day and hour rates
  - self-drive and/or with-driver flags
  - deposit, km limit and extra-km rate, Salik, delivery
  - document expiry alerts, maintenance log, ownership and partner details
  - publish / unpublish, SEO fields, status
  - a booking column and a Bookings tab
- **Partial:**
  - **Status mixes concepts:** a single field holds Available / Reserved / Rented / Maintenance / Unavailable. Bookings change it automatically, and staff can also change it by hand.
  - **Maintenance:** being in maintenance does not stop a booking from being saved; the form only shows a warning.
- **Missing:** seasonal rates, preparation and turnaround buffers, and a calendar view.

**Yachts (M1, A5)**
- **Exists:** yacht data; hourly, half-day and full-day prices; mandatory extra charges; deposit; crew and captain; documents; status; publishing.
- **Missing:** yacht trips on the same yacht are not counted in yacht availability (E-4), and there is no crew scheduling.

**Yacht trips (M1, A6)**
- **Exists:**
  - trip data, price per person or per trip, departure dates with available seats
  - a section on/off switch (Category) that never deletes records
  - publishing
  - bookings by date with a seats-left check
- **Partial:** the seats check is form-only (E-3).
- **Missing:** a trip status (scheduled / departed / completed / cancelled) per departure, and a link to yacht availability.

**Bookings (M2, A7, built early)**
- **Exists:**
  - all eight A7 statuses
  - customer, lead, service, item, trip date, self-drive or driver, dates and locations, guests
  - price from the rates, with extras, discount and deposit
  - an advance payment at creation
  - a clash check for cars and yachts, and a driver clash check
  - automatic car or yacht status
  - a lead stage update
  - a balance kept in Accounts
- **Partial:** "Pending payment" holds the car (H-6).
- **Missing:**
  - tax
  - booking extension or modification history
  - document checklist and gating
  - a confirmation step for website requests (no website yet)
  - a price snapshot or audit of adjustments

**Drivers (A10)**
- **Exists:** users with the Driver role, assignment per booking, and a clash check.
- **Missing:** default driver-to-car assignment (brief §7.2), and a driver profile beyond the user record (name, phone, active).

**Handover and return (M2, A10, built early)**
- **Exists:**
  - **Handover:** time, km, fuel, driver, deposit and payment received, photos, notes.
  - **Return:**
    - time, km, fuel, condition, photos, notes
    - charges: extra km (calculated from the car's km limit), fuel, late, Salik and fines, damage, cleaning, other
    - payment check, deposit deduction, refund now or hold, optional send to maintenance
- **Issue:** all of the return happens in one action, and the car is released immediately (E-5).
- **Issue:** the late charge is pre-filled using an unapproved rule (E-6).
- **Missing:** missing-items and discrepancy findings, and a separate "operational clearance" step.

**Accounts and payments (M2, A9, built early)**
- **Exists:**
  - income and expenses with categories, methods and statuses (Paid / Partially paid / Pending / Overdue / Refunded)
  - deposits excluded from revenue, and deposit refunds excluded from costs
  - each booking keeps one system "balance due" row; marking it paid turns it into a payment
  - charts, top earners, To collect, WhatsApp reminders
- **Missing:**
  - refund approval
  - protection against recording the same payment twice
  - tax recording
  - payment deadlines or an overdue policy (overdue is currently date-based only)

**Dashboard (M2, A13 partly)**
- **Exists:** a welcome banner with key numbers; lead trends and sources; fleet status; my follow-ups; upcoming trips; today's handovers and returns; recent leads.
- **Missing:** reports (A13 is M2), a list of vehicles awaiting inspection, and operational conflict alerts.

**Roles and permissions (M2, A14)**
- **Exists:** role names only.
- **Missing:** actual permission rules and policies.

**Documents (M2, A11)**
- **Exists:** booking-level uploads (agreement, ID, licence) and car or yacht document expiry dates.
- **Missing:** a secure upload link, customer-level documents, review statuses, checklists by booking type, and private storage (E-1).

**Notifications and automation (M2, A12)**
- **Exists:** none. Badges and dashboard lists only.

**Website and API (M2 controls / M3 connection)**
- **Exists:** CRM-side publishing, SEO and category switches only.
- **Missing:** website and APIs.

---

## Section B — Agreement and scope

### B.1 Milestones (clause 7.3)

- **M1:** CRM architecture and database, plus five modules: Car Rental, Yacht Rental, Yacht Trips & Experiences, Customer Management, Lead Management.
- **M2:** all remaining clause 3.2 modules, CRM-side website controls (publishing, pricing, availability, categories), and about 10% of the website.
- **M3:** the rest of the website, the website-to-CRM connection, social and communication integrations, acceptance testing, deployment and handover.

### B.2 Scope matrix

Categories: **1** existing and working · **2** existing, needs correction · **3** contracted, not yet built · **4** improvement within an existing requirement · **5** later milestone · **6** possible change request · **7** decision pending.

| Item | Contract ref | Milestone | Category |
|---|---|---|---|
| Car / Yacht / Trip data, pricing, publishing, category on/off | A4–A6, 4.6 | M1 | 1 |
| Car statuses Available / Reserved / Rented / Maintenance / Unavailable | A4 | M1 | 2 (E-9) |
| Calendar and conflict checking | A4 "where technically practical" | M1 | 2 (E-3, E-4); calendar view 3 |
| Customer record and history | A1 | M1 | 1 (quotes and documents 5) |
| Lead sources and lead management | A2 | M1 | 1 (automatic capture 5, M3) |
| Sales pipelines | A3, clause 3.2 module | M2, built early | 1 |
| Booking Management | A7 | M2, built early | 1 / 2 |
| Operations: delivery, return, driver, handover notes | A10 | M2, built early | 1 / 2 (E-5, E-6) |
| Payment / Finance recording | A9 | M2, built early | 1 / 2 |
| Dashboard / KPIs | clause 3.2 | M2, built early | 1 |
| Quotation Builder (PDF, statuses) | A8 | M2 | 5 |
| Documents (passport, EID, licence…) | A11 | M2 | 5. The secure upload link is a 4 |
| Tasks and automation | A12 | M2 | 5 |
| Reports and analytics | A13 | M2 | 5 |
| Internal user roles and permissions | A14 | M2 | 5. Minimum lock-down is a security fix (Phase 1) |
| CRM-side website controls | 7.3(b) | M2 | partly 1 |
| Website ↔ CRM API and booking requests | 7.3(c), A7 | M3 | 5 |
| Tax on quotations and bookings | A8 "applicable tax" | M2 | 5 / 7 |
| Preparation and turnaround buffers | brief §14.3 | not in contract | 4 or 6 / 7 |
| Seasonal or special-date rates | brief §6.3 | not in A4 (daily, weekly, monthly only) | **6** |
| Default driver-to-car assignment | brief §7.2 | A10 lists "driver" only | **6** (small) |
| Refund approvals and thresholds, cancellation policy engine | brief §16.5, §17 | A9 is "recording only" | **6** / 7 |
| Customer document upload link (no portal) | brief §13 | A11 "where technically implemented" | 4 (M2) |
| Audit trail of sensitive actions | brief §20 | not explicit | 4 (recommended) |
| Booking extension | brief §14.2 | implied by A7 | 3 |
| Trip departure status | brief §14.6 | A6 "status" | 3 |
| Customer portal, driver app, payroll, ledger | brief §28 | excluded | not built |

### B.3 Milestone note for the client notice

Clause 7.4 links payment to the completion notice for the five M1 modules. Those five are done.

Bookings, Operations, Payments, Pipelines and the Dashboard are M2 modules that were delivered early. **Recommendation:** list them in the M1 notice as "delivered ahead of schedule as part of M2", so M2 acceptance later covers them. Do not fold them into M1, or the scope boundary gets lost.

---

## Section C — Workflow maps

**Legend:** `[ ]` is a staff action, `( )` is automatic, `⚠` is a gap or decision.

### C.1 Leads and customers

```
Source (manual now; website/social ⚠M3) → [Add lead] → (duplicate warning by phone/email)
→ (pipeline from service: car→Car, yacht/trip→Yacht; B2B chosen by staff) → New Lead
→ [Log activity] (car: → Contacted) → [Move stage on board / form]
→ Won stage (Confirmed / Agreement) → (customer created or linked, never duplicated)
→ Lost → [reason required]; history kept
```

### C.2 Car rental, as built today

```
Lead → [Create booking] (prefilled) → [self-drive | with driver + driver]
→ (price from rate card; staff can change) → [status Confirmed / Pending payment]
→ (clash check: form level only ⚠E-3; maintenance only warns ⚠E-8)
→ (car → Reserved ⚠E-9; lead → Confirmed; balance → Accounts/To collect)
→ [Hand over: km, fuel, deposit, payment, photos] → (car → Rented; lead → Vehicle Delivered)
→ [Receive car: km, fuel, condition, charges, payment, deposit use/refund/hold, photos]
→ (booking Completed; car → Available immediately ⚠E-5, or Maintenance if ticked)
→ (any balance stays in To collect) → [Record payment] → Paid
```

### C.3 Car rental, proposed after Phase 3 (pending H-1)

```
… Hand over → Rented
→ [Receive vehicle] (time, km, fuel, photos)          → vehicle "Returned – pending inspection"
→ [Complete inspection] (findings, charges confirmed) → if issue: restricted until cleared
→ [Settle] (payment / deposit deduction / refund / hold) — may stay outstanding
→ [Release] (only when no restriction; respects buffer) → Available
```

### C.4 Yacht charter

```
Lead (Yacht pipeline) → [Create booking] (hourly / half day / full day)
→ clash check vs other charters ⚠ not vs trips (E-4)
→ [Start charter] (deposit / payment) → [End charter] (charges, settlement) → Charter Completed
→ (Balance Paid stage reached when fully paid)
```

### C.5 Yacht trips

```
[Create trip + departure dates with seats] → [Book: date + guests]
→ (seats-left check: form level ⚠E-3) → (price per guest or per trip) → payment
→ trip on/off switch hides the section only; data is kept
⚠ no per-departure status; ⚠ trip does not block the yacht for private charters (E-4)
```

### C.6 Quotations (not built; M2, A8)

```
[Create quotation] → Draft → Sent (PDF via WhatsApp/email link) → Negotiation
→ Accepted (price locked) → (re-check availability) → [Convert to booking at locked price]
→ Rejected / Expired (auto on valid-until date). Quotation never holds inventory.
```

### C.7 Document collection (not built; M2, A11)

```
[Generate secure link] → customer uploads on mobile page (no account) → Received / Under review
→ [Approve | Reject | Request replacement] → checklist on booking shows what is missing
→ (optional, per H-3) block Confirm / Hand over when mandatory docs are missing
```

### C.8 Driver assignment

```
Today:    booking (with driver) → [pick driver] → (driver clash check)
Proposed: driver profile → default cars (⚠6) → booking suggests driver for car / cars for driver → override allowed
```

### C.9 Payments, deposits and refunds, as built

```
Booking total + return charges = amount due
Payments (rental/advance/charges) reduce balance → system "balance due" row in To collect
Deposit in (income, not revenue) → deduction (revenue + deposit-out) → refund (deposit-out)
⚠ no refund approval, no duplicate-submit guard, no tax
```

### C.10 Cancellation, as built

```
[Cancel booking] (reason; Cancelled or No-show) → (inventory released; balance row removed)
→ money already received: staff record refund manually in Accounts ⚠ no refund workflow
```

### C.11 Website booking request (not built; M3)

```
Website form → API (validate, idempotency key) → find/create customer + lead
→ re-check availability → booking status Inquiry/Pending (never "confirmed" unless rules allow)
→ staff confirm → (re-check again at confirmation, inside a lock)
```

---

## Section D — Business rules

Every new rule is marked **Proposed — Pending Approval**. "Built" means it is in the code today.

| ID | Rule | Current | Proposed | Source | Status | Acceptance test |
|---|---|---|---|---|---|---|
| R-01 | A lead's status follows its pipeline stage | Built | Keep | A3 | Built | Moving a card to Confirmed shows status Won and creates or links the customer |
| R-02 | Duplicate contacts warn but never merge | Built (exact text) | Normalise numbers (+971 / 0 / spaces) before comparing | brief §4.2 | Proposed — Pending Approval | `050 123 4567` and `+971501234567` trigger the warning |
| R-03 | A car or yacht cannot have two overlapping holding bookings | Form check only | Server check inside a database transaction with row locks on the asset | A4, brief §6.4 | Proposed — Pending Approval | Two simultaneous saves: one succeeds, one gets a clash error |
| R-04 | Trip guests cannot exceed seats | Form check only | Same locked check on the trip date | A6, brief §9.3 | Proposed — Pending Approval | 20 seats with 15 booked: 6 guests rejected, 5 accepted, under concurrency too |
| R-05 | A trip departure blocks its yacht for private charters, and the reverse | Not enforced | Treat a departure as yacht occupancy | brief §9.3 | Proposed — Pending Approval | A charter overlapping a trip on the same yacht is rejected |
| R-06 | Which booking statuses hold inventory | Pending payment, Confirmed, In progress | Decision H-6 | brief §11.4 | Decision | — |
| R-07 | An asset in Maintenance / Unavailable cannot take a new holding booking | Warning only | Block, with a permission-based override that is logged | brief §14.4 | Proposed — Pending Approval | Booking a Maintenance car fails without the override permission |
| R-08 | A maintenance restriction added over a future booking raises a conflict, never a cancellation | Not built | Conflict alert on the dashboard | brief §14.4 | Proposed — Pending Approval | Setting Maintenance with a future booking shows the alert |
| R-09 | Return: receipt, inspection, settlement and release are separate steps | One step; auto-release | Decision H-1 | brief §15 | Decision | — |
| R-10 | Car status keeps booking allocation separate from readiness | One mixed field | Readiness field (Ready / Inspection due / Cleaning / Maintenance / Off-fleet) plus booking state calculated from bookings | brief §3.2, §14.6 | Proposed — Pending Approval | A car booked for next month still shows Ready today with "Next booking: …" |
| R-11 | Late-return charge uses an approved formula only | Pre-filled as hours × hourly rate | Decision H-5. Until then, leave empty for staff to enter | brief §15.4 | Decision | — |
| R-12 | Extra-km charge = (km driven − days × daily limit) × rate | Built, from car settings | Keep; show it as an estimate staff confirm | A4 car rules | Built | 970 km over 3 days at 250/day and AED 2/km gives AED 440 |
| R-13 | Security deposits are never revenue; deductions become revenue only when applied | Built | Keep | brief §15.5 | Built | Deposit received does not change the revenue chart |
| R-14 | A booking is Paid only when its balance is 0, not when a deposit is received | Built | Keep | brief §6.5 | Built | Deposit only: payment status stays Pending |
| R-15 | One payment per submit | Not guarded | Idempotency key on payment actions; disable the button while it saves | brief §16.6 | Proposed — Pending Approval | A double-click creates one payment |
| R-16 | Refunds above a threshold need approval | Not built | Decision H-8 (possible change request) | brief §16.5 | Decision | — |
| R-17 | Cancelling never auto-refunds | Built (manual refund) | Keep; add a "Refund due" flag | brief §17.5 | Built | Cancelling a paid booking shows Refund due, with no automatic refund |
| R-18 | Customer documents are private | Public folder | Private disk, served only to logged-in staff through a signed route | brief §13.5 | Proposed — Pending Approval | A direct file URL without login returns 403 or 404 |
| R-19 | Sensitive actions are permission-controlled | No permissions | Minimum role matrix (Section F) | A14 | Proposed — Pending Approval | A Sales user cannot refund, delete or edit rates |
| R-20 | Sensitive changes are audited | None | Activity log: who, what, old and new values | brief §20 | Proposed — Pending Approval | A price change on a booking appears in its history |
| R-21 | An accepted quotation locks the price but not the asset | n/a | Re-check availability at conversion | A8, brief §10.4 | Proposed (M2) | Accepting a quote for a booked car shows a conflict, and the price stays locked |
| R-22 | Tax is configurable and recorded per transaction | None | Decision H-4 | A8 | Decision | — |
| R-23 | A late return never moves the next booking; it flags a conflict | Not built | Dashboard alert "Car X late; next booking BK-… at …" | brief §14.3 | Proposed — Pending Approval | Overdue return with a booking today: the alert shows both |
| R-24 | Preparation and turnaround buffer between bookings | None | Decision H-2 | brief §14.3 | Decision | — |

---

## Section E — Gaps, defects and risks

Severity: **High** means fix before real customer data goes in. **Medium** means fix in the next phase. **Low** means improvement.

| # | Finding | Severity | Notes |
|---|---|---|---|
| E-1 | Booking documents and handover/return photos are stored under `public/uploads`, so they are reachable by URL without login | **High** | Personal data (passport, Emirates ID, licence). The fix is a private disk plus authenticated download. Car and yacht galleries can stay public because the website needs them |
| E-2 | No permissions: every active user can delete, refund, change prices or edit rates. No audit trail | **High** | A14 is M2, but a minimum lock-down is a security matter |
| E-3 | Car and yacht clash checks and trip seat checks run only in form validation, with no lock or constraint, so a race can double-book | **High** | Fix with a transaction and `SELECT … FOR UPDATE` on the asset row. MySQL cannot enforce date overlap with a constraint |
| E-4 | Yacht trips are not part of yacht availability, so a charter can overlap a trip on the same yacht | **High** | — |
| E-5 | Return releases the car immediately; there is no inspection or clearance step | **Medium** | Matches Bilal's earlier instruction but conflicts with brief §15.6. Decision H-1 |
| E-6 | Late-return charge is pre-filled with an unapproved formula | **Medium** | Remove the pre-fill or replace it with the approved rule (H-5). Quick fix |
| E-7 | "Pending payment" bookings hold the car with no expiry | **Medium** | Decision H-6 |
| E-8 | A Maintenance or Unavailable car or yacht can still be booked (warning only) | **Medium** | — |
| E-9 | Car status mixes allocation with readiness. A booking 3 weeks away shows the car as Reserved today; manual status edits can contradict bookings | **Medium** | Proposal R-10 |
| E-10 | Payment actions can create duplicates on retry or double-submit | **Medium** | — |
| E-11 | Duplicate check compares raw phone text | Low | — |
| E-12 | No booking extension; staff must edit the end date, which re-runs pricing with no history | Medium | — |
| E-13 | No tax anywhere | Medium | Needed with quotations (M2) |
| E-14 | No automated tests for the booking, balance and deposit logic | **Medium** | The money logic is the most fragile part; tests are needed before more changes |
| E-15 | Demo seed data and test users (`admin@redprime.test` / `change-me`, driver@redprime.test) are on the server; the DB password was shared in chat | **High** before go-live | Change the passwords; remove demo data before real data |
| E-16 | Live `.env` (APP_DEBUG / APP_ENV) not verified | Unknown | `.env.example` has debug on. If live debug is on, error pages leak details. Bilal can check, or I can when file access is restored |
| E-17 | Trip departures have no status (scheduled, departed, cancelled) | Low | — |
| E-18 | No deploy rollback beyond reversible migrations; no database backup step in the deploy script | Medium | Add `mysqldump` (or a Hostinger backup) before `migrate` |
| E-19 | Live test after today's deploy not done (login needed) | — | First item of Phase 1 |

---

## Section F — Proposed technical changes

Minimum changes only. Every migration is additive and reversible; no table or column is dropped.

### F.1 Security and integrity (Phase 1)

**Private documents**
- Add a `private` disk at `storage/app/private`.
- Booking `documents`, `delivery` and `return` collections move to it.
- Files are served through an authenticated route that checks permissions.
- A one-off artisan command moves existing files. The old public copies are deleted only after the new copies are verified.
- **Rollback:** the command can move files back.

**Permissions** (spatie, policies on each resource)

| Ability | Super Admin | Admin | Manager | Sales | Operations | Finance | Staff | Driver |
|---|---|---|---|---|---|---|---|---|
| Leads / customers (edit) | ✓ | ✓ | ✓ | ✓ | view | view | ✓ | — |
| Bookings create / edit | ✓ | ✓ | ✓ | ✓ | ✓ | view | ✓ | own (view) |
| Hand over / receive | ✓ | ✓ | ✓ | — | ✓ | — | ✓ | — |
| Fleet, rates, publishing | ✓ | ✓ | ✓ | view | view | view | — | — |
| Payments record | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| Refunds, deposit deductions, deletes | ✓ | ✓ | ✓ | — | — | ✓ | — | — |
| Override clash / maintenance block | ✓ | ✓ | ✓ | — | — | — | — | — |
| Team & settings | ✓ | ✓ | — | — | — | — | — | — |

*This is a starting proposal; H-9 confirms it.*

**Audit trail**
- Use `spatie/laravel-activitylog` on bookings, transactions, rates and statuses.
- Show a History tab on bookings.

**Concurrency**
- Booking save and handover run inside `DB::transaction` with `lockForUpdate()` on the car, yacht or trip-date row.
- The overlap and seats check is re-run inside the lock, on the server.

**Yacht occupancy**
- Trip departures count as yacht occupancy using the trip's start time plus duration.
- The overlap query covers both charters and trips.

**Maintenance block**
- Server-side check when the asset status is Maintenance or Unavailable.
- Override only with permission; overrides are logged.

**Remove the unapproved late-charge pre-fill** (keep the "X hours late" hint).

**Payment de-duplication**
- Each action form gets a one-time token, and the server rejects reuse.
- Buttons are disabled while saving (Filament already does this).

**Tests**
- Pest feature tests for: clash, seats, balance sync, deposit maths, return flow, the cancel/refund flag, and permission denials.

**Deploy safety:** the deploy script takes a DB dump before migrating.

### F.2 Operations clarity (Phase 3, pending decisions)

**Readiness field (R-10)**

| | |
|---|---|
| Schema | new column `cars.readiness` and `yachts.readiness`: ready / inspection_due / cleaning / maintenance / off_fleet |
| Booking state | calculated from bookings, never stored |
| Migration | existing Maintenance / Unavailable map to maintenance / off_fleet; Available / Reserved / Rented map to ready |
| Old status column | kept in sync for one release for safety, then retired |

**Return in steps (if H-1 = separate)**

| | |
|---|---|
| Flow | "Receive car" sets readiness to inspection_due. "Complete inspection" confirms charges and settlement. "Release" sets it to ready |
| Schema | `booking_inspections` table (or JSON on the booking if kept light) |

**Other Phase 3 items**
- **Buffers (H-2):** `prep_minutes` per car or yacht category (settings), included in the overlap query.
- **Late-return conflict alert (R-23):** a dashboard widget.
- **Booking extension:** an action that records old → new end date and price, re-checks clashes, and keeps a history.

### F.3 Milestone 2 contracted items (Phase 2)

- **Quotations:** `quotations` table (number, customer, lead, booking, item, dates, line items JSON, subtotal, discount, tax, deposit, total, valid_until, status, sent_at, accepted_at, price snapshot); a branded PDF (dompdf); a WhatsApp/email share link (signed, read-only page); convert to booking (R-21).
- **Documents:**
  - `customer_documents` (type, status, file on the private disk, reviewed_by, reviewed_at, reason)
  - `document_upload_links` (hashed token, expiry, revoked_at, scope customer/booking)
  - a mobile upload page with size and type limits and rate limiting
  - checklists by booking type (H-3)
- **Tasks and automation (A12):** a tasks table, and scheduled rules (Laravel scheduler via Hostinger cron) for follow-up, payment due, return due and late alerts. In-app notifications only, unless email is configured.
- **Reports (A13):** a reports page with filters (by period, source, salesperson, vehicle; utilisation where reliable).
- **Tax (H-4):** a tax setting with rate and inclusive/exclusive mode, stored per quotation, booking and transaction.
- **Website-side CRM controls:** already mostly built (publishing, categories, prices); add an "available from" or blackout setting if needed.

### F.4 Website and API (M3 — design only now)

Candidate API groups, to be finalised once the website build is known:

| Group | Endpoints (candidate) | Access |
|---|---|---|
| Inventory | `GET /api/v1/cars`, `/cars/{slug}`, `/yachts`, `/yachts/{slug}`, `/categories` | Public, published only, cached |
| Trips | `GET /api/v1/trips`, `/trips/{slug}/departures` (seats left) | Public |
| Availability | `POST /api/v1/availability` (item, dates) → available plus reason | Public, rate-limited |
| Requests | `POST /api/v1/booking-requests` (Idempotency-Key header) → reference, status "received" | Public, captcha plus rate limit |
| Documents | `POST /api/v1/uploads/{token}` | Token only |

**Rules**
- The website never writes inventory or prices.
- Website requests never confirm by themselves unless H-7 allows it.
- Every request is re-checked when staff confirm.

---

## Section G — Implementation plan

Each phase starts only after written approval. Day estimates assume one developer working with AI assistance, including tests and the staging deploy.

### Phase 1 — Security and integrity fixes (no scope change, M1/M2 quality)

| | |
|---|---|
| **Includes** | E-1 private documents · E-2 minimum permissions + audit log · E-3 locked clash and seat checks · E-4 trips block the yacht · E-6 remove the late pre-fill · E-8 maintenance block with override · E-10 payment de-duplication · E-11 phone normalisation · E-14 tests · E-18 backup in deploy · E-19 full live test |
| **Excludes** | Readiness redesign, inspection steps, quotations, documents module |
| **Files** | `Booking`, `Transaction`, `BookingFlow`, `BookingResource`, `BookingActions`, policies (new), `AppServiceProvider`, filesystems config, a file-move command, tests (new), deploy script |
| **Database** | `activity_log` table (package migration); no changes to existing tables |
| **Risks** | Moving files breaks old links: the command verifies before deleting. Permissions may lock someone out: Super Admin always passes and roles are seeded first |
| **Acceptance** | R-03, R-04, R-05, R-07, R-15, R-18, R-19, R-20 tests pass; live smoke test of every page; document URL without login is denied |
| **Milestone** | Quality work on delivered modules. No change request |
| **Effort** | about 2.5 days |

### Phase 2 — Milestone 2 contracted modules

| | |
|---|---|
| **Includes** | Quotations (A8), Documents with secure upload link (A11), Tasks and automation (A12, in-app), Reports (A13), full role matrix (A14), tax (once H-4 is decided) |
| **Depends on** | H-3, H-4, H-9 decisions; Hostinger cron for scheduled tasks; company details, logo, terms text and bank details for the quotation PDF |
| **Database** | `quotations`, `customer_documents`, `document_upload_links`, `tasks`; tax columns on bookings and transactions |
| **Acceptance** | Brief §25.1–25.4 tests; quote → accept → convert at locked price; upload link expiry, revoke and access tests |
| **Milestone** | M2 (contracted) |
| **Effort** | about 6–8 days |

### Phase 3 — Operations clarity (decision-dependent)

| | |
|---|---|
| **Includes** | R-10 readiness, return in steps (H-1), buffers (H-2), late-return conflict alerts, booking extension, trip departure status, default driver-to-car assignment (if approved, H-10) |
| **Database** | `readiness` columns, an inspection record, buffer settings, a `driver_cars` pivot (if approved) |
| **Migration** | Map existing status to readiness. The old column stays until verified. Rollback: drop the new columns; the old status still works |
| **Milestone** | Improvements within A4/A10 (category 4). Seasonal rates and driver defaults may need a change request (category 6) |
| **Effort** | about 3–4 days, plus about 1 day if seasonal rates are approved |

### Phase 4 — Website integration (M3)

| | |
|---|---|
| **Includes** | API per F.4, website build on the final design, booking requests, shared availability, social integrations listed in the agreement |
| **Depends on** | The website design files; integration choices for Meta and WhatsApp (see the earlier Meta document) |
| **Effort** | To be estimated once the design is shared |

### Phase 5 — Final QA and handover

- Full regression run (brief §25)
- Remove demo data
- Production `.env` check
- Training
- Handover document listing completed, incomplete and excluded items

---

## Section H — Decisions required

Ordered by priority. Each decision lists options and a recommendation; recommendations are suggestions only.

**H-1. When does a returned car become available again?** *(affects E-5, R-09; effort, not contract scope)*
- **A:** Immediately on receipt, as built now. Staff can tick Maintenance.
- **B:** After "Inspection complete" (one extra click). Payment never blocks it.
- **Recommendation:** B. It is still quick, and it stops a damaged or dirty car from being booked by mistake.

**H-2. Preparation buffer between bookings?** *(R-24)*
- **A:** None.
- **B:** A fixed number of minutes per category (e.g. cars X h, yachts Y h; you set X and Y).
- **Recommendation:** B, with values from the client. If you prefer none, enter 0.

**H-3. Which documents are mandatory, and when?** *(A11)*
- Example to confirm or edit: self-drive needs a licence and ID or passport before handover; with-driver needs ID only; yacht needs a passport or EID per the yacht's rules; corporate needs a trade licence.
- **Block point:** at Confirm, or only at Hand over? **Recommendation:** at Hand over, so sales are never slowed.

**H-4. VAT.**
- Are prices VAT-inclusive or VAT-exclusive?
- At what rate, and on which services? The client's accountant should confirm; no rate is assumed.
- Needed before quotations.

**H-5. Late-return charge.**
- **A:** Staff enter it manually (recommended until a policy exists).
- **B:** A grace period plus a per-hour rate.
- **C:** A full extra day after N hours.
- The client chooses the values.

**H-6. Does "Pending payment" hold the car?**
- **A:** Yes, with no expiry (current behaviour).
- **B:** Yes, for N hours, then an alert to staff (no automatic cancellation).
- **C:** No; only Confirmed holds.
- **Recommendation:** B.

**H-7. Website booking requests.** *(M3)* Should every request wait for staff confirmation, or can any service auto-confirm? **Recommendation:** all wait for staff at launch.

**H-8. Refund approval threshold.** Should refunds above a certain amount need Manager or Admin approval? This adds an approval step. **Recommendation:** permission-based only, with no approval queue.

**H-9. Role matrix.** Confirm or edit the table in F.1.

**H-10. Default driver-to-car assignment.** Is it needed? It is not explicit in the contract. **Recommendation:** add it as a small item if wanted (about 0.5 day). Otherwise the per-booking choice stays.

**H-11. Seasonal or special-date rates.** These are not in A4 (daily, weekly, monthly only). Do you want them? If so, this is a change request (about 1 day).

**H-12. Milestone 1 notice wording.** Use B.3: five M1 modules complete, plus M2 modules delivered early.

---

## Section I — Approval request

Please review and reply with one of these:

1. **"Phase 1 approved"**: I start the security and integrity fixes (about 2.5 days, no scope change). This is the minimum before real client data goes in.
2. **Answers to H-1 to H-12**: I update Sections D, F and G and send the revised plan for approval before Phase 2 or 3.
3. **Changes to this plan**: I revise it and ask again.

No code, schema or live data will change until you approve a phase in writing.
