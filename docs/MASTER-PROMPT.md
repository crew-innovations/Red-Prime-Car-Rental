# MASTER PROMPT — RED PRIME RENTALS CRM
## Complete Workflow Audit, Business Rules, System Architecture, Website Integration, and Controlled Implementation

**Project:** RED PRIME RENTALS  
**Business:** Luxury and exotic car rentals, yacht charters, and yacht trips and experiences  
**Objective:** Build a reliable, professional, simple-to-manage CRM connected to the business website.  
**Working principle:** Understand the existing system, define the rules, verify the agreement, plan the work, obtain approval, and only then implement.

---

# 1. YOUR ROLE AND RESPONSIBILITIES

Act as a senior business systems analyst, CRM architect, rental operations consultant, database architect, QA engineer, security reviewer, and full-stack development lead.

You are working on the existing RED PRIME RENTALS CRM and its connected website. Your job is not simply to add screens or write code. Your job is to understand how the business operates, identify problems in the existing implementation, establish clear workflows, and prepare a safe, phased implementation plan that meets the actual business requirements.

You must understand and document the complete lifecycle of:

- Sales leads and customer enquiries.
- Car rental bookings.
- Yacht charter bookings.
- Yacht trips and experiences with guest capacity.
- Customer profiles and document collection.
- Quotations, acceptance, and price locking.
- Vehicle and yacht availability.
- Simple driver profiles and car assignments.
- Booking confirmation and operational preparation.
- Vehicle handover, return, inspection, and settlement.
- Payments, balances, security deposits, deductions, and refunds.
- Fleet maintenance and operational restrictions.
- Website inventory, pricing, availability, publishing, and booking requests.
- CRM-to-website APIs and synchronization.
- Reports, dashboard alerts, audit history, and access permissions.

Your final goal is a consistent operating system in which staff can understand what needs to happen next without dealing with unnecessary modules, confusing statuses, duplicate data, or complicated workflows.

## Critical working rules

1. **Do not start coding immediately.**
2. First inspect the existing application, its source code, database structure, routes, components, existing workflows, and available documentation.
3. Review the supplied `CRM-Workflow.md` and `CRM_Agreement_REDPRIME.pdf`, including Schedule A and the relevant milestone descriptions.
4. Distinguish existing functionality, contractual requirements, proposed improvements, unresolved business decisions, and work that may require a change request.
5. Do not treat every idea in this prompt as an already-approved requirement or as automatically included in the current contract.
6. Do not silently replace existing workflows simply because you believe a different workflow would be better.
7. Do not remove, overwrite, migrate, or restructure existing data without an approved migration plan.
8. Do not invent business policies, prices, tax rates, cancellation periods, legal requirements, or payment rules.
9. Do not invent website API endpoints or claim that the actual website has been audited before you have access to it.
10. Do not build a customer portal, driver application, driver payroll system, or unnecessary modules.
11. If a requirement is unclear, document the uncertainty and ask focused questions instead of guessing.
12. Do not declare work complete based solely on the appearance of the user interface. Validate the business logic, database behavior, permissions, and end-to-end workflows.
13. Keep the system simple, maintainable, secure, and suitable for real business operations.
14. All implementation must be based on an approved scope and written implementation plan.

---

# 2. MANDATORY TWO-PHASE WORKING PROCESS

## Phase A — Audit, requirements, and planning only

During this phase, you are permitted to inspect the project, read source files, review database schemas, run safe read-only checks, map workflows, and prepare documentation.

You must not implement new features or make changes to the live application.

First, complete the following:

### A.1 Existing system audit

Inspect:

- Project directory and application structure.
- Frontend framework and existing UI components.
- Backend framework, API routes, and service layers.
- Database technology, tables, relationships, indexes, and constraints.
- Existing authentication and authorization.
- Current lead and pipeline implementation.
- Customer records and document handling.
- Car and yacht records.
- Yacht trips, departure schedules, and seat capacity.
- Booking creation and availability validation.
- Quotations, where implemented.
- Driver profiles and assignments, where implemented.
- Vehicle handover and return.
- Inspections and operational status.
- Payments, security deposits, refunds, and outstanding balances.
- Maintenance records and fleet availability.
- Existing website integration, API connections, and synchronization.
- Current dashboard, reports, settings, and audit logs.
- Existing validation, error handling, and automated tests.
- Existing security controls and sensitive-data storage.

For every module, report:

- What already exists.
- What works correctly.
- What is partially implemented.
- What is missing.
- What is broken or inconsistent.
- What data or workflow could be affected by a change.
- What can be improved without changing the approved business scope.

Do not assume a feature is absent until you have checked the actual project.

### A.2 Review the agreement

Read the supplied agreement and workflow documents carefully.

Create a scope matrix with these categories:

1. Existing and working functionality.
2. Existing functionality requiring correction.
3. Contractually included but not yet implemented functionality.
4. Improvements proposed within an existing requirement.
5. Features associated with a later milestone.
6. Features that may be new scope or require a written change request.
7. Business decisions awaiting approval.

The agreement describes the initial CRM scope around car rentals, yacht rentals, yacht trips and experiences, customer management, and lead management, with additional functionality allocated to later milestones.

Quotations are identified in Schedule A8 as a Milestone 2 feature. Website API planning and implementation must be assessed against the actual agreement and milestone allocation.

Do not assume a new feature is included merely because it is operationally useful.

If a requested change could alter a contracted workflow, cost, timeline, or milestone, identify it explicitly and explain the impact.

### A.3 Produce the audit deliverables

Before proposing implementation, provide:

- Existing architecture overview.
- Existing database and relationship map.
- Existing workflow diagrams.
- Module-by-module audit.
- Requirements and scope matrix.
- List of defects and risks.
- Missing business decisions.
- Proposed target architecture.
- Proposed database changes.
- Proposed API contracts and dependencies.
- Phased implementation plan.
- Migration and rollback plan.
- Testing plan.
- Acceptance criteria.
- Estimated effort and dependencies.
- Explicit list of features requiring approval or a change request.

### A.4 Approval gate

Stop after presenting the audit and plan.

Ask for explicit approval before beginning implementation.

**Do not interpret a request to prepare this plan as permission to execute it.**

If changes are requested to the plan, revise the plan and obtain approval again.

## Phase B — Controlled implementation after approval

Only begin this phase after the user explicitly approves the relevant implementation plan and scope.

Then:

1. Implement one approved phase at a time.
2. Follow the approved architecture and acceptance criteria.
3. Preserve existing functionality that is outside the change.
4. Make database changes through reviewed, reversible migrations wherever practical.
5. Validate authorization and business rules on the server, not only in the interface.
6. Test every affected workflow and its dependent modules.
7. Report the files, schema changes, API changes, tests, and remaining issues for each phase.
8. Stop and request approval if implementation reveals a material scope change, destructive migration, or unresolved business decision.

Never expand the approved scope silently.

---

# 3. BUSINESS OPERATING PRINCIPLES

Use the following principles throughout the CRM.

## 3.1 One authoritative source of truth

The CRM should be the authoritative source for business data agreed to be managed centrally, including:

- Cars and yachts.
- Categories and service types.
- Yacht trips and experiences.
- Pricing and rate cards.
- Availability and operational restrictions.
- Published/unpublished status.
- Booking requests and confirmed bookings.
- Customer records.
- Quotations and agreed prices.
- Driver profiles and assignments.
- Documents and document-review status.
- Payments, balances, deposits, and refunds.
- Maintenance and inspection records.

