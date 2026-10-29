# Limousine pricing, suppliers and accounts: implementation report (29 Oct 2026)

**Brief:** "Add Limousine Module, Supplier & Vehicle Management, and Accounts Integration".
**Plan:** approved by Bilal on 29 Oct.
**Contract:** change request.
**Bilal's decisions:**
- The supplier bill is posted automatically when the job is completed.
- Road distance is billed to 0.1 km.
- A booking with a supplier vehicle cannot be confirmed until its supplier cost is known.

## Built

### 1. Limousine (extends the 28 Oct transfers module)
Kept as built:
- Google Routes API road distance (DRIVE).
- Per-km rate by vehicle category or service, with effective dates.
- Minimum fare, airport fee, stops, waiting, return, tolls and night %.
- Fare breakdown and price snapshot (`price_breakdown`).

Added:
- **Rounding.** Distance comes from metres: 28,640 m → 28.6 km. It is a setting (Business settings › Price suggestions): 0.1 km, whole km up, or 2 decimals. The metres are stored with the route.
- **Signed route.** A distance counts as "Google" only while it matches the server's signature for the same addresses and km. Changing an address or the km makes it "typed by hand", and the Google figure is kept (`route.google_km`).
- **Typed distance.** Needs the "Change prices" permission. A reason is required before confirming a booking or sending a quotation. Who typed it is recorded (`route_override_reason`, `route_override_by`).
- **No route.** Without a Google route or a reason, a booking can't be confirmed and a quotation can't be sent.
- **No rate.** If the vehicle category has no rate, no other rate is used. A price typed by hand needs a reason and the "Change prices" permission.
- **Stable confirmed prices.**
  - A confirmed booking or sent quotation keeps the rate it was priced with, even when it is edited.
  - A rate that is in use can't be edited or deleted: give it an end date and add a new one.
  - "Price again with the current transfer rate" is a deliberate action: it needs permission and a reason, and it is logged.
- **Fix.** A transfer with no price could not be saved at all (null extras). Fixed.

### 2. Suppliers (Fleet › Suppliers, permission `manage-suppliers`: Admin, Manager, Finance)
- **Supplier record:** name, contact, phone, email, address, TRN, type, status (active / pending / inactive / blocked), payment terms, opening balance, notes, documents. A supplier with history can't be deleted.
- **Cars and yachts:**
  - Ownership: Own / Supplier's / Hired in when needed, plus the supplier.
  - The old "partner name / share %" fields are kept, read-only.
  - "Make suppliers from partner names" creates and links suppliers from the old names, without rates.
- **Cost rates:**
  - Methods: per booking, per day, per week, per month (part weeks / months pro rata or in full), per km, or % of revenue without VAT.
  - Rates can be vehicle-specific or the supplier's default.
  - Dates: from / until. Overlapping active rates are refused.
  - A rate used by a booking is locked.
- **Which rate wins:** booking override (Finance, with a reason) → vehicle rate on the booking's start date → supplier default. If none applies, the cost is "missing" (never zero) and the booking can't be confirmed.
- **Booking snapshot:** supplier, vehicle, rate id, method, rate, quantity, cost, source and dates. It is recalculated only while no bill is posted, and frozen after.
  - Per day uses the same days the customer pays for.
  - Per km uses the route km for transfers, and the odometer at return for rentals.

### 3. Accounts (inside the existing module, no second ledger)
- **Supplier bill:** an expense row (category "Partner payout") with the booking and the supplier.
  - Posted once at completion. Edits, reassigning the car or a second post never make another one.
  - It follows the existing expense-approval rule: a bill from Operations waits for Finance.
  - "Post the supplier bill" is the manual fallback.
- **Supplier payments:**
  - Allocated to one or more bills or to the opening balance, oldest first by default.
  - Partial payments are allowed. Paying more than is owed, a double submission, or paying a bill that is waiting for approval are all refused.
  - A wrong payment is reversed with a reason.
  - A payment is not an expense, so a cost is never counted twice.
  - A bill's paid status only follows its payments. A bill with payments can't be reversed until those payments are.
- **Balance:** opening + approved bills − payments. The supplier page has balance cards and a statement with a running balance; the statement and the balance match.
- **Booking "Supplier & profit"** (Finance only):
  - revenue without VAT, supplier cost (estimate, or the posted bill), other direct costs
  - estimated profit and margin
  - actual profit: only once the job is done and the costs are posted and approved
- **Reports:**
  - Profit report has a "Supplier" view.
  - Accounts has "To pay suppliers".
  - The audit log records supplier bills, payments and cost overrides.

## Reused
- Booking, statuses and availability, `syncBalance`, Transaction (expense approval, reversal, `counting`), ProfitReport, AuditLog, Access abilities, medialibrary.
- The transfer price engine, GoogleMaps, and the Filament layouts.

## Database (one migration, additive and reversible)
`2026_10_29_000010_create_suppliers_and_costs`:
- New tables: `suppliers`, `supplier_rates`, `supplier_payments`, `supplier_payment_allocations`.
- New columns:
  - `cars.supplier_id`, `yachts.supplier_id`
  - `bookings.supplier_id`, `supplier_cost_status`, `supplier_cost`, `supplier_cost_snapshot`, `supplier_bill_id`
  - `transactions.supplier_id`
  - `bookings` / `quotations.route_override_reason`, `route_override_by`
- No data is changed and no rates are seeded.

## Setup
- **Google Maps:**
  - Server key (Routes API + Places API) and browser key (Maps Embed) in Settings › Integrations. See `docs/INTEGRATIONS-SETUP.md`.
  - Until Google Maps is connected, staff type the distance and give a reason.
- **Suppliers:**
  - For each supplier car or yacht, set its supplier and the supplier's cost rates.
  - Until that is done, new bookings of those vehicles can't be confirmed. Bookings confirmed earlier can still be handed over and returned; Finance is told if the bill can't be posted.

## Tests (actually run, local MariaDB)
- **Full suite: 272 tests, all passing.** New: `SuppliersTest` (9), `LimousineRulesTest` (5). `TransfersTest` and `PagesSmokeTest` were updated.
- Google is mocked (Http::fake). No real Google call was made.
- Screens checked in the browser: supplier page, statement, booking "Supplier & profit", Accounts.

## Not included / limits
- **Supplier credit notes:** no separate document. A wrong bill is reversed and a corrected bill added.
- **Payments beyond what is owed** (advances to suppliers): refused, as there is no advance workflow.
- **Cash reports:** supplier payments are not rows in Transactions. Costs count when the bill is posted (the existing method); the cash paid out is visible per supplier.
- **Not checked on the live server yet:** the migration, the pages, and real Google routes.
