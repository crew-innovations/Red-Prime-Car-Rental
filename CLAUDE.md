# RED PRIME RENTALS CRM — Instructions for Claude Code

Internal CRM for RED PRIME RENTALS (luxury car rental, yacht charters, yacht trips), built by Crew Innovations.
The full brief is `docs/MASTER-PROMPT.md`. The current audit and phase plan are in `docs/CRM-Audit-and-Plan.md`. The staff workflow is in `docs/CRM-Workflow.md`.

## How to work (mandatory)

1. **Plan, then get approval, then build.** Never start implementing a feature or phase until the owner (Bilal) explicitly approves it in writing, e.g. "Phase 1 approved". A request to plan something is not permission to build it.
2. **Build only one approved phase at a time.** Keep to its listed scope and acceptance criteria. If the work reveals a scope change, a destructive migration or an open business decision, stop and ask.
3. **Do not invent business policy.** Ask instead of guessing:
   - prices, deposits, VAT
   - late, km or fuel fees
   - cancellation or refund rules
   - mandatory documents
   - buffers and thresholds

   Open decisions are listed in Section H of the audit.
4. **Map every change to the contract** (`CRM_Agreement_REDPRIME.pdf`, clause 7.3 and Schedule A1–A14). Label it M1, M2 or M3, or "change request" if the contract doesn't cover it.
5. **Never delete or rewrite production data**, reset the DB, or run a destructive migration without approval. Migrations must be additive and reversible, and keep the old columns until the new ones are verified.
6. **Ask Bilal before every `git push`.** Commit locally freely.
7. **Never type passwords or secrets into any field**, and never repeat secrets that appear in chat.
8. **"Done" means tested.** Report what was actually run and verified, and say what wasn't.
9. **Keep it simple for staff.** Use few statuses, show only relevant fields, and add no unrequested modules. Never build: a separate customer or driver application, payroll, commissions, general ledger. (Since 26 Oct, the driver screens inside `/crm` and the customer account area `/account` are approved; both live in this same app.)

## Business rules already built (keep unless a change is approved)

- **Lead status follows the pipeline stage.**
  - Pipelines are Car, Yacht and B2B, as in Schedule A3; see `App\Enums\Pipeline`.
  - A won stage auto-creates or links the customer, never a duplicate.
  - Lost requires a reason.
- **Bookings** (`App\Models\Booking`):
  - Statuses follow A7.
  - Holding statuses: Pending payment, Confirmed, In progress (pending decision H-6).
- **Drivers:**
  - Any car booking can have a driver. `App\Support\Drivers\DriverJobs::sync()` keeps its delivery and collection jobs in step.
  - Drivers never record money. Handover and collection are in `App\Support\Drivers\DriverWork`.
- **Accounts:** customers only reach their own records through `App\Support\CustomerArea`. Private files are checked by `App\Support\PrivateFiles`. Login links come from `App\Support\Accounts`; nobody types a password for someone else.
- **Car and yacht status** is updated from bookings by `refreshBookingStatus()`. Maintenance and Unavailable are never overwritten by it.
- **Money** (`App\Models\Transaction`):
  - Security deposits are not revenue, and deposit refunds are not costs.
  - Each booking keeps one system row with `is_balance = true` holding its unpaid balance; this feeds Accounts › To collect. Marking that row paid turns it into a normal payment.
  - `Booking::syncBalance()` is the single place where paid amount, balance and payment status are calculated.
- **Handover and return logic** lives in `App\Support\BookingFlow`; the UI actions are in `BookingResource\BookingActions`.

## Stack and conventions

- **Framework:** Laravel 12, PHP 8.2+, MySQL, timezone Asia/Dubai.
- **Admin panel:** Filament 3.3 at `/crm`.
  - Brand colours: red `#d7141a`, gold `#d4a62a`. Font: Poppins.
  - Dark theme by default; the light theme must also work.
  - Custom CSS lives in `public/css/crm.css` (plain CSS, no build step).