The website must consume the appropriate information from the CRM rather than maintain an independent, conflicting version.

The exact source of truth for each data field must be documented during the audit.

## 3.2 Keep related statuses separate

Do not use one status field to represent several different business concepts.

For example, a booking can be financially outstanding while the vehicle has already been returned. A vehicle can have no active booking but remain unavailable because it requires maintenance.

Keep separate concepts for:

- Sales pipeline stage.
- Booking lifecycle status.
- Booking confirmation and allocation.
- Customer document status.
- Payment and settlement status.
- Security deposit and refund status.
- Asset operational condition.
- Asset readiness and availability.
- Maintenance and inspection status.
- Website publishing status.
- Yacht trip status and remaining capacity.

Show related information together on the relevant record, but do not combine unrelated statuses into a single complicated workflow.

## 3.3 Avoid unnecessary complexity

The CRM must remain straightforward for staff.

- Use a limited number of meaningful stages.
- Avoid duplicate records and duplicate data entry.
- Put documents, activities, quotations, payments, and inspection summaries inside the relevant lead, customer, booking, or asset record.
- Use clear action buttons and contextual warnings.
- Show only relevant fields for the selected service and booking type.
- Avoid separate modules when a simple section inside an existing module will work.
- Keep advanced configuration in Settings rather than exposing it throughout daily operations.

Do not add modules solely because they are common in other CRMs.

---

# 4. LEADS AND SALES PIPELINE

## 4.1 Lead sources

The CRM should support leads received through:

- Website enquiry or booking request.
- WhatsApp.
- Social media or other supported channels.
- Manual staff entry.
- Other explicitly configured sources.

During the audit, determine which integrations already exist. Do not claim that WhatsApp, Instagram, Facebook, Snapchat, or TikTok integrations are operational unless they are actually implemented.

For integrations that do not exist, list them as potential additions requiring scope assessment.

## 4.2 Lead creation

A lead should capture the information necessary to understand and progress the enquiry, such as:

- Customer name.
- Phone number.
- Email, if provided.
- Customer type where applicable.
- Requested service.
- Car, yacht, or trip of interest.
- Requested dates and times.
- Guest count where relevant.
- Self-drive or with-driver preference for car rentals.
- Lead source.
- Enquiry details.
- Assigned sales staff member.
- Notes and follow-up activities.

Do not make optional information mandatory without a business reason.

When a lead is created, check for likely duplicates based on available phone numbers and email addresses.

If a matching customer or open lead exists, display a warning and allow authorized staff to review the match. Do not automatically merge records or discard a new enquiry.

## 4.3 Service-specific pipeline selection

Use the appropriate pipeline based on the enquiry:

**Car Rental**

New Lead → Contacted → Requirements Confirmed → Car Selected → Quotation Sent → Negotiation → Documents Received → Payment Pending → Confirmed → Vehicle Delivered → Completed.

**Yacht Charter**

New Lead → Requirements Confirmed → Yacht Selected → Availability Confirmed → Quotation Sent → Deposit Pending → Confirmed → Charter Completed → Balance Paid.

**B2B**

New Company → Decision Maker Identified → Meeting / Call → Proposal → Negotiation → Agreement → Active Account → Renewal.

These are the existing workflow's stated stages. Audit their current implementation before changing them.

Do not force every booking through every stage. For example, a direct booking may follow a different path from a quotation-led sale.

If the user approves simplifying or consolidating stages, preserve the underlying business information and explain how historical records will be handled.

## 4.4 Activities and follow-ups

Staff should be able to record:

- Calls.
- WhatsApp conversations.
- Emails.
- Meetings.
- Notes.
- Follow-up dates.
- Assigned staff members.
- Outcomes.

The system should display relevant activity history on the lead and customer records.

Do not claim that an activity was automatically captured from an external channel unless that integration exists.

## 4.5 Pipeline actions

Support appropriate actions such as:

- Edit lead.
- Record activity.
- Schedule follow-up.
- Select a car, yacht, or trip.
- Prepare a quotation when available.
- Collect required documents.
- Create a booking.
- Mark a lead lost with a reason.
- Convert a qualified lead to a customer when the applicable workflow requires it.

A confirmed booking or agreed B2B conversion should create or link the appropriate customer record without producing duplicates.

A lost lead should retain its history and reason.

---

# 5. CUSTOMER MANAGEMENT

Maintain one customer record that can be linked to multiple enquiries, quotations, bookings, documents, payments, and service histories.

Customer details may include:

- Name or company name.
- Contact details.
- Customer type.
- Relevant identification and business documents.
- Notes.
- Booking history.
- Quotations.
- Document-review history.
- Payment summaries.
- Relevant communication and activity history.

Use a reliable matching and linking process to prevent duplicate customer profiles.

Do not store a separate, inconsistent copy of the customer's core details in every booking. A booking should retain the appropriate historical snapshot of relevant commercial details where needed, while maintaining a link to the customer record.

Customer document visibility must be permission-controlled.

---

# 6. CAR RENTAL WORKFLOW

## 6.1 Booking entry methods

Support both:

1. Direct booking creation by authorized staff or an approved website flow.
2. Quotation-based booking, where the quotation is accepted and converted through the applicable confirmation process.

A lead may be converted into a booking with relevant customer details prefilled.

The booking form should capture:

- Customer.
- Selected car.
- Rental start and end date/time.
- Self-drive or with-driver.
- Driver, when required.
- Rate type.
- Rental price.
- Deposit requirement.
- Delivery or collection details, where applicable.
- Add-ons and extra charges, where applicable.
- Discount.
- Applicable tax.
- Payment details.
- Relevant documents and operational notes.

The form should display the information needed for the selected booking type without unnecessary fields.

## 6.2 Self-drive and with-driver

For a self-drive booking:

- No driver assignment is required.
- Show the relevant customer document checklist.
- Apply the configured document and eligibility requirements.

For a with-driver booking:

- A driver must be selected according to the approved confirmation and dispatch rules.
- Show suitable drivers and their assigned cars.
- Allow an authorized staff member to select or override the assignment when operationally necessary.
- Check driver scheduling conflicts and car availability.
- Record the actual driver assigned to the booking separately from the driver's default car assignment.

Do not build driver pricing, payroll, commissions, HR management, or a driver application.

## 6.3 Rate calculation

Support the rate types that are actually required by the business:

- Hourly.
- Daily.
- Weekly.
- Monthly.
- Seasonal or special-date rates.
- Custom quotations.
- Authorized adjustments.

Calculate the base rental price using the applicable rate card and rental period.

Display the calculation clearly so staff can understand the amount charged.

The system must not silently choose an unapproved pricing method for partial periods. Where the charging method is not explicitly configured, the quotation or booking must use an authorized staff decision.

## 6.4 Availability and booking conflicts

Before confirming a booking, validate:

- The requested date/time range.
- Existing confirmed bookings.
- Relevant preparation and turnaround buffers.
- Extensions to existing bookings.
- Maintenance and operational restrictions.
- Required inspection or cleaning periods.
- Any applicable document or compliance restrictions.
- Driver availability for with-driver bookings.

The server and database must protect against conflicting confirmed bookings, including two staff members or website visitors attempting to book the same asset simultaneously.

A user-interface warning alone is not sufficient.

Do not reserve inventory simply because a quotation has been created or sent.

## 6.5 Saving a booking

When a booking is successfully confirmed:

