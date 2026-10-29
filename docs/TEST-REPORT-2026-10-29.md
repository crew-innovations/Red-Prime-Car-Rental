# Test report: complete upgrade brief (28 Oct-2), 29 Oct 2026

**How it was tested**
- Local install: Laravel 12, Filament 3.3, MariaDB 10.11. Full suite `php vendor/bin/phpunit`: **258 tests, all passing**.
- Screens opened in a real browser at desktop (1366 px) and phone (390 px) width, in light and dark themes.
- Every outside service (Stripe, Paymob, Tabby, Tamara, PayPal, Meta, TikTok, Snapchat, Google Maps) is **mocked** in the tests. No real charge, refund or message was made.

**Labels**
- **PASS**: built, and checked by automated tests and on screen.
- **BLOCKED**: built, but cannot be verified without an outside account, keys or merchant approval.
- **NOT RUN**: built, but not yet checked on the live server.
- **NOT BUILT**: not done in this round (reason given).

**Scope:** everything below is a change request against the contract, except exports, audit log and calendar views, which extend M2 modules.

| Area | Status | What was checked |
|---|---|---|
| Exports (Excel / CSV) on every list | PASS | Follows the tab, filters, search and agent scope. Formula injection blocked. Logged. Finance / Manager / Admin only. |
| Audit log | PASS | Sign-in, sign-out, failed sign-in, role changes, exports, document opening and refusals, integrations, payments, refunds, job reports. Lines can't be edited or deleted. Retention setting (≥ 90 days) prunes nightly. |
| Calendar (day / week / month / agenda) | PASS | Bookings, charters, transfers, departures, follow-ups, quotes. Clash and needs-a-driver flags. Drivers see only their own jobs; agents only their own work. |
| Limousine & transfers | PASS | Services, vehicle categories, rates, fixed routes, hourly / half-day / full-day, return, stops, waiting, night %, airport fee, tolls. Passenger and bag limits. Flight number before confirming an airport job. Chauffeur clash checks. Driver job created. |
| Google Maps route and price | BLOCKED | Routes API / Places API code tested with mocks. Needs the Google Cloud keys. Until then, route transfers show "price on request". |
| Optional price approval | PASS | Off by default (Bilal). When on: a big discount waits for a manager; it can't be sent or confirmed until approved. |
| Job completion PDF report | PASS | Made automatically on completion and again on request; every version kept. Lists what is missing. Photos, money and deposits included. Staff, the customer (latest version only) and access refusals tested; every download logged. |
| Online payment links | PASS | From a booking, an accepted quotation, the customer account or the quotation page. Open links count toward the limit. Expiry is a setting (empty = until paid / cancelled). |
| Stripe | BLOCKED | Checkout and signed webhook (valid, forged, replayed, wrong amount) tested with mocks. Needs the Stripe account keys and webhook. |
| Paymob | BLOCKED | Intention + HMAC callback tested with mocks. Needs Paymob UAE keys and integration IDs. |
| Tabby | BLOCKED | Read back + capture before recording, tested with mocks. Needs Tabby merchant approval. |
| Tamara | BLOCKED | JWT check, then authorise + capture, tested with mocks. Needs Tamara merchant approval. |
| PayPal | BLOCKED | Capture on return tested with mocks. Not offered until a currency and AED rate are set (PayPal has no AED). Lower priority. |
| Refunds | PASS (mocked gateway) | Finance only. Part refunds split the payment row; the booking balance goes back up. Over-refund refused. |
| Meta Lead Ads | BLOCKED | Verify-token check, signature, lead read from Graph API, campaign / ad / form saved, no duplicates (mocked). Needs a Meta app with leads_retrieval approved. |
| TikTok / Snapchat leads | BLOCKED | Signed webhook tested with mocks. The real payload format must be confirmed with one test lead from each platform. |
| Lead inbox, dedupe, assignment | PASS | Same person with an open lead → added as a note, no duplicate. Manual (default), round robin and by-service tested. "Came in by" filter and campaign shown on the lead list. |
| Shared lead forms | PASS | Builder, required questions, honeypot, expiry, answer limit, new link. Leads get the service and the owner. |
| Messages (customer ↔ team, driver ↔ team) | PASS | Customers and drivers see only their own conversations and never internal notes. Agents only their own. Unread counts and in-app notices. Nothing is sent outside the CRM. |
| Reports | PASS | New Lead report (by channel, source, campaign, person, service; won / lost / open / conversion). Profit report gains "by service". |
| Website API additions | PASS | GET /transfer-services, GET /vehicle-categories, transfer estimates and transfer booking requests. See docs/API.md. |
| Settings | PASS | New: lead assignment, payment link expiry, Integrations page (keys encrypted, "Connected" only after a successful test). |
| Permissions | PASS | New abilities: manage-integrations, approve-prices, export-data. Refunds use manage-finance. Payment links use record-payments. Drivers and customers are kept out of all new staff screens. |
| Workflow automation builder | NOT BUILT | Notifications and assignment rules are built in. A drag-and-drop workflow builder was not built: it would be a separate module (CLAUDE.md rule 9). Ask if wanted. |
| Live server | NOT RUN | Nothing from this round is uploaded yet. Still to check after deploy: the migrations, dompdf on the server, the pages, laravel.log. |
| Real emails | NOT RUN | Mail is not set up on the server; in-app notices only. |

## Open points for Bilal
- Keys and accounts for Google Maps, Stripe, Paymob, Tabby, Tamara and Meta (and TikTok / Snapchat if used).
- PayPal: currency and AED rate, if PayPal is wanted at all.
- Lead assignment: keep Manual, or choose a round-robin group?
- Payment link expiry: keep "until paid", or set a number of days?
