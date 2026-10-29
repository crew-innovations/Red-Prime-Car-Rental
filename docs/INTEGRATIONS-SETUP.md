# Connecting outside services

Everything is set up in **Settings › Integrations** (Admin only). For each service:

1. Do the provider-side steps below.
2. Press **Set up** and paste the keys. Keys are stored encrypted and are never shown again.
3. Press **Test connection**. A service is only **Connected** after a successful test. Saving keys is not enough.

Test and live keys are kept apart: switch the mode to **Live** only after a full test-mode payment.

Nothing in the CRM charges money, refunds or sends a message to a customer until a service is connected.

## Google Maps (transfer prices)
- Google Cloud project with billing on. Enable **Routes API**, **Places API (New)** and **Maps Embed API**.
- **Server key**: restrict it to Routes API + Places API (New) and to the server IP.
- **Browser key**: restrict it to Maps Embed API and to `https://crm.redprimerentals.com/*`.
- Until this is connected, route transfers show "price on request" (hourly hire works without it).

## Payments

The webhook address of each gateway is shown on its card. Money is recorded only when the provider confirms it. Opening the "thank you" page never records anything.

### Stripe
- Developers › API keys: copy the **secret key** (`sk_test_…` first).
- Developers › Webhooks › Add endpoint, at the CRM address. Events:
  - `checkout.session.completed`
  - `checkout.session.async_payment_succeeded`
  - `checkout.session.async_payment_failed`
  - `checkout.session.expired`
- Copy the **signing secret** (`whsec_…`).
- Test: make a payment link on a test booking, pay with card `4242 4242 4242 4242`, and check that the booking balance drops.

### Paymob (UAE)
- Settings › Account info: **API key**, **Secret key**, **Public key** and **HMAC secret**.
- Developers › Payment integrations: copy the **integration ID(s)** (card, Apple Pay…).
- For each integration, set the **Transaction processed callback** to the CRM address.

### Tabby (needs merchant approval)
- Tabby gives a **public key**, a **secret key** and a **merchant code** once the business is approved.
- Register the webhook with Tabby (their API `POST /api/v1/webhooks`), at the CRM address, with a header:
  - name: as set in the CRM, default `X-RP-Webhook`
  - value: a long random secret (paste the same value in the CRM)
- **Test connection** passes only once Tabby lists the CRM webhook.

### Tamara (needs merchant approval)
- Partners portal › Integration: **API token** and **notification token** (sandbox first).
- The CRM sends its notification address with every checkout.
- The customer's mobile number must be on the booking.

### PayPal (optional)
- Create a REST app, then copy the **client ID** and **secret**.
- PayPal has no AED: set the **PayPal currency** and the **AED rate**. Both are your decision.
- Add a webhook for `PAYMENT.CAPTURE.COMPLETED` and copy its **webhook ID**.

### Day to day
- **Ask for money:** on a booking or an accepted quotation, use **Payment link (online)**, then send the link (WhatsApp button included).
- **Customers:** they also see "Pay the balance online" in their account, and "Pay the advance online" on an accepted quotation.
- **Accounts › Online payments:**
  - Every link, its status and the gateway used (test payments are marked).
  - **Refund** (Finance only) refunds through the gateway and records it.
  - "Needs checking" means the amount paid did not match, or the link was paid twice. Nothing was recorded automatically. Check it in the provider's dashboard.

## Lead sources

### Facebook & Instagram Lead Ads
- A Meta app (Business type) with **Webhooks**, and the **leads_retrieval** permission approved by Meta (App Review).
- In the CRM, save:
  - the **App secret**
  - a long-lived **Page access token** (with leads_retrieval, pages_manage_metadata, pages_show_list and pages_read_engagement)
  - a **Verify token** you choose
  - the **Page ID**
- Switch it on **before** adding the webhook in Meta, so the address check succeeds.
- Meta app › Webhooks › Page: subscribe to **leadgen**, with the CRM address and the same verify token.
- Subscribe the app to the Page, and give the CRM app access in Page settings › Leads access.
- Test with Meta's **Lead Ads Testing Tool**. The lead appears in Leads with its campaign, ad and form.

### TikTok and Snapchat
- These send leads as a signed webhook: HMAC-SHA256 of the body with the shared secret, in the header shown on the card.
- Save the shared secret, switch it on, point the platform's webhook to the CRM address, and send **one test lead**.
- **Test connection** passes only after a real signed lead has arrived. The exact payload format must be confirmed with that first lead, so check the lead's fields in the CRM.

### Who gets new leads
Business settings › **New leads: who gets them**:
- **Manual** (default): a manager assigns them.
- **Round robin** in a chosen group.
- **By service**, with round robin for the rest.

Someone who already has an open lead (same phone or email) does not get a duplicate: the new enquiry is added to their lead as a note.

### Shared forms
Sales › **Shared forms**:
- Make a form (name and mobile are always asked), then share its link.
- A form can close on a date or after a number of answers.
- **New link** stops the old one at once.