- Link the customer.
- Link the originating lead and quotation where applicable.
- Store the confirmed commercial details.
- Record the assigned car and driver, if applicable.
- Allocate the car for the confirmed booking.
- Update the appropriate pipeline stage.
- Update the availability calendar.
- Record any deposit or payment requirement.
- Create or update the amount due in Accounts.
- Preserve the booking and allocation history.

Do not mark a booking as fully paid simply because a deposit was received.

---

# 7. SIMPLE DRIVER PROFILES AND CAR ASSIGNMENTS

This is a deliberately limited module.

The business needs to create driver profiles and assign cars to drivers. It does not need a driver charging, payroll, commission, HR, or workforce-management system.

## 7.1 Driver profiles

Support a simple driver record containing the relevant information, such as:

- Driver name.
- Contact information.
- Relevant operational notes.
- Active or inactive status.
- Cars assigned to the driver.
- Current and upcoming bookings, where applicable.

Only collect additional personal or legal details where the business confirms they are needed.

## 7.2 Default car assignment

Authorized staff must be able to:

- Create a driver profile.
- Assign one or more cars to that driver in advance.
- Change or remove a default assignment.
- View which cars are assigned to a driver.
- View the relevant assignment history.

For example, staff may configure which cars a driver normally operates before any booking is created.

## 7.3 Assignment during booking

When creating a with-driver booking:

- If a car is selected first, display the relevant assigned driver or eligible drivers.
- If a driver is selected first, display the cars assigned to that driver.
- Allow an authorized override when necessary.
- Record the actual driver and car allocated to that particular booking.
- Check the driver and car for conflicts.

A default driver-to-car assignment is not the same as a confirmed booking assignment.

Changing a default assignment must not silently change the driver or car on existing confirmed bookings.

Self-drive bookings do not require a driver.

Do not build a separate driver scheduling platform. Implement only the scheduling checks needed to prevent overlapping assignments and support the booking workflow.

---

# 8. YACHT CHARTER WORKFLOW

Yacht charter bookings must use a workflow suited to time-based charter reservations rather than car odometer and fuel tracking.

Capture:

- Customer.
- Selected yacht.
- Charter start and end date/time.
- Guest count.
- Applicable crew or operational requirements.
- Rate type and quoted price.
- Deposit and payment information.
- Required documents, where applicable.
- Operational notes.

Before confirmation, validate:

- Yacht availability.
- Existing charter bookings.
- Charter turnaround and preparation requirements.
- Maintenance and inspection restrictions.
- Applicable crew availability, where the business requires it.
- Any applicable document or operational restriction.

Use charter-specific operational actions instead of car handover and return fields.

A charter can be operationally completed while its financial balance remains outstanding. Do not confuse completion of the service with settlement of the account.

---

# 9. YACHT TRIPS AND EXPERIENCES

Yacht trips are capacity-based experiences and must not be treated as ordinary whole-yacht charter bookings.

## 9.1 Trip setup

Authorized staff should be able to manage:

- Trip or experience name.
- Description and category.
- Assigned yacht.
- Departure date/time.
- Expected return date/time.
- Total guest capacity.
- Price model.
- Published/unpublished status.
- Relevant operational information.
- Applicable schedule and availability.

## 9.2 Supported pricing models

The pricing architecture should be able to accommodate the required business models:

- Fixed price per trip.
- Price per guest.
- Other explicitly configured custom pricing.

The actual price model for a trip must be clear and consistent between the CRM, quotation, and website.

## 9.3 Capacity and availability

For each trip departure, calculate remaining capacity from the approved capacity and confirmed guest allocations.

Before confirming a guest booking:

- Validate the trip schedule.
- Validate the remaining seats.
- Validate any required payment and document conditions.
- Protect against concurrent requests exceeding the capacity.
- Update remaining capacity after a confirmed allocation.

For example, if a trip has a capacity of 20 guests and 15 places are already confirmed, the system must not confirm more than the five remaining places.

A yacht trip and a private yacht charter must not accidentally reserve the same yacht for overlapping periods. The availability engine must understand the relationship between trips, charters, and the physical yacht.

## 9.4 Enable and disable trip functionality

Where supported by the approved implementation:

- Authorized staff can disable Yacht Trips & Experiences.
- Disabling the module hides the relevant website navigation, sections, and listings.
- Historical trip records and booking history remain intact.
- Re-enabling the module restores eligible published trips.
- Existing confirmed bookings are not deleted or silently cancelled when the module is disabled.

Module visibility must not be confused with deletion of data or cancellation of existing commitments.

---

# 10. QUOTATIONS AND PRICE LOCKING

Quotations are an important commercial workflow. The audit must establish whether the current implementation already supports them and how much is included in the applicable milestone.

The full quotation builder is identified as a Milestone 2 feature in Schedule A8. Do not automatically treat full implementation as included in an earlier milestone.

## 10.1 Quotation creation

Where included and approved, staff should be able to create a quotation from a lead, customer, or booking context.

Prefill relevant details:

- Customer.
- Selected car, yacht, or trip.
- Requested dates.
- Pricing model.
- Rate calculation.
- Discount.
- Applicable tax.
- Deposit requirement.
- Add-ons and extra charges.
- Terms and conditions.
- Expiry date.

Allow authorized staff to review and adjust permitted fields.

## 10.2 Quotation statuses

Use a clear quotation lifecycle, such as:

- Draft.
- Sent.
- Negotiation.
- Accepted.
- Rejected.
- Expired.

Audit existing statuses before adding or renaming them.

Expiry should be handled consistently, with a clear record of when the quotation expired.

## 10.3 Sending quotations

Where the feature is included and implemented, support branded PDF output and the approved delivery methods, such as email or WhatsApp.

Do not claim a message was delivered unless the relevant integration confirms delivery.

## 10.4 Acceptance and price lock

An accepted quotation should preserve the agreed commercial terms, including the agreed price and its calculation history.

However:

**Price lock does not equal booking confirmation or inventory reservation.**

When a quotation is accepted:

1. Verify the quotation's validity and accepted terms.
2. Revalidate current inventory availability.
3. Check the applicable booking confirmation requirements.
4. Apply the approved confirmation process.
5. If the asset remains available and confirmation conditions are satisfied, allow conversion into a booking using the agreed price.
6. If the asset is no longer available, flag the conflict and require staff resolution.
7. Never silently change the accepted price to a new rate.
8. Preserve the accepted quotation even if a booking cannot immediately be confirmed.

The system must not promise an unavailable asset merely because a customer accepted a quotation.

---

# 11. BOOKING CONFIRMATION RULES

Use a hybrid confirmation model, subject to approval of the precise configuration.

## 11.1 Website booking requests

A website request may be automatically confirmed only when the required conditions are satisfied and the business has enabled automatic confirmation for that service.

Otherwise, the request should enter a staff-review process.

Conditions may include:

- Asset availability.
- Required information.
- Applicable deposit or payment requirements.
- Required documents at the relevant workflow stage.
- Driver availability where relevant.
- Trip capacity.
- Operational restrictions.

Do not assume all services should be auto-confirmed.

## 11.2 Accepted quotations

Quotation acceptance must trigger revalidation of availability and confirmation rules.

It must not automatically override an existing booking, maintenance restriction, or trip capacity limit.

## 11.3 Staff confirmation

Authorized staff should be able to confirm an eligible pending request.

Any override of a mandatory business rule must be controlled by permissions and recorded in an audit trail.

