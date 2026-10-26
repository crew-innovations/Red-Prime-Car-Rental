# Development checklist: status after testing (17 Oct 2026, updated 21 Oct 2026)

**How this was tested**
- The CRM was installed and run here with real Laravel 12, Filament 3.3.57 and MariaDB 10.11, the same database family Hostinger uses.
- An automated test suite was added in `tests/Feature`: **101 tests, 726 checks, all passing** (21 Oct). It covers every item marked *Tested* below.
- Other checks:
  - Two-process race tests in `tests/Concurrency`.
  - Migrations upgraded on a copy of the old version's database, and rolled back.
  - Every screen opened in a real browser at desktop (1366 px) and phone (390 px) width.

**Status labels:**
- **Working**: was already correct, and confirmed.
- **Fixed**: was broken, now corrected and tested.
- **Built**: was missing, now added and tested.
- **Partial**: works with a limitation, explained in the item.
- **N/A**: not applicable at this stage.
- **Decide**: waiting on a business decision.

## 1. Leads and sales pipeline

| Item | Status | Evidence / note |
|---|---|---|
| Create leads manually | Working | Tested (`LeadsAndCustomersTest`) |
| Leads from website / integrations | N/A | No website yet; the website connection is M3. Lead source and UTM fields are ready. |
| Duplicate detection (phone / email) | Working | Tested: `050…`, `+971 50 …` and `00971…` all match. |
| Car / Yacht / B2B pipelines | Working | Tested |
| Calls / WhatsApp / activities | Working | Activities tab; tested that it stays on the lead after conversion. |
| Pipeline board, move between stages | Working | Tested; Finance can view the board but cannot move leads. |
| Correct stages per type | Working | Schedule A3 stages tested. |
| Lost needs a reason | **Fixed** | It was only checked on screen; now the server also refuses "Lost" without a reason. Tested. |
| Customer created or linked at the won stage | Working | Tested: an existing customer is linked, never duplicated. |
| Lead data carries into bookings and quotes | Working | Tested; the lead moves to "Quotation sent", "Confirmed" and "Completed" automatically. |

## 2. Customers

| Item | Status | Evidence / note |
|---|---|---|
| Create / edit | Working | Pages tested |
| Prevent duplicates | Working | Matching by phone (last 9 digits) and email. |
| Related leads, bookings, quotations, documents, payments | Built | Quotation and document tabs added; all tabs load (tested). |
| History kept when a lead becomes a customer | Working | Tested |
| Access permissions | **Fixed** | The documents tab is now limited to roles that handle documents (Finance does not see passports). Tested. |

## 3. Car bookings

| Item | Status | Evidence / note |
|---|---|---|
| Create from a lead, with pre-fill | Working | Tested |
| Self-drive and with driver | Working | Tested |
| Hourly / daily / weekly / monthly price | Working, now configurable | Tested. The thresholds (hourly under 8 h, weekly from 7 days, monthly from 28 days, yacht half / full day) are now in Business settings. |
| Deposits, discounts, delivery, add-ons, VAT | Working | Tested |
| Staff can adjust price | Working | Allowed per role; every change is in History. |
| Availability check before confirming | Working | Tested |
| No overlapping bookings | Working | Tested in the form and on the server. |
| Simultaneous saves | **Fixed** | The check read an old snapshot of the data inside long save transactions, so two saves at once could both pass. It now reads the latest saved data. Tested with two processes: the second save is refused. |
| Clear conflict messages | Working | For example: "Already booked for these dates: BK-00007 (01 Mar 10:00 – 03 Mar 10:00)". |
| Booking and car statuses | Working | Tested (Reserved, Rented, Available) |
| Pending requests separate from confirmed | Working, configurable | Inquiry and Quotation never hold the car; whether Pending payment holds it is a setting (on). |
| Booking and price history | Built | History tab (audit log) |

## 4. Drivers

| Item | Status | Evidence / note |
|---|---|---|
| Create / edit profiles, active / inactive | Built | Tested; inactive drivers are not offered on bookings. |
| Default cars per driver | Built | Tested |
| Pick or override the driver on a booking | Built | Tested |
| Car selected → its usual drivers | Built | Listed first with "★ usual driver"; suggested automatically. Tested. |
| Driver selected → their usual cars | Built | Shown under the driver field. |
| Driver availability and conflicts | Working | Tested. Since 26 Oct: self-drive blocks only delivery and collection, with a gap setting; also checked on every save. |
| Full profile and documents with expiry (26 Oct) | Built | Tested: encrypted numbers, replace keeps history, required fields and documents, reminders. |
| Driver login by link, switch off without deleting (26 Oct) | Built | Tested: single-use, expiring link; per-driver and global switch. |
| Mobile "My jobs", handover and collection checks, damage reports (26 Oct) | Built | Tested; screenshots at 390 px. Real phones and slow networks still to check on staging. |
| No money by drivers | Respected | Tested: a handover records no transactions; the office completes returns. |
| No pay, payroll or separate app | Respected | Driver screens are inside the CRM. |