- **Packages:** spatie/laravel-permission (roles: Super Admin, Admin, Manager, Sales, Operations, Finance, Staff, Driver) and spatie/laravel-medialibrary.
- **Morph aliases** are defined in `AppServiceProvider`: car, yacht, trip, customer, lead, user, transaction, booking. Always use the aliases.
- **Shared form helpers:**
  - `App\Filament\Concerns\FleetFields`
  - `App\Filament\Concerns\WebsitePublishing`
- **Filament gotchas:**
  - Closure parameters are injected **by name**: use `$query`, `$record`, `$get`, `$set`, `$state`, `$data`, `$arguments`.
  - Relation managers lazy-load.
  - Action forms do not save relationships, so attach media manually.
- **UI text** is plain English for staff. Communicate with Bilal in Roman Urdu: short, human, no jargon.

## Verifying code

Automated tests: `php vendor/bin/phpunit` (needs an empty MySQL/MariaDB database `rp_testing`, see `phpunit.xml`). Race tests: `tests/Concurrency`. Checklist status: `docs/CHECKLIST-STATUS.md`.

If Packagist is blocked in the agent environment, install packages from GitHub clones (path repositories) or, at minimum, before every commit:
- Run `php -l` on every changed file.
- Check every `use` import and every chained method against the cloned Filament 3.x and Laravel 12.x sources in the session scratchpad (`ref/filament/packages`, `ref/framework/src`), with the verify script if available.
- Unit-test pure logic (enums, calculations) with stub interfaces.
- Real behaviour must be checked on staging after deploy.

## Deploying to the live server (Hostinger shared hosting, crm.redprimerentals.com)

- **App path:** `~/domains/crm.redprimerentals.com/public_html` (root `.htaccess` rewrites to `/public`).
- **Uploading files:** use the Hostinger File Browser API from the browser tab, and verify each upload by SHA-256 (first 16 hex characters).
  - Do not use SSH from the agent side. Bilal runs the commands himself.
- **After uploading, Bilal runs:** `bash scripts/deploy-hostinger.sh` (add `--demo` for sample data).
  - It runs composer with `--no-scripts` (because `proc_open` is disabled), then package:discover, filament:upgrade, migrate, optimize:clear and optimize.
- New routes, pages or widgets need `optimize:clear`, otherwise they return 404.
- After a deploy, check `storage/logs/laravel.log` for new errors and smoke-test the affected pages.

## Current status

- Milestone 1 modules are complete: cars, yachts, trips, customers, leads.
- Also built early, at the client side's request: bookings, handover and return, accounts, pipelines and the dashboard. These are Milestone 2 modules.
- Audit fixes plus quotations, documents, readiness, drivers and history were built on 2026-10-16. See `docs/DEVLOG.md`, including the live test checklist and the open decisions.
- 26 Oct: one shared system: Agent and Customer roles, driver profiles and documents, driver jobs with mobile handover and collection, damage reports, and the customer account area (`/account`). See DEVLOG.
- 28 Oct: full correction brief (Priorities 1–10): pricing engine, sales workflow, charters with crew, car operations, PDFs and invoices, accounts and profit report, dashboard and menu, website API, end-to-end tests. See DEVLOG. Deployed to the live server on 28 Oct.
- 29 Oct: complete upgrade brief: exports, audit log, calendar, limousine & transfers (Google Maps), integrations, job reports, online payments (Stripe, Paymob, Tabby, Tamara, PayPal), lead inbox (Meta, TikTok, Snapchat), shared forms, messages, lead report. See DEVLOG and `docs/TEST-REPORT-2026-10-29.md`. Outside services are mocked until keys arrive. Not yet deployed.
- 29 Oct (2): suppliers, cost rates, booking cost snapshot, supplier bills and payments, booking profit, limousine route / rate rules. See `docs/REPORT-2026-10-29-LIMOUSINE-SUPPLIERS.md`. Rules: `App\Support\Suppliers\SupplierCost` (which rate, missing = no confirmation), `SupplierLedger` (bills, payments, balance).
- **Earlier note:** waiting for approval of Phase 1 in `docs/CRM-Audit-and-Plan.md` (security and integrity fixes), and for answers to decisions H-1 to H-12.