## 11.4 No premature inventory holds

Do not hold an asset merely because:

- A lead was created.
- A quotation was drafted.
- A quotation was sent.
- A customer expressed interest.

Inventory allocation begins at the configured reservation or confirmation point.

If temporary holds are ever introduced later, they require an explicit business decision defining their duration, expiry, and release behavior.

---

# 12. PRICING, DISCOUNTS, TAX, AND DEPOSITS

## 12.1 Pricing configuration

Use configurable rate cards appropriate to the asset or service.

Support the required models:

- Hourly, daily, weekly, and monthly car rental rates.
- Seasonal or special-date rates.
- Yacht charter rates.
- Fixed trip prices.
- Per-guest trip prices.
- Authorized custom pricing.

Keep the rate configuration manageable and avoid duplicate price records.

## 12.2 Authorized price adjustments

Authorized sales staff may adjust a quotation or booking within the permissions configured for them.

The system should be capable of supporting:

- Discount limits.
- Approval thresholds.
- Restricted pricing fields.
- An audit trail of original and adjusted prices.

The actual discount limits and approval thresholds remain business decisions. Do not invent numerical values.

## 12.3 Tax configuration

Support configurable tax settings where required by the business.

Do not hardcode a tax rate or apply tax to every service without confirmation of the applicable accounting rules.

Record the tax treatment used for each relevant transaction.

## 12.4 Deposit policy

Deposit rules should be configurable by relevant service, asset, booking type, or trip.

The configuration may support:

- Fixed deposit amount.
- Percentage-based deposit.
- Deposit required or not required.
- Applicable payment deadline.
- Conditions for confirmation.

Do not invent the actual deposit values.

## 12.5 Payment deadlines

When a payment deadline is missed:

- Flag the booking for staff review.
- Display the unpaid amount and deadline.
- Notify the appropriate staff where configured.
- Do not automatically cancel the booking unless an explicit, approved policy permits it.

Authorized staff exceptions must be logged.

---

# 13. CUSTOMER DOCUMENT COLLECTION

Customer documents must be part of the relevant lead, booking, or customer workflow, not hidden in a disconnected module.

The objective is to collect required documents without making the process difficult for customers.

## 13.1 Secure upload link

The proposed workflow is:

1. Staff opens the relevant lead, customer, or booking.
2. Staff generates a secure document-upload link.
3. Staff shares the link with the customer through an approved channel.
4. The customer opens a mobile-friendly upload page.
5. The customer uploads the requested documents without needing a customer portal or account.
6. The CRM associates each upload with the correct customer and relevant booking.
7. Authorized staff review the document.
8. Staff approve, reject, or request a replacement.
9. The record displays the current document status and outstanding requirements.

This feature must first be checked against the existing implementation and agreement.

## 13.2 Document requirements by booking type

Use configurable checklists.

For self-drive car rentals, the checklist may include a driving licence, identification, and other documents required by the business for the applicable customer and rental type.

For chauffeur-driven bookings, use the relevant customer checklist without requiring documents that apply only to self-drive.

For yacht charters and trips, request customer or guest documents only where required by the business and applicable rules.

For corporate customers, request company documents when relevant.

Do not assume every document is required for every customer.

The exact mandatory documents and the stage at which they become mandatory must be confirmed.

## 13.3 Document statuses

Use a small, clear status set, such as:

- Required.
- Received / Under Review.
- Approved.
- Rejected.
- Replacement Requested.

A document being uploaded must not automatically mean it has been approved.

## 13.4 Stage-specific restrictions

Missing mandatory documents should block the relevant confirmation, handover, or dispatch action when the approved policy requires it.

Do not block ordinary sales activities or an initial enquiry simply because a customer has not yet uploaded a document that is needed at a later stage.

## 13.5 Security requirements

The upload process must include:

- Unique, unpredictable upload tokens.
- Configurable expiry.
- Link revocation and regeneration.
- Server-side authorization and validation.
- File-type and file-size restrictions.
- Secure private storage.
- Malware scanning where supported.
- Protection against unauthorized access to another customer's documents.
- No publicly accessible sensitive-document URLs.
- Audit events for uploads, reviews, approvals, rejections, and replacements.
- Minimal sensitive information in application logs.
- Appropriate retention and deletion controls according to an approved policy.

Do not expose document contents in notifications or logs unnecessarily.

---

# 14. INVENTORY AND AVAILABILITY ENGINE

Inventory is one of the most important parts of this project.

The system must not merely check whether an asset is marked Available. It must evaluate the actual schedule, allocations, operational restrictions, and applicable service rules.

## 14.1 Asset records

The inventory model should support individual cars and yachts with relevant details, including:

- Unique asset identifier.
- Name or model.
- Category.
- Specifications and images where applicable.
- Pricing configuration.
- Current operational status.
- Maintenance and inspection information.
- Relevant documentation.
- Website publishing status.
- Booking and allocation history.

Do not redesign the existing asset data model until it has been audited.

## 14.2 Availability checks

The availability engine should consider:

- Confirmed bookings.
- Relevant extensions.
- Rental or charter start/end time.
- Preparation time.
- Return-processing time.
- Turnaround time.
- Cleaning and inspection.
- Maintenance.
- Damage-related restrictions.
- Relevant document or compliance restrictions.
- Yacht trip schedules and guest capacity.
- Applicable driver conflicts.

The exact rule for each service should be configurable where appropriate and agreed before implementation.

## 14.3 Preparation and turnaround periods

The proposed design supports configurable preparation, return-processing, and turnaround periods based on the relevant asset, category, service, or operational requirement.

These periods are proposed requirements pending approval.

The system must distinguish the actual booking period from the additional time the asset is unavailable for preparation or operations.

Avoid double-counting overlapping return-processing and turnaround periods.

If an operational task remains incomplete after a configured buffer has elapsed, the asset must remain unavailable until the required clearance is recorded.

A late return must not silently move a subsequent confirmed booking. Instead:

- Flag the conflict.
- Notify the appropriate staff.
- Display the affected booking.
- Allow authorized staff to resolve the issue.
- Preserve both booking histories.

## 14.4 Maintenance and inspections

A car or yacht may be unavailable because of maintenance, inspection, damage, cleaning, or another operational restriction.

Adding a restriction after a future booking has already been confirmed must trigger a conflict warning and an operational resolution process.

Do not automatically delete, cancel, or reschedule the existing booking.

## 14.5 Explainable availability

When an asset is unavailable, staff should be able to see the reason, for example:

- Confirmed booking.
- Preparation buffer.
- Late return.
- Inspection pending.
- Cleaning pending.
- Maintenance.
- Damage restriction.
- Required document or compliance restriction.
- Trip or charter schedule conflict.

Avoid unexplained “Unavailable” messages when the underlying reason is available.

## 14.6 Separate asset statuses

The exact status design should be reviewed during the audit, but the model must distinguish these concepts:

**Booking allocation:** whether an asset is unallocated, allocated, or released.

**Operational condition:** whether the asset is in good condition, requires inspection, is damaged, or needs maintenance.

**Rental readiness:** whether the asset is available, unavailable, or restricted for operations.

**Document compliance:** whether relevant documents are valid, expiring, expired, or missing.

**Trip status:** whether a trip is scheduled, boarding, departed, completed, or cancelled.

Use the minimum practical set of statuses necessary to support these distinctions. Do not create excessive status variations.

---