## 4b. Agents and customer accounts (change request, 26 Oct)

| Item | Status | Evidence / note |
|---|---|---|
| Agent role, own records only (setting) | Built | Tested: lists, open-by-URL, badges, widgets. |
| Customer account (/account): bookings, quotations, documents, payments | Built | Tested: only own records, others return "not found". |
| Private files limited to their owner | Built | Tested for customer, driver and staff. |
| Formal tax invoice for customers | Not built | Needs VAT / TRN decisions; payments are shown instead. |

## 5. Quotations

| Item | Status | Evidence / note |
|---|---|---|
| Create from a lead or customer, pre-filled | Built | Tested |
| Discounts, VAT, deposit, terms, expiry | Built | Tested |
| Branded quotation / PDF | Built | Branded web page with "Download PDF" (print to PDF); tested. |
| Sending | Built | WhatsApp, with the message text editable in settings. Email is not connected (there is no mail setup yet). |
| Statuses Draft → Expired | Built | Tested |
| Expiry | Built | Overdue quotes expire automatically; closed quotes are left alone. Tested. |
| Availability re-checked on convert | Built | Tested: if the car was taken meanwhile, the quote stays Accepted and nothing is booked. |
| Agreed price and terms kept | Built | Tested: a price-list change afterwards does not change the booking. A sent quote cannot be edited without "Revise". |
| Accepting does not reserve | Built | Tested |
| Quote history on the customer | Built | Quotations tab |

## 6. Yachts

**21 Oct:** RED PRIME sells yacht trips and does not keep yacht inventory. New yachts and new charter bookings can no longer be created, and the Yachts menu is hidden. Old charters stay in history and can still be opened. The rows below describe those old charters.

| Item | Status | Evidence / note |
|---|---|---|
| Charter bookings, full-period availability | Working | Tested |
| No overlaps, including with trip departures | Working | Tested |
| Charter pricing, deposits, payments | Working | |
| Start and completion | Working | "Start charter" and "End charter" |
| Turnaround and readiness | Built | Gap-between-bookings setting; Ready / Cleaning / Inspection. |
| No odometer or fuel for yachts | Working | Those fields are cars only. |

## 7. Yacht trips

| Item | Status | Evidence / note |
|---|---|---|
| Trips, dates, times, prices, capacity | Working | |
| Seats checked; no overbooking | Working | Tested |
| Simultaneous seat bookings | **Fixed** | Two bookings of 6 seats at the same moment were both accepted on a 10-seat trip. Now the second is refused ("Only 4 seat(s) left"). Tested with two processes. |
| Yacht / crew availability | Partial | The yacht is checked against charters; there is no crew module (not in scope). |
| Completion and balances | Working | |
| Trip without any yacht record | **Built** (21 Oct) | Operator name and customer instructions instead. Tested. |

## 7b. Trips & Packages (change request, 21 Oct)

| Item | Status | Evidence / note |
|---|---|---|
| Multi-day package with an editable day-by-day itinerary | **Built** | 5-day itinerary saved, reordered and a day removed. Tested. |
| Pricing per person / per package, sale, group and departure prices | **Built** | Tested. |
| Departures with places; no overbooking; cancelled bookings free places | **Built** | Tested. |
| Quotation to booking on the same departure | **Built** | Tested. |
| Website publishing, section switch, preview | **Built** | Tested. |

## 8. Handover

| Item | Status | Evidence / note |
|---|---|---|
| Date / time, odometer, fuel, driver, deposit, payment, photos, notes | Working | Tested; photos are now private. |
| Car → Rented at handover | Working | Tested |
| Lead and booking stages | Working | Tested ("Vehicle delivered") |
| Blocks only the handover step | Built | Blocked if the car is not ready, in maintenance, still out on another booking, or missing documents (when that setting is on). A manager can override, and it is logged. Tested. |

## 9. Return and inspection

| Item | Status | Evidence / note |
|---|---|---|
| Return time, late indicator | Working | Grace time is now a setting. |
| Odometer, fuel, condition, photos, notes | Working | Tested |
| Extra-km charge from configured rules | Working | Uses each car's km limit and rate. |
| Late charge from configured rules | Built | Four options in settings (default: staff type it). Tested. |
| Fuel, Salik, damage, cleaning, other charges | Working, configurable | The charge lines are editable in settings. |
| Estimated vs confirmed charges | Built | Suggested amounts are labelled as estimates; they are only charged on "Complete return". |
| Car unavailable until cleaned / inspected | Built | Tested |
| Damaged → maintenance | Working | Tested |
| Booking / lead completed at the right step | Working | Tested |

## 10. Payments and accounts

