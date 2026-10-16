# RED PRIME CRM: How It Works

This guide follows one customer from first message to final payment. The flow is the same for every car rental; yachts and yacht trips follow it too, with the small differences noted at the end.

---

## 1. A lead comes in

- Leads arrive from the website, WhatsApp and social media, or staff add them by hand under **Sales › Leads**.
- The CRM picks the pipeline from what the customer asked about:
  - a car → **Car Rental**
  - a yacht or yacht trip → **Yacht Rental**
  - a company or agency → staff choose **B2B**
- Every new lead starts at the first stage, **New Lead**.
- If the phone number or email already belongs to a customer or an open lead, the form shows a warning.

## 2. Working the lead

- Log each call or WhatsApp message as an **Activity**. A new car lead moves to **Contacted** on its own.
- Open **Sales › Pipeline** to see every lead as a card on a board. Drag a card to the next stage, or use the ••• menu on the card.
- Pipeline stages:

| Pipeline | Stages |
|---|---|
| Car Rental | New Lead → Contacted → Requirements Confirmed → Car Selected → Quotation Sent → Negotiation → Documents Received → Payment Pending → Confirmed → Vehicle Delivered → Completed |
| Yacht Rental | New Lead → Requirements Confirmed → Yacht Selected → Availability Confirmed → Quotation Sent → Deposit Pending → Confirmed → Charter Completed → Balance Paid |
| B2B | New Company → Decision Maker Identified → Meeting / Call → Proposal → Negotiation → Agreement → Active Account → Renewal |

- If the deal falls through, drop the card on **Lost**. The CRM asks for the reason so lost-deal reports stay accurate.
- When a lead reaches **Confirmed** (or **Agreement** for B2B), the CRM creates the customer profile automatically.

## 3. Creating the booking

- On the lead, click **Create booking**. The customer, the car and the dates are filled in from the lead.
- Choose the rental type:
  - **Self-drive**: the customer drives.
  - **With driver**: pick a driver from the team. A driver cannot be booked on two jobs at the same time.
- The price is worked out from the car's rates: hourly, daily, weekly or monthly, or the with-driver rate. Staff can change it and add add-ons, delivery or a discount.
- The car's security deposit is filled in from the car record.
- An advance payment can be recorded straight away.
- If the car is already booked for those dates, the booking cannot be saved. The CRM shows which booking it clashes with.

**What happens automatically when the booking is saved:**
- The car's status changes to **Reserved**, and the Cars list shows the booked dates.
- The lead moves to **Confirmed**.
- Any unpaid amount appears under **Accounts › To collect**.

## 4. Handing over the car

On the booking, click **Hand over car** and record:

- the date and time
- the odometer reading and fuel level
- the driver (for with-driver bookings)
- the security deposit received, and how it was paid
- any rental payment received
- photos of the car's condition, and notes

The car changes to **Rented** and the lead moves to **Vehicle Delivered**.

## 5. Receiving the car back

On the booking, click **Receive car** and record:

- the return time (the CRM shows if the car is late)
- the odometer reading and fuel level
- the condition: Good, Minor marks, or Damaged
- photos and notes

**Extra charges**
- Extra km is calculated from the car's daily km limit.
- Late return is calculated from the car's hourly rate.
- Fuel, Salik and fines, damage, cleaning and other charges are entered by staff.

**Payment check**

The return screen shows the rental balance, the extra charges and the security deposit held, so staff know exactly what the customer still owes. Staff can then:

- record a payment received now
- take whatever is still owed from the deposit
- refund the rest of the deposit now, or hold it for Salik and fines and refund it later with **Refund deposit**

**When the return is completed:**
- The car becomes **Available** for new bookings straight away.
- If it is damaged, tick **Send to maintenance** instead. The car then cannot be booked until it is set back to Available.
- Any amount still unpaid stays under **Accounts › To collect**, with a WhatsApp reminder button.
- The lead moves to **Completed**.

## 6. Collecting the balance

- When the customer pays, click **Record payment** on the booking, or **Mark paid** on the entry in Accounts.
- The booking then shows **Paid**, and the balance disappears from To collect.

---

## Yachts and yacht trips

- **Yacht charters** follow the same steps. **Start charter** and **End charter** replace hand over and receive, without the odometer and fuel fields.
- **Yacht trips**: pick a trip date and enter the number of guests. The CRM checks how many seats are left on that date.
- On the yacht pipeline, a completed charter moves to **Charter Completed**, and to **Balance Paid** once fully paid.

## Daily view

The dashboard shows:

- the cars and yachts going out today and coming back today
- any late returns
- revenue this month
- the balance still to collect

Staff can start each day from there.

---

## Quotations (planned for Milestone 2, Schedule A8)

At present, staff can mark a booking as **Quotation** and send the booking details over WhatsApp. A full Quotation Builder is part of Milestone 2 and will work like this:

1. Click **Create quotation** on a lead or booking. The customer, the car or yacht, the dates and the price are filled in.
2. Add the discount, VAT where it applies, deposit, terms and how long the quote stays valid.
3. The CRM produces a branded RED PRIME **PDF**.
4. **Send** shares it by WhatsApp or email. The lead moves to **Quotation Sent**.
5. Quotation statuses are Draft → Sent → Negotiation → Accepted / Rejected / Expired. A quote expires on its own after the valid-until date.
6. An **Accepted** quotation turns into a booking in one click, at the same price, and the car is reserved.
7. Every quotation is kept on the customer's profile.