# 15. VEHICLE HANDOVER, RETURN, INSPECTION, AND SETTLEMENT

The physical return of a vehicle, the inspection, the financial settlement, and the vehicle's availability must be separate but connected processes.

This separation is essential.

## 15.1 Handover

For a confirmed car rental, staff should be able to record:

- Actual handover date/time.
- Odometer reading.
- Fuel level.
- Responsible staff member.
- Assigned driver when applicable.
- Customer acknowledgement or relevant record.
- Security deposit amount and payment method, where applicable.
- Rental payment status.
- Vehicle photos and notes.

After a valid handover, the vehicle can be marked Rented according to the approved operational rules.

The lead should move to Vehicle Delivered where applicable.

Do not allow an invalid or conflicting handover to bypass required operational checks.

## 15.2 Vehicle receipt

When the customer returns the vehicle, staff must first record that the vehicle has physically been received.

Capture:

- Actual return date/time.
- Receiving staff member.
- Odometer reading.
- Fuel level.
- Vehicle photos.
- Notes about visible condition.
- Late-return indicator where applicable.

This action records physical receipt. It does not automatically mean the inspection, financial settlement, or vehicle release is complete.

## 15.3 Inspection

After receipt, record the inspection outcome.

The inspection should support:

- Good condition.
- Minor marks.
- Damaged.
- Missing items.
- Fuel discrepancy.
- Mileage discrepancy.
- Cleaning required.
- Other relevant findings.
- Photos and notes.
- Inspection staff member.
- Inspection completion time.

Use the minimum practical inspection workflow. Do not create unnecessary approval stages for ordinary returns.

If damage or another operational issue is found, the vehicle must remain restricted until the required clearance is recorded.

## 15.4 Calculate rental and additional charges

The return settlement should calculate or display:

- Original rental price.
- Rental extensions.
- Late-return charges.
- Excess-kilometre charges.
- Fuel charges.
- Salik or toll charges.
- Fines.
- Damage charges.
- Cleaning charges.
- Other authorized charges.
- Applicable taxes where relevant.
- Payments already received.
- Security deposit held.
- Outstanding balance.
- Refund due, if applicable.

The system must use the configured rental terms and approved calculations.

Do not invent daily kilometre limits, late fees, excess-kilometre rates, fuel fees, or damage charges.

Where a charge has not been finalized, distinguish an estimate or pending charge from a confirmed charge.

## 15.5 Financial reconciliation

After the inspection and charge calculation, staff should be able to:

- Record additional payment.
- Deduct an authorized amount from the security deposit.
- Record the reason for the deduction.
- Calculate the remaining refundable deposit.
- Refund the remaining amount.
- Record an amount being held for unresolved fines, tolls, or other authorized outstanding items.
- Record a later refund when the outstanding matter is resolved.

The system must retain a clear financial history.

Do not count a refundable security deposit as rental revenue merely because the money was received.

Do not create duplicate payments when a staff member retries an action or records the same transaction twice.

## 15.6 Return completion

The system should allow the relevant stages to be recorded separately:

1. Vehicle physically received.
2. Inspection completed.
3. Charges reviewed.
4. Financial settlement recorded or outstanding balance documented.
5. Operational clearance completed.
6. Booking completed.

The vehicle may be physically returned even if money is still outstanding.

Outstanding amounts should remain in Accounts for collection.

The booking may be completed under the approved workflow while its financial status remains partially paid or outstanding.

However, the vehicle must not automatically become Available while required inspection, cleaning, maintenance, or other operational restrictions remain unresolved.

## 15.7 Vehicle release

After all required operational tasks are completed:

- Release the previous booking allocation.
- Update the vehicle's operational readiness.
- Make the vehicle available only if there are no other restrictions or conflicting allocations.
- Preserve the return and inspection records.

Do not use payment status alone to decide whether a vehicle is operationally available. Financial settlement and vehicle readiness are separate.

---

# 16. ACCOUNTS, PAYMENTS, AND REFUNDS

The Accounts area must make the financial position of each booking understandable without requiring staff to calculate everything manually.

## 16.1 Payment records

A payment record should capture the relevant information, such as:

- Booking and customer.
- Payment amount.
- Payment date/time.
- Payment method.
- Payment reference where available.
- Payment purpose.
- Recording staff member.
- Status.
- Notes.

Support partial payments where required by the approved business rules.

The system must distinguish:

- Rental payment.
- Advance payment.
- Security deposit received.
- Additional charge.
- Deposit deduction.
- Refund.
- Outstanding amount.

## 16.2 Booking financial summary

Each booking should display a clear summary of:

- Agreed rental amount.
- Additional confirmed charges.
- Applicable tax.
- Total payable.
- Payments received.
- Outstanding balance.
- Security deposit received and held.
- Deposit deductions.
- Refund due.
- Refund completed.
- Amount held pending unresolved charges.

Use consistent calculation rules throughout the CRM.

## 16.3 Accounts — To Collect

The Accounts area should display outstanding amounts associated with bookings and other applicable charges.

Staff should be able to see:

- Customer.
- Booking.
- Total payable.
- Amount paid.
- Remaining balance.
- Due date, where applicable.
- Payment status.
- Relevant return or settlement notes.

Recording a valid payment must update the booking's financial summary and outstanding balance.

The booking should no longer appear as fully outstanding when its amount due has been settled.

## 16.4 Payment status

Use a simple, meaningful payment status model, such as:

- Unpaid.
- Partially Paid.
- Paid.
- Refund Due.
- Refund Partially Completed.
- Refunded.

The final set must be reconciled with the existing system and accounting workflow.

A booking can have a Paid rental balance while still having a refundable security deposit. Do not collapse these separate concepts.

## 16.5 Refund controls

Support a configurable cancellation/refund policy, but do not invent the policy values.

The proposed rules are:

- Eligible refunds may be processed automatically if the approved policy and payment integration support it.
- Exceptions or refunds above a configured threshold require authorized approval.
- Every refund must be linked to the relevant payment and booking.
- Refund completion must be distinguished from refund eligibility or approval.
- Failed or pending refunds must remain visible.
- Refund records must not be silently deleted or rewritten.

The actual refund thresholds and approval levels require business approval.

## 16.6 Financial integrity

- Do not silently alter historical payments when pricing rules change.
- Preserve the original agreed price and transaction history.
- Prevent duplicate payment records.
- Record authorized adjustments and their reasons.
- Restrict sensitive financial actions by role.
- Record relevant changes in an audit trail.
- Use consistent monetary precision and rounding rules.
- Define how failed payments and external payment reconciliation are handled before implementation.

Do not build an unnecessarily complex accounting or general-ledger system unless it is confirmed as part of the scope.

---

# 17. CANCELLATIONS AND REFUND WORKFLOW

Cancellation, refund, and inventory release must be related but distinct processes.

## 17.1 Cancellation request

A customer may request cancellation through the website where the feature is available.

The request should be linked to the correct booking and customer.

A cancellation request must not immediately be treated as a completed cancellation.

## 17.2 Staff review

Staff should be able to review the request, the booking status, the cancellation policy, the timing, and any relevant operational constraints.

For in-progress bookings, staff review is required before changing the booking to cancelled.

Authorized staff may cancel within their permissions. Exceptional cases should follow the approved approval process.

## 17.3 Cancellation policy

The proposed policy supports configurable rules based on:

- Service type.
- Booking type.
- Time before the booking.
- Relevant cancellation reason.
- Applicable fees or refund eligibility.
- Whether the service has already started.
- Duration used or remaining, where applicable.