| Item | Status | Evidence / note |
|---|---|---|
| Full / partial payments | Working | Tested |
| Total, paid, balance, deposit held | Working | Shown on the booking page |
| Balances consistent; To collect | Working | Tested |
| No duplicate payments | Working | Tested (a double click within 30 seconds is ignored) |
| Charges reconciled against the deposit | Working | Tested |
| Deposit deduction, refund, holding for Salik / fines | Working | Tested |
| Refunds separate from approval | Partial | Deposit refunds are tracked separately. Refunds of rental money on a cancelled booking are recorded by Finance in Accounts by hand; a proper refund flow needs your refund policy (**Decide**). |
| Deposit separate from revenue | Working | Tested: deposits are not revenue, and refunds are not costs. |
| History of payments and adjustments | Working | History tab and audit log |
| Marking the balance paid updates everything | Working | Tested, with no duplicate row. |

## 11. Cancellation and changes

| Item | Status | Evidence / note |
|---|---|---|
| Cancel from the booking page | **Fixed** | The "Cancel booking" button on the booking page did nothing: it shared its name with the form's Cancel button. Renamed and tested. |
| Cancellation rules / policy | Decide | No refund or cancellation policy is assumed. |
| Cancellation requests vs confirmed cancellations | N/A for now | Staff cancel directly. Customer requests will come with the website (M3). |
| Refund status separate from cancellation | Working | Cancelling never refunds anything automatically. |
| Car released only on a confirmed cancellation | Working | Tested |
| Re-check on date or car change; never move other bookings | Working | Tested |

## 12. Availability and maintenance

| Item | Status | Evidence / note |
|---|---|---|
| Booking status separate from readiness | Built | |
| Gaps, maintenance, inspections checked | Built | Gap between bookings is a setting. |
| Late returns keep the car busy | **Fixed** | An overdue car looked free. Now new bookings that start before it is back are refused ("Still out on BK-…"), and later handovers are blocked until it is received. Tested. |
| Maintenance / unavailable blocks bookings | Working | A manager can override; it is logged. Tested. |
| Warning when a new restriction hits confirmed bookings | Built | Setting a car to maintenance with bookings ahead warns staff and logs it; bookings are not touched. Tested. |

## 13. Customer documents

| Item | Status | Evidence / note |
|---|---|---|
| Upload, storage, review, approval | Built | Tested |
| Requirements per booking type | Built | None by default; you choose them in settings. |
| Only authorised users | Built | Tested |
| Private files never on public URLs | **Fixed** | Booking photos and agreements were in a public folder. They are now private, signed and login-only. A command moves the old files (tested on a copy of the old database). |
| File type and size checks | Built | JPG / PNG / WebP / PDF, maximum 10 MB. Tested. Virus scanning is not available on shared hosting. |
| Blocks only the handover | Built | Tested; it can be switched on or off. |
| Secure upload link | Built | You asked for it; tested (expiry, switch off, wrong type). |

## 14. Website integration

| Item | Status | Evidence / note |
|---|---|---|
| Public catalogue API (published items only, no internal fields) | **Built** (21 Oct) | `docs/API.md`. Tested. |
| Website requests create leads, never bookings; server-side price | **Built** | Tested. |
| Duplicates, idempotency, bot honeypot, rate limit | **Built** | Tested. |
| CORS limited to website addresses in Business settings | **Built** | Tested. |
| The website itself | N/A | Not built and not connected (M3). |

## 15. Dashboard and navigation

| Item | Status | Evidence / note |
|---|---|---|
| Figures match records; no double counting | Working | Tested (revenue excludes deposits; outstanding only counts balance rows). |
| Today's out / back, late returns | Working | Now also lists cars waiting for cleaning or inspection. |
| Monthly revenue, to collect | Working | |
| All screens, tabs and widgets open | **Fixed** | Tested every page, tab and widget. Found and fixed two crashes: the Quotations list, and "New transaction" (it crashed on open). |
| Desktop / phone layout | Working | Checked in a real browser at 1366, 1024 and 390 px in both themes (21 Oct): no sideways scrolling. On phones each page starts with the menu closed. |
| Menu groups and permissions | **Built** (21 Oct) | Overview, Sales, Operations, Finance, Administration. Tested per role. |
| Global Documents and History lists | **Built** (21 Oct) | Tested (permissions, approve from the list). |

## 16. Security and data integrity

| Item | Status | Evidence / note |
|---|---|---|
| Role-based permissions | Built | Tested for each role; the matrix is editable in settings. |
| Server-side validation | Working | Availability, seats, lost reason and price lock are all enforced on the server. |
| Transactions and relations | **Fixed** | See simultaneous saves above. |
| Migrations safe | Working | Additive; upgrade from the old version tested; rollback tested. |
| Deploy safety | Built | The deploy script takes a database backup first and stops if the backup fails. |

## 17. Testing

- Automated suite: `php vendor/bin/phpunit`. It needs an empty MySQL/MariaDB database called `rp_testing`; see `phpunit.xml`.
- Race tests: `tests/Concurrency/*.php` (run two copies at once).
- **Could not be tested here:**
  - the real Hostinger server
  - real WhatsApp sending (the links open WhatsApp)
  - Google Fonts (blocked in this sandbox)
  - the website connection (does not exist yet)