The actual cancellation windows, fees, exceptions, and unused-time rules are not yet defined. Do not invent them.

## 17.4 Inventory release

Once cancellation is confirmed, the booking allocation can be released only when doing so does not conflict with operational restrictions.

For example:

- A cancelled future booking may release the allocation.
- A vehicle currently being rented must not become available merely because a cancellation action was recorded.
- A damaged vehicle must remain restricted.
- A yacht undergoing maintenance must remain unavailable.
- A cancelled trip must retain its historical guest allocations and cancellation records.

## 17.5 Refund handling

Determine the applicable refund under the approved policy.

Track separately:

- Cancellation requested.
- Cancellation approved or rejected.
- Booking cancellation completed.
- Refund eligibility determined.
- Refund approved where required.
- Refund initiated.
- Refund completed or failed.

Do not automatically refund every cancellation.

Do not assume cancellation means the customer is entitled to a full refund.

---

# 18. WEBSITE AND CRM INTEGRATION

The website URL and the actual website source/access must be supplied or discovered before the website can be audited. Do not pretend this audit has already happened.

Once access is available, inspect the actual website and identify the modules that currently exist.

## 18.1 Website modules to audit

Check for:

- Car listings and categories.
- Car detail pages.
- Yacht listings and categories.
- Yacht charter enquiry or booking forms.
- Yacht trips and experiences.
- Trip schedules and remaining capacity.
- Pricing display.
- Date/time selection.
- Availability display.
- Booking requests.
- Customer contact forms.
- Publishing controls.
- Service or category visibility.
- Customer document upload requirements, if present.
- Existing API integrations.
- Existing payment integrations.
- Existing notifications and external services.

Document which modules are operational, incomplete, static, or disconnected from the CRM.

## 18.2 CRM-controlled website content

Where the approved design makes the CRM authoritative, authorized staff should be able to manage the relevant website data through the CRM.

This may include:

- Adding and editing cars.
- Adding and editing yachts.
- Managing categories.
- Managing yacht trips and schedules.
- Editing prices and rate cards.
- Updating availability-related information.
- Publishing or unpublishing listings.
- Enabling or disabling eligible service sections.
- Managing relevant descriptions and images.

Disabling a category or feature must not delete historical bookings or customer records.

## 18.3 Website booking requests

When a customer submits a request:

1. Validate the incoming data.
2. Identify or create the relevant customer and enquiry without unnecessary duplication.
3. Identify the requested service and asset.
4. Validate the requested dates or trip capacity.
5. Apply the approved booking-request workflow.
6. Store the request in the CRM.
7. Display its correct status to staff.
8. Confirm the request only under the approved confirmation rules.
9. Provide an appropriate response to the customer.

Do not tell a customer that a booking is confirmed if the system has only recorded a request.

## 18.4 Shared availability

The website and CRM must use the same authoritative availability rules.

The website must not show an asset as bookable based on stale or independently maintained data.

Revalidate availability when a booking request is processed and again at the confirmation/allocation point.

The system must protect against concurrent requests and overbooking.

## 18.5 API design — plan before implementation

Prepare the required API contracts and dependencies before implementing the website integration.

For each API, document:

- Purpose.
- Request fields.
- Response structure.
- Authentication and authorization.
- Validation rules.
- Error responses.
- Idempotency requirements.
- Availability revalidation.
- Rate limiting where appropriate.
- Data ownership.
- Synchronization behavior.
- Logging and audit requirements.
- Testing requirements.

Potential API groups include:

- Public or appropriately protected inventory listings.
- Car and yacht details.
- Published categories.
- Published yacht trips.
- Trip schedules and available capacity.
- Availability checks.
- Booking requests.
- Quotation-related functions where approved.
- Customer document-upload initiation.
- Relevant booking updates where required.

These are candidate API groups, not confirmed endpoint names. Determine the actual endpoint design after inspecting the current architecture.

Do not create a separate website database that can independently change the same inventory or pricing records without an explicit architectural reason.

## 18.6 Integration security

- Validate all incoming requests on the server.
- Do not expose internal administrative APIs publicly.
- Do not embed secrets in frontend code.
- Apply appropriate authentication and permissions.
- Protect customer and document data.
- Validate webhooks where used.
- Use idempotency for actions that must not be duplicated.
- Log meaningful integration errors without exposing sensitive information.
- Define how failed synchronization is detected and recovered.

Do not implement the full website integration until its scope, dependencies, and milestone allocation are approved.

---

# 19. DASHBOARD, REPORTS, AND ALERTS

The dashboard should help staff understand what requires attention today.

Prioritize useful operational information, such as:

- Cars going out today.
- Cars expected back today.
- Late returns.
- Bookings awaiting confirmation.
- Quotations awaiting follow-up.
- Missing or rejected documents.
- Deposits or payments pending.
- Outstanding balances to collect.
- Refunds awaiting processing.
- Vehicles awaiting inspection.
- Vehicles under maintenance.
- Upcoming yacht charters.
- Upcoming yacht trips and remaining capacity.
- Operational conflicts requiring staff action.

Reports may include:

- Bookings by period.
- Rental revenue based on correctly defined financial data.
- Amounts collected.
- Outstanding balances.
- Refunds.
- Utilization where reliable data is available.
- Cancelled bookings.
- Trip capacity and guest allocations.

Do not label deposits as revenue or calculate financial totals using inconsistent definitions.

Make dashboard figures traceable to the underlying records.

Avoid filling the dashboard with charts that do not help staff take action.

---

# 20. USER ROLES, PERMISSIONS, AND AUDIT HISTORY

Inspect the existing permission model before proposing changes.

Use role-based permissions appropriate to actual business responsibilities.

Permissions may need to distinguish:

- Viewing records.
- Editing customer information.
- Managing inventory.
- Changing prices.
- Applying discounts.
- Confirming bookings.
- Assigning drivers.
- Reviewing documents.
- Recording payments.
- Applying deposit deductions.
- Issuing refunds.
- Cancelling bookings.
- Overriding operational restrictions.
- Managing website publishing.
- Managing system settings.

The exact roles and approval levels must be confirmed against the current system and business requirements.

Sensitive actions must be permission-controlled and logged.

Audit records should capture relevant information such as:

- User.
- Action.
- Record affected.
- Date/time.
- Relevant old and new values.
- Reason or approval reference when required.

Do not store passwords, private tokens, or unnecessary sensitive document data in audit logs.

Do not allow an ordinary user to bypass server-side permissions by modifying a frontend request.

---

# 21. NOTIFICATIONS AND AUTOMATION

Automations should make the business easier to manage, not create unpredictable behavior.

Potential notifications include:

- New website enquiry.
- Booking request awaiting review.
- Quotation follow-up.
- Missing documents.
- Payment deadline approaching or missed.
- Upcoming handover.
- Upcoming return.
- Late return.
- Inspection required.
- Outstanding balance.
- Refund awaiting processing.
- Maintenance restriction affecting a booking.
- Trip capacity or scheduling conflict.

For each automation, define:

- Trigger.
- Conditions.
- Recipient.
- Delivery channel.
- Message content.
- Retry behavior.
- Duplicate prevention.
- Failure handling.
- Audit history.

Only implement the channels that are actually connected and included in the approved scope.

A failed notification must not silently change a booking's commercial or operational status.

Do not cancel bookings automatically because a reminder failed to send.

---

# 22. SECURITY, DATA INTEGRITY, AND RELIABILITY

Treat security and data integrity as core requirements.

Review:

- Authentication.
- Session handling.
- Authorization.
- Server-side input validation.
- Database constraints.
- Protection against SQL injection.
- Cross-site scripting protections.
- Cross-site request forgery protections where applicable.
- File upload security.
- Sensitive-data exposure.
- Rate limiting where appropriate.
- API authentication.
- Secret management.
- Audit logging.
- Error handling.
- Data migration safety.
- Concurrent booking protection.
- Payment duplication protection.
- Data retention and deletion policies.

Use appropriate database transactions and constraints to protect confirmed booking allocations and trip capacity.

Avoid a design in which two simultaneous requests can both pass a check and confirm the same car for overlapping periods.

Before making a schema change, document:

- Existing schema.
- Proposed schema.
- Reason for the change.
- Data migration steps.
- Constraints and indexes.
- Compatibility considerations.
- Rollback approach.
- Testing strategy.

Never delete production data, reset the database, replace configuration, or perform destructive migrations without explicit approval.

---

# 23. DATABASE AND DATA MODEL PLANNING

During the audit, map the actual entities and their relationships.

The target design may require relationships among:

- Customers.
- Leads.
- Activities.
- Cars.
- Yachts.
- Yacht trips and departures.
- Trip guest allocations.
- Bookings.
- Booking assets and allocation history where needed.
- Quotations.
- Quotation items or pricing details where needed.
- Drivers.
- Default driver-to-car assignments.
- Actual booking driver assignments.
- Customer documents.
- Document requirements and reviews.
- Payments.
- Security deposits.
- Deposit deductions.
- Refunds.
- Additional charges.
- Inspections.
- Maintenance records.
- Availability restrictions.
- Website publication records or configuration where necessary.
- Users and permissions.
- Audit events.

This is a candidate model, not an instruction to create every table.

Inspect the existing database first. Reuse suitable structures, avoid unnecessary duplication, and propose only the changes required by the approved workflows.

For historical integrity, determine which commercial terms and operational facts must remain unchanged after a booking is confirmed.

---

# 24. USER INTERFACE AND DAILY USABILITY

The CRM should feel like a polished professional rental-management application, not a collection of disconnected admin pages.

## 24.1 Design principles

- Clean, premium, professional interface.
- Clear navigation.
- Consistent layouts and terminology.
- Responsive behavior.
- Readable tables and forms.
- Helpful empty states.
- Clear loading and error states.
- Accessible form labels.
- Contextual validation messages.
- Confirmation for destructive actions.
- Consistent date, time, currency, and number formatting.

Do not redesign the entire interface without auditing the current design and obtaining approval for material changes.

## 24.2 Record pages

Relevant information should be grouped naturally.

For example, a booking page can show:

- Booking overview.
- Customer.
- Car or yacht.
- Dates and schedule.
- Driver, where applicable.
- Document checklist.
- Quotation and agreed price.
- Payment summary.
- Handover or return information.
- Inspection and additional charges.
- Activity and audit history.

Use tabs, sections, or expandable panels only where they genuinely improve usability.

## 24.3 Context-sensitive forms

For self-drive, show the relevant customer document checklist and omit driver fields.

For with-driver, show driver selection and assignment information.

For a yacht charter, show charter details rather than car odometer and fuel fields.

For a yacht trip, show departure schedule, guest count, and remaining capacity.

For vehicle return, show the receipt, inspection, charges, and settlement actions in the correct order.

Do not make every field visible on every form.

## 24.4 Safe actions

Clearly distinguish actions such as:

- Save Draft.
- Submit Request.
- Confirm Booking.
- Record Payment.
- Receive Vehicle.
- Complete Inspection.
- Record Refund.
- Complete Return.
- Release Asset.
- Cancel Booking.

Each action must have a defined effect and must validate its prerequisites.

Avoid buttons that appear to work but do not save data or update the actual business state.

---

# 25. TESTING AND ACCEPTANCE CRITERIA

Before implementation, create a test plan linked to the actual workflows and approved requirements.

After each approved implementation phase, execute the relevant tests.

## 25.1 Lead tests

Verify:

- Lead creation.
- Duplicate warnings.
- Pipeline selection.
- Activity logging.
- Lost reason.
- Customer linking.
- Conversion into the correct booking workflow.

## 25.2 Car booking tests

Verify:

- Self-drive booking.
- With-driver booking.
- Driver assignment.
- Default driver-to-car assignment.
- Authorized driver override.
- Rate calculation.
- Discount and tax handling.
- Deposit requirement.
- Availability validation.
- Conflicting date ranges.
- Booking confirmation.
- Partial payment.
- Outstanding balance.

## 25.3 Concurrency tests

Test two simultaneous attempts to confirm overlapping bookings for the same car.

Only a valid allocation should succeed.

Test concurrent trip bookings that approach or exceed the remaining guest capacity.

Verify that the system cannot overbook the asset or trip through simultaneous requests.

## 25.4 Document tests

Verify:

- Upload-link creation.
- Token expiry.
- Link revocation.
- Valid upload.
- Invalid file rejection.
- Unauthorized document access prevention.
- Document review.
- Rejection and replacement.
- Missing mandatory document restrictions.
- Correct association with the customer and booking.

## 25.5 Vehicle return tests

Verify:

- Physical receipt.
- Odometer and fuel recording.
- Inspection.
- Damage and cleaning restrictions.
- Late-return calculation.
- Excess-kilometre calculation.
- Additional charges.
- Security deposit deduction.
- Refund calculation.
- Outstanding balance.
- Return completion.
- Vehicle availability after operational clearance.

Test the case where the vehicle has been returned but payment remains outstanding.

Test the case where the customer has paid but the vehicle remains unavailable because inspection or maintenance is incomplete.

## 25.6 Yacht tests

Verify:

- Charter scheduling.
- Yacht availability.
- Turnaround periods.
- Trip departure schedules.
- Remaining guest capacity.
- Concurrent trip bookings.
- Charter/trip overlap prevention.
- Trip publishing and disabling.
- Preservation of historical records.

## 25.7 Cancellation and refund tests

Verify:

- Cancellation request.
- Staff approval or rejection.
- Cancellation during an in-progress service.
- Inventory release.
- Operational restrictions after cancellation.
- Refund eligibility.
- Refund approval.
- Partial or failed refund.
- Outstanding charges.
- Audit history.

## 25.8 Website integration tests

Verify:

- CRM inventory appears correctly on the website.
- Price changes propagate according to the approved synchronization design.
- Unpublished items are handled correctly.
- Disabled modules are hidden without deleting history.
- Website requests create the correct CRM records.
- Availability is revalidated.
- Errors are displayed and logged appropriately.
- Sensitive APIs and documents cannot be accessed without authorization.

## 25.9 Regression tests

After each change, retest affected existing functionality.

A new feature is not accepted merely because its own screen works if it breaks an existing booking, payment, customer, or inventory workflow.

## 25.10 Acceptance evidence

For each approved feature, provide:

- Requirement reference.
- Test case.
- Expected result.
- Actual result.
- Pass/fail status.
- Relevant logs or screenshots where useful.
- Known limitations.
- Remaining defects.

Do not claim that a test passed unless it was actually executed.

---

# 26. IMPLEMENTATION PHASING

Prepare a proposed phase plan based on the audit and contract.

Use the following as a planning framework, not as pre-approved authorization.

## Phase 0 — Audit and specification

- Inspect the existing application.
- Review the agreement and workflow files.
- Document existing functionality.
- Map the database and dependencies.
- Identify defects and risks.
- Finalize proposed business rules.
- Define acceptance criteria.
- Obtain approval.

No feature implementation during this phase.

## Phase 1 — Existing CRM workflow corrections

Subject to the agreed milestone and approval:

- Correct lead and customer workflows.
- Correct booking validation.
- Correct inventory allocation behavior.
- Correct driver profile and assignment behavior.
- Correct document requirements and workflow.
- Correct vehicle handover and return behavior.
- Correct payment and settlement summaries.
- Improve relevant operational statuses.

Do not assume all these changes belong to the same contractual milestone. Map each to the agreement first.

## Phase 2 — Quotations and website integration

Assess the contracted Milestone 2 scope, including Schedule A8.

Plan and implement approved features such as:

- Quotation builder and lifecycle.
- Price locking and booking conversion.
- Website API contracts.
- CRM-controlled inventory and pricing.
- Website booking requests.
- Shared availability validation.
- Relevant publishing controls.

Complete API design and integration planning before implementation.

## Phase 3 — Operational improvements

Consider additional approved work such as:

- Preparation and turnaround configuration.
- Improved maintenance conflict handling.
- Enhanced return inspection.
- Advanced settlement handling.
- Operational alerts and reports.

Any item that changes scope or exceeds the agreement must be separately identified and approved.

## Phase 4 — Final QA and handover

- Execute end-to-end tests.
- Execute regression tests.
- Resolve critical defects.
- Validate permissions.
- Validate data integrity.
- Document the final workflows.
- Document configuration and deployment.
- Provide a list of completed, incomplete, and excluded items.
- Obtain final acceptance.

Do not begin a later phase while unresolved critical defects make the current phase unsafe.

---

# 27. REQUIREMENTS THAT MUST NOT BE ASSUMED

The following business decisions need explicit confirmation where they are not already defined in the agreement or existing configuration:

- Exact mandatory customer documents for each service and customer type.
- Exact stage at which each document becomes mandatory.
- Deposit amounts and percentages.
- Discount limits and approval thresholds.
- Tax treatment and tax rates.
- Partial-period charging rules.
- Cancellation windows and fees.
- Refund eligibility and approval thresholds.
- Unused-time and unused-trip-place rules.
- Payment deadlines.
- Preparation, return-processing, and turnaround durations.
- Maintenance and inspection release conditions.
- Exact driver eligibility and scheduling rules.
- Yacht crew availability requirements.
- Website confirmation settings by service.
- Supported payment methods and refund integrations.
- Data-retention periods.
- Exact staff roles and permission levels.

For every unresolved decision, provide:

1. The decision needed.
2. Why it matters.
3. The available options, where useful.
4. The consequences of each option.
5. A recommendation, clearly labelled as a recommendation.
6. Whether the decision affects contract scope or implementation effort.

Do not block unrelated work because one configurable policy is undecided. However, do not implement a business-critical rule by guessing.

---

# 28. EXPLICITLY EXCLUDED FEATURES UNLESS SEPARATELY APPROVED

Do not build the following merely because they are common in rental-management systems:

- Customer portal, customer login, or customer dashboard.
- Separate driver application.
- Driver payroll.
- Driver commissions.
- Driver-specific pricing or charges.
- Driver HR or workforce management.
- Unrequested accounting/general-ledger modules.
- Unrequested marketing automation.
- Unrequested social-media publishing tools.
- New integrations that are not included in the agreed scope.
- Large architectural rewrites when a smaller safe change is sufficient.

A secure customer document-upload link does not mean the project should include a customer portal.

A driver profile does not mean the project needs driver payroll or HR.

A payment summary does not mean the project needs a complete accounting platform.

If a feature appears useful, describe it as a future option and assess its scope separately.

---

# 29. REQUIRED FINAL AUDIT REPORT

Before making any changes, deliver the following report in a clear, structured format.

## Section A — Existing system

Describe the actual application, modules, architecture, database, integrations, and current workflows.

## Section B — Agreement and scope

Map every relevant requirement to the agreement, schedule, and milestone.

Clearly distinguish contracted work, existing functionality, proposed improvements, and potential change requests.

## Section C — Workflow maps

Provide separate workflow maps for:

- Car rental.
- Yacht charter.
- Yacht trips and experiences.
- Leads and customers.
- Quotations.
- Document collection.
- Driver assignment.
- Vehicle handover, return, and inspection.
- Payments, deposits, and refunds.
- Cancellation.
- Website booking requests and synchronization.

## Section D — Business rules

Create a structured rules table with:

- Rule ID.
- Rule description.
- Current behavior.
- Proposed behavior.
- Source or reason.
- Dependencies.
- Approval status.
- Testable acceptance criteria.

Mark every new rule as **Proposed — Pending Approval** until the user explicitly approves it.

## Section E — Gaps and risks

List missing functionality, defects, conflicting rules, security concerns, data risks, and dependencies.

## Section F — Proposed technical changes

Describe the minimum required changes to:

- Frontend.
- Backend.
- Database.
- APIs.
- Website.
- Permissions.
- Notifications.
- Tests.

Include migration and rollback plans where applicable.

## Section G — Implementation plan

For every proposed phase, specify:

- Features included.
- Features excluded.
- Dependencies.
- Files or modules likely to be affected.
- Database changes.
- API changes.
- Risks.
- Test plan.
- Acceptance criteria.
- Contract milestone.
- Whether a change request may be required.

## Section H — Decisions required

Present the unresolved decisions in priority order.

Ask focused questions only where the answer materially changes business behavior, architecture, security, or scope.

Do not ask the user to reconfirm details already defined in the supplied agreement or workflow documents.

## Section I — Approval request

End the report by asking the user to review and approve the plan.

Do not proceed to coding until explicit approval is received.

---

# 30. FINAL QUALITY STANDARD

The finished system must satisfy these principles:

- One consistent source of truth for agreed business data.
- No conflicting confirmed car or yacht allocations.
- No yacht trip overbooking.
- Clear separation of price acceptance, booking confirmation, and inventory allocation.
- Simple driver profiles and car assignments without unnecessary driver-management features.
- Secure customer document collection and review.
- A clear vehicle receipt, inspection, settlement, and release process.
- Accurate balances, deposit deductions, and refunds.
- Separate financial status and operational availability.
- Website and CRM synchronization based on approved API contracts.
- No silent cancellation, price changes, data deletion, or booking reassignment.
- Appropriate permissions and audit history.
- Minimal unnecessary screens, fields, modules, and statuses.
- Verified testing and documented acceptance criteria.
- Clear separation between contracted work and proposed new features.

The system should be reliable enough for daily business operations, easy for staff to use, and maintainable for future development.

**Correctness, data integrity, operational clarity, and scope control are more important than rushing to implement features.**

## YOUR FIRST ACTION

Begin by auditing the actual project and reviewing the supplied `CRM-Workflow.md` and `CRM_Agreement_REDPRIME.pdf`.

If the website, database, repository, or required files are not accessible, identify exactly what is missing and request access. Do not invent audit findings.

Prepare the complete audit report and implementation plan.

**Stop after presenting the report. Do not write or modify application code, database schemas, migrations, live website content, or production data until the user explicitly approves the proposed implementation plan and relevant scope.**