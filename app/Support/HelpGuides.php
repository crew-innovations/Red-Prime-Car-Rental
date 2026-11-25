<?php

namespace App\Support;

/**
 * 20 Nov (Bilal, item 12): the "?" help of every CRM page, written for staff from what each page
 * really does. One guide per module; each page of the module opens its guide (the most useful part
 * first). Keep this file up to date when a page changes (see CLAUDE.md).
 *
 * Guide: title, for (what the page is for), where (menu), who (roles, by default; Admin can change
 * permissions in Business settings › Roles & permissions), steps (how to …: numbered steps),
 * buttons (button / tab / field => what it does), after (what happens after the main actions),
 * tips.
 */
class HelpGuides
{
    /** Route name (without "filament.admin.") => guide key; "*" = any page of that list. */
    public const ROUTES = [
        'pages.dashboard' => 'dashboard',
        'resources.leads.*' => 'leads',
        'pages.pipeline' => 'pipeline',
        'resources.quotations.*' => 'quotations',
        'resources.customers.*' => 'customers',
        'resources.documents.*' => 'documents',
        'resources.bookings.*' => 'bookings',
        'pages.calendar' => 'calendar',
        'pages.dispatch' => 'dispatch',
        'pages.live-drivers' => 'dispatch',
        'resources.drivers.*' => 'drivers',
        'resources.damage-reports.*' => 'damage',
        'resources.contracts.*' => 'contracts',
        'resources.cars.*' => 'cars',
        'resources.yachts.*' => 'yachts',
        'resources.trips.*' => 'trips',
        'resources.packages.*' => 'trips',
        'resources.suppliers.*' => 'suppliers',
        'resources.transfer-services.*' => 'limousine',
        'resources.vehicle-categories.*' => 'limousine',
        'resources.transfer-rates.*' => 'limousine',
        'resources.transfer-fixed-routes.*' => 'limousine',
        'resources.price-rules.*' => 'pricing',
        'resources.price-extras.*' => 'pricing',
        'pages.rate-card' => 'pricing',
        'pages.price-check' => 'pricing',
        'resources.transactions.*' => 'payments',
        'resources.invoices.*' => 'invoices',
        'resources.payment-requests.*' => 'online-payments',
        'resources.traffic-fines.*' => 'fines',
        'resources.toll-trips.*' => 'fines',
        'resources.toll-accounts.*' => 'fines',
        'pages.statement-import' => 'fines',
        'resources.fuel.*' => 'fuel',
        'pages.fuel-report' => 'fuel',
        'pages.accounts-overview' => 'reports',
        'pages.profit-reports' => 'reports',
        'pages.rental-reports' => 'reports',
        'pages.discount-report' => 'reports',
        'pages.lead-reports' => 'reports',
        'resources.history.*' => 'audit',
        'pages.messages' => 'messages',
        'pages.agents' => 'agents',
        'resources.users.*' => 'team',
        'pages.settings' => 'settings',
        'pages.settings.*' => 'settings',
        'pages.business-settings' => 'settings',
        'pages.integrations' => 'settings',
        'pages.apps' => 'settings',
        'resources.email-accounts.*' => 'email',
        'resources.email-templates.*' => 'email',
        'resources.email-log.*' => 'email',
        'pages.announcements' => 'email',
        'pages.website-content' => 'website',
        'resources.categories.*' => 'website',
        'resources.blog-posts.*' => 'website',
        'resources.lead-forms.*' => 'website',
        'resources.lead-sources.*' => 'website',
        'pages.driver.jobs' => 'driver',
        'pages.driver-job' => 'driver',
        'pages.driver.schedule' => 'driver',
        'pages.driver.fuel' => 'driver',
        'pages.my-profile' => 'driver',
        'pages.my-notifications' => 'notifications',
        'pages.help' => 'help',
    ];

    /** Which "how to" opens first on a given page. */
    public const FOCUS = [
        'resources.leads.create' => 'Add a new lead',
        'resources.bookings.create' => 'Make a new booking',
        'resources.quotations.create' => 'Make a quotation',
        'resources.customers.create' => 'Add a customer',
        'resources.contracts.create' => 'Make a new contract (without opening a booking)',
        'resources.cars.create' => 'Add a car',
        'resources.drivers.create' => 'Add a driver',
    ];

    public static function forRoute(string $route): ?string
    {
        if (isset(self::ROUTES[$route])) {
            return self::ROUTES[$route];
        }
        foreach (self::ROUTES as $pattern => $key) {
            if (str_ends_with($pattern, '.*') && str_starts_with($route, substr($pattern, 0, -1))) {
                return $key;
            }
        }

        return null;
    }

    public static function all(): array
    {
        return [
            'dashboard' => [
                'title' => 'Home (dashboard)',
                'for' => 'Your day at a glance: what needs you today first (cars going out and coming back, late returns, cars to prepare, trips), then sales (new leads, follow-ups due, quotes waiting).',
                'where' => 'Menu › Home. It is the first page after you log in.',
                'who' => 'Everyone. Agents see only their own leads, customers and bookings; Accounts see money first; Admin sees everything.',
                'steps' => [
                    'Work through today' => [
                        'Look at the cards at the top: numbers in red need action now.',
                        'Tap a card or a number: it opens the list with only those records (for example today\'s handovers).',
                        'Open a record and press its big red button: that is always the next step.',
                    ],
                    'Start something new' => [
                        'Use the quick buttons at the top (New lead, New quotation, New booking).',
                        'They open the same forms as the menu.',
                    ],
                ],
                'buttons' => [
                    'Today / Out / Back cards' => 'Bookings handed over or returned today, and late returns.',
                    'Fleet' => 'Cars and yachts available, rented, in maintenance or needing preparation.',
                    'Leads and follow-ups' => 'New enquiries and follow-ups that are due; opens the leads list.',
                    'Trend and sources' => 'How many leads came in and from where (website, WhatsApp, Instagram…).',
                ],
                'after' => ['Nothing is changed from the dashboard itself: it only shows and opens lists.'],
                'tips' => ['Refresh the page to see new numbers. Notifications (the bell) tell you about new leads and jobs.'],
            ],

            'leads' => [
                'title' => 'Leads',
                'for' => 'Every enquiry, from the website, WhatsApp, Instagram, TikTok, shared forms or added by hand, followed from first contact to won (booking) or lost.',
                'where' => 'Admin: Menu › Leads. Agents: Menu › My leads.',
                'who' => 'Admin and Agents add and work leads. Agents see only their own leads and leads waiting for an agent. Only Admin gives a lead to someone else and deletes (archives) leads.',
                'steps' => [
                    'Add a new lead' => [
                        'Press "New lead" (top right of the list).',
                        'Customer: if the person is already a customer, pick them in "Customer" (search by name, phone or email).',
                        'Otherwise fill in the customer form below it. It is the same form as in New Booking: type, full name, phone, WhatsApp, email, residency, nationality, date of birth, address, ID and licence, and documents if you have them.',
                        'Pick the Source (where the enquiry came from).',
                        'Under "What they want": the service, the car / trip if known, dates, guests and their message.',
                        'Optional: "More options" for budget and follow-up date.',
                        'Press Create. You become the owner of the lead (Admin can leave it unassigned: it then goes to all agents).',
                    ],
                    'Edit a lead' => [
                        'Open the lead from the list (tap its row).',
                        'Change what you need and press "Save changes".',
                        'For a lead with a customer, the customer\'s details are changed with "Customer details" next to the customer field (same form as New Booking).',
                    ],
                    'Move a lead forward (status)' => [
                        'The status follows the pipeline stage: change "Stage" on the lead, or drag the card on the Pipeline board.',
                        'Won makes or links the customer profile (never a duplicate) and the lead counts as converted.',
                        'Lost needs a reason: use "Mark as lost".',
                    ],
                    'Make a quotation or booking from a lead' => [
                        'Open the lead and press "Create quotation" or "Create booking".',
                        'The customer, service, car and dates are filled in from the lead; you do not pick the customer again.',
                        'If the lead has no customer profile yet, it is made from the lead\'s details when you save.',
                    ],
                    'Give a lead to an agent (Admin)' => [
                        'Open the lead and use "Assign to salesperson" (or select several in the list).',
                        'The agent is told and must accept the lead. A lead nobody accepts stays under "Waiting for an agent".',
                    ],
                    'Accept a lead (Agent)' => [
                        'Open "Waiting for an agent", open the lead and press "Accept lead". The first agent to accept owns it.',
                        '"Hand back" gives it back to the team if you cannot handle it.',
                    ],
                    'Delete a lead' => [
                        'Admin only: open the lead, "More" › Delete. Deleted leads are archived (kept in the history), not wiped.',
                    ],
                    'Search, filter and work the list' => [
                        'Type a name, phone or email in Search.',
                        'Use the tabs: New, My leads, Follow-up due, Waiting for an agent, Unassigned, Won, Lost, All.',
                        'Filters (funnel icon): source, service, agent, dates.',
                        'Row buttons: Call and WhatsApp open the phone / WhatsApp with the number.',
                    ],
                ],
                'buttons' => [
                    'Create customer profile' => 'Opens the New Booking customer form filled in from the lead, or links the customer already on file with the same phone / email.',
                    'Create quotation / Create booking' => 'Opens the quote or booking form with the lead\'s details filled in.',
                    'Mark as lost' => 'Closes the lead; asks for the reason.',
                    'Accept lead / Hand back' => 'Take or return ownership (agents).',
                    'Quick limousine price' => 'Prices a ride from the lead (limousine leads).',
                    'Pipeline board' => 'The same leads as cards by stage.',
                    'Source' => 'Where the enquiry came from (edit the list in Settings › Lead sources).',
                    'Stage' => 'Where the sale is; the status (new, contacted, won, lost…) follows it.',
                ],
                'after' => [
                    'A new lead with customer details makes a customer profile (a "prospect" until the lead is won) and links it.',
                    'Leads from the website, Instagram, etc. that have no profile keep their own contact fields until "Create customer profile" is used.',
                    'Follow-up reminders appear on Home and in notifications when their date comes.',
                ],
                'tips' => ['Website and social leads arrive by themselves; you are notified.', 'Every change is kept in the lead\'s History tab.'],
            ],

            'pipeline' => [
                'title' => 'Pipeline board',
                'for' => 'Your leads as cards, one column per stage (Car, Yacht and B2B pipelines).',
                'where' => 'Menu › Leads › Pipeline (Agents: My leads › Pipeline).',
                'who' => 'Admin and Agents (agents see their own leads).',
                'steps' => [
                    'Move a lead to another stage' => ['Drag the card to the new column and let go.', 'Dropping on Lost asks for the reason ("Why was it lost?").', 'Dropping on Won makes or links the customer profile.'],
                    'Open or add a lead' => ['Tap a card to open the lead.', '"Add lead" opens the new lead form; "List view" goes back to the list.'],
                ],
                'buttons' => ['Create booking (on a card)' => 'Opens a booking filled in from that lead.'],
                'after' => ['The lead\'s status changes with the stage straight away and is kept in its history.'],
                'tips' => [],
            ],

            'quotations' => [
                'title' => 'Quotations',
                'for' => 'Price offers sent to customers. A customer can accept online from the link; an accepted quote becomes a booking with one tap.',
                'where' => 'Admin: Menu › Leads › Quotations. Agents: My leads › Quotations.',
                'who' => 'Admin and Agents make, send and convert quotations (agents: their own). Discounts above the agent\'s limit need Admin approval.',
                'steps' => [
                    'Make a quotation' => [
                        'From a lead or customer press "Create quotation" (the customer is filled in), or press "New quotation" here.',
                        'Step 1, Customer & car: pick the customer or add a new one with "+" (same form as New Booking); choose the service and the car / yacht / trip.',
                        'Step 2, Dates: pick-up and return.',
                        'Step 3, Price: worked out from the rate card; add extras, a discount (with reason) or VAT if switched on.',
                        'Step 4, Check & send: what the customer sees, valid until, terms.',
                        'Press "Save and send": the send window opens (email / WhatsApp).',
                    ],
                    'Change a sent quotation' => ['A sent quote is locked so the customer sees what you sent. Make a new version from the quote.'],
                    'Turn an accepted quote into a booking' => ['Open "Accepted · to book", open the quote and press its red button. The booking is made with the same price and customer.'],
                ],
                'buttons' => [
                    'Tabs' => 'Open (draft / sent), Accepted · to book, Declined / expired, All.',
                    'Send on WhatsApp' => 'Opens WhatsApp with the quote link.',
                    'Open customer / Open lead' => 'Jump to the linked records.',
                    'Customer details' => 'Next to the customer: opens the full customer form.',
                ],
                'after' => [
                    'The customer gets a link to view, accept or decline. You are told when they answer.',
                    'An expired quote cannot be accepted until "Valid until" is changed.',
                    'Accepting can ask the customer for documents (in their account, or by a link if they have no account).',
                ],
                'tips' => ['From a lead without a customer profile, the lead\'s details are shown; the profile is made when you save.'],
            ],

            'customers' => [
                'title' => 'Customers',
                'for' => 'Everyone who rents or asked to rent: details, ID and licence, documents, bookings, quotations, payments, fines and history in one place.',
                'where' => 'Admin: Menu › Leads › Customers. Agents: My customers.',
                'who' => 'Admin and Agents (agents see only their own customers). Blacklisting and deleting: Admin.',
                'steps' => [
                    'Add a customer' => [
                        'Press "Add customer".',
                        'Fill in the customer form (same as New Booking): Contact (type, full name as on ID, phone, WhatsApp, email, residency, nationality, date of birth, company and TRN for companies, address).',
                        'ID and driving licence: Emirates ID and expiry, passport and expiry, licence number, country, issue and expiry dates.',
                        'Documents: upload photos / PDFs, or press "Scan with camera" to take a photo on the phone or webcam; tick "I checked these against the originals" to approve them now.',
                        'Optional: "Read the documents (OCR)" fills empty fields from the uploads; check them.',
                        'Preferences (contact preference, language, marketing, tags), Account (VIP, source, account manager, follow-up) and notes.',
                        'Press Create. A phone or email already used by another customer is refused, so there are no duplicates.',
                    ],
                    'Edit a customer' => ['Open the customer, change the fields and press "Save changes". The same details show in bookings, quotations, leads and contracts.'],
                    'Start work for a customer' => ['On the customer page use New enquiry, New contract, or the Quotations / Bookings tabs to create one for this customer.'],
                    'Blacklist a customer (Admin)' => ['Open the customer, "More" › Blacklist, give the reason. Staff see a red warning on their bookings.'],
                ],
                'buttons' => [
                    'Tabs' => 'Customers, Prospects (profile made before they booked), VIP, Corporate & agencies, My customers, Follow-up due, Never logged in, Documents to review.',
                    'ID, licence & documents' => 'Opens the customer form in a window.',
                    'Account login' => 'Send a login link for the customer account, change the login email or switch it off.',
                    'Tabs below the form' => 'Enquiry history, Quotations, Bookings, Documents, Upload links, Fines, Tolls, Payments, History.',
                ],
                'after' => ['A customer with an open booking and an account is asked in their account for any missing details and documents before the rest of the account opens.'],
                'tips' => ['Search finds customers by name, phone, WhatsApp, email or company.'],
            ],

            'documents' => [
                'title' => 'Customer documents',
                'for' => 'Every customer document in one list, so you can check new uploads without opening each customer.',
                'where' => 'Menu › Leads › Documents (Agents: My customers › Documents).',
                'who' => 'Admin and Agents (agents: their own customers).',
                'steps' => [
                    'Check a new upload' => ['Open "To review".', 'Open the file, compare with the customer\'s details.', 'Approve, reject (with the reason) or ask for a new upload.'],
                ],
                'buttons' => ['Tabs' => 'To review, Waiting for a new upload, Expired, All.'],
                'after' => ['Approving the last required document opens the customer\'s account and tells them.', 'Rejected: the customer is asked to send it again.'],
                'tips' => [
                    'Files are private: they open only for logged-in staff allowed to see that customer.',
                    'On every document upload use "Scan with camera" or Choose file — same private storage either way.',
                ],
            ],

            'bookings' => [
                'title' => 'Bookings',
                'for' => 'Car rentals, chauffeur and limousine rides, yacht charters and trips: from booking to handover, return, payment and deposit.',
                'where' => 'Admin: Menu › Bookings. Agents: My bookings.',
                'who' => 'Admin and Agents make and run bookings (agents: their own). Changing the status by hand, delivery charges, discounts above the limit and overriding availability: Admin. Refunds and deposit deductions: Admin and Accounts.',
                'steps' => [
                    'Make a new booking' => [
                        'Press "New booking" (or "Create booking" from a lead or quote: details are filled in).',
                        'Customer & service: pick the customer or "+" New customer (customer form with documents); choose the service and the car.',
                        'Dates and Rental agreement: pick-up and return; km per day, rental term, source.',
                        'Price: filled in from the rate card; delivery / collection, extras, deposit.',
                        'Check and press Create. A message names the booking (BK-…).',
                    ],
                    'Take a payment' => ['Open the booking and press the red button "Take payment" (or under More). Choose amount, method and account.'],
                    'Hand over the car' => ['Press "Hand over": km, fuel, photos, deposit received, and the signature if a contract is needed.'],
                    'Receive the car back' => ['Press "Receive back": km, fuel, charges (extra km, fuel, damage, cleaning) and what happens to the car next.'],
                    'Settle the deposit' => ['After the return, "Settle deposit": refund in full or keep part with reasons.'],
                    'Give a driver' => ['Pick the driver on the booking (delivery and collection) or drag the job on the Dispatch board.', 'A driver with incomplete documents shows "⚠ documents incomplete": you can still give the job; a warning says what is missing.'],
                ],
                'buttons' => [
                    'Tabs' => 'Today, Just added (last 7 days), Upcoming, With customer, Waiting for payment, Balance due, Deposits to settle, Late returns, Enquiries & quotes, Completed, Cancelled, All.',
                    'Big red button' => 'The next step for this booking (payment, hand over, receive back, settle).',
                    'Documents' => 'Confirmation, invoice, deposit statement, job report, borrow agreement PDFs.',
                    'Message the customer / driver' => 'WhatsApp or message from the booking.',
                ],
                'after' => [
                    'A new car booking for a customer with missing details is saved as "Waiting for payment" with a message saying what is missing to confirm it. Making the booking is never blocked.',
                    'A second click on Create never makes a second booking.',
                    'Drivers get their delivery / collection jobs on their phone automatically.',
                ],
                'tips' => ['If something cannot be saved (car already booked, driver busy), the message says why and the form jumps to that field.'],
            ],

            'calendar' => [
                'title' => 'Calendar',
                'for' => 'Handovers, returns, charters, transfers, trip departures, follow-ups and quotes on one calendar.',
                'where' => 'Menu › Calendar.',
                'who' => 'Everyone; agents see their own work, drivers their own jobs.',
                'steps' => ['Find a free slot' => ['Switch between day, week, month and agenda.', 'Use the filters (type, car, agent).', 'Gaps are free; tap an item to open it.']],
                'buttons' => [],
                'after' => [],
                'tips' => [],
            ],

            'dispatch' => [
                'title' => 'Dispatch board and live drivers',
                'for' => 'One day, one column per driver, plus the jobs that still need a driver. The live map shows drivers on an active job (only if location sharing is on).',
                'where' => 'Menu › Bookings › Dispatch board; Limousine › Live drivers.',
                'who' => 'Admin (dispatch-drivers / track-drivers).',
                'steps' => ['Give a job to a driver' => ['Pick the day.', 'Drag the job from "Needs a driver" (or another driver) onto the driver\'s column.', 'Both drivers are told.']],
                'buttons' => [],
                'after' => ['The booking\'s driver is changed and every booking check runs (busy, not available). Incomplete driver documents only give a warning.'],
                'tips' => ['Charters (captain and crew) are changed on the booking itself.'],
            ],

            'drivers' => [
                'title' => 'Drivers & crew',
                'for' => 'Driver, captain and crew profiles, their documents, jobs, time off and login.',
                'where' => 'Menu › Bookings › Drivers.',
                'who' => 'Admin (manage-drivers). Agents can be given this permission.',
                'steps' => [
                    'Add a driver' => ['Press "New driver", fill in name, phone and email (their login), personal and employment details.', 'Save, then "Send login link": the driver chooses their own password.'],
                    'Check a document the driver sent' => [
                        'Open the driver › Documents tab. At the top: every required document and its state (Missing, Waiting for approval, Approved, Rejected, Expired).',
                        'Open the file (eye icon), check it, then "Approve" or "Reject" (the driver sees your reason).',
                    ],
                    'Upload a document for a driver' => ['Documents tab › "Add document": type, number, issue / expiry dates and the file (or "Scan with camera"). Documents the office adds are approved at once.'],
                    'Replace a document' => ['Use the arrows icon on the row. The old copy is kept as history.'],
                ],
                'buttons' => [
                    'Check column' => 'Waiting for approval / Approved / Rejected.',
                    'Expiry' => 'Valid, expiring soon or expired.',
                    'Tabs' => 'Documents, Jobs, Not available, Emails, Bookings, History.',
                    'Scan with camera' => 'On the profile photo and document uploads: take a photo, or still Choose file.',
                ],
                'after' => [
                    'Drivers can still be given jobs while documents are missing; staff get a warning.',
                    'A driver\'s new copy only replaces the old approved one once you approve it.',
                    'Expiry reminders go out before documents expire.',
                ],
                'tips' => ['Required documents and fields are set in Business settings › Drivers.'],
            ],

            'damage' => [
                'title' => 'Damage reports',
                'for' => 'Damage and issues reported by drivers at handover or collection, with photos.',
                'where' => 'Menu › Bookings › Damage reports.',
                'who' => 'Admin (review-damage).',
                'steps' => ['Review a report' => ['Open it, look at the photos and notes.', '"Review": decide and note what happens. Any charge is added on the booking (return charges), not here.']],
                'buttons' => [],
                'after' => [],
                'tips' => [],
            ],

            'contracts' => [
                'title' => 'Rental contracts',
                'for' => 'Car rental contracts in the reference PDF layout: hirer and driver details, vehicle and charges, OUT/IN, card last 4, terms, document images, and three signatures (hirer, driver, company).',
                'where' => 'Admin: Menu › Contracts. Agents: My contracts. Drivers: My jobs › Sign rental contract.',
                'who' => 'Admin and Agents (agents: their own bookings\' contracts). Drivers sign on their phone.',
                'steps' => [
                    'Make a new contract (without opening a booking)' => [
                        'Press "New contract".',
                        'Fill in the same steps as a car booking: customer (or add a new one), car, dates, rental agreement, price, deposit.',
                        'Contract step: type, company details if needed, deposit card last 4 digits, notes and additional drivers with licence place of issue and dates.',
                        'Press Create: the booking behind it and the contract are made together and the contract opens.',
                    ],
                    'Make a contract for an existing booking' => ['"From a booking" and pick the booking, or on the booking press "Make rental contract".'],
                    'Fill what the PDF needs' => [
                        'Customer: address, passport or Emirates ID with issue and expiry, licence number, place of issue, issue and expiry.',
                        'Car: plate, type/name, colour, model or year.',
                        '"Card for deposit": last 4 digits only (never a full card number).',
                    ],
                    'Get it signed (both)' => [
                        'Hirer: "Hirer signs here" on the tablet, or "Send signing link" (WhatsApp / link).',
                        'Driver: on My jobs for the delivery, press "Sign rental contract".',
                        'The PDF shows both signatures. With "Hand over only with a fully signed contract" on, both are needed before handover.',
                    ],
                    'During the rental' => ['Extend, Replace car, Add driver, Add damage report, Add traffic fine, Invoice the next period (monthly).'],
                    'Close the contract' => ['After the car is returned and the booking is completed, press "Close contract".'],
                    'Enter an old paper contract' => ['"Enter old contract": customer, car, dates, km, amounts and the scan. It is saved as a completed rental and does not hold the car.'],
                ],
                'buttons' => [
                    'Tabs' => 'Open, Drafts / to sign, Due back today, Closed, Company, Monthly, Cancelled, All.',
                    'Fines and tolls' => 'On the contract page: fines and Salik matched to this rental.',
                    'Card for deposit' => 'Stores last 4 digits for the PDF only.',
                    'Make the PDF again' => 'A new PDF version after a change (versions are kept).',
                    'Scan with camera' => 'On attachments, damage photos and fine notice: take a photo, or still Choose file / PDF.',
                    'Add attachment' => 'Files show on the contract screen (Attachments) and on the PDF Document Images page (photos as images; PDFs listed by name).',
                ],
                'after' => ['Each change makes a new PDF version. Monthly contracts invoice each period by themselves.'],
                'tips' => [
                    'The PDF has Driver / Hirer / Vehicle / Charge tables, car structure before & after (OUT/IN), terms in English + Urdu, Arabic conditions, signatures, and document images.',
                    'Terms: Contract settings › English + Urdu. Conditions: Arabic. A signed contract freezes the text as at signing.',
                ],
            ],

            'cars' => [
                'title' => 'Cars',
                'for' => 'Your car fleet: details, photos, prices and rules, documents (registration, insurance), status and website publishing.',
                'where' => 'Menu › Cars & Yachts.',
                'who' => 'Admin (manage-fleet). Status changes from bookings happen by themselves.',
                'steps' => [
                    'Add a car' => ['Press "New car": Overview (make, model, year, plate, type), Photos, Pricing & rules (daily / weekly / monthly rates, deposit, km included), Documents (expiry dates), Website & SEO.', 'Switch "Show on website" on to publish it. Changes show on the website straight away.'],
                    'Take a car out of service' => ['"Change status" › Maintenance or Unavailable. Bookings never overwrite these.'],
                ],
                'buttons' => [
                    'Tabs' => 'All, Available, Rented / Reserved, Needs cleaning / inspection, Maintenance, Documents due, Hidden from website.',
                    'Tabs on the car' => 'Fines, Tolls, Fuel, Bookings, History.',
                    'New contract' => 'Starts a rental contract for this car.',
                ],
                'after' => ['Expired registration or insurance blocks handover (if switched on in Contract settings).'],
                'tips' => ['Car readiness (menu) lists cars to clean, inspect or service.'],
            ],

            'yachts' => [
                'title' => 'Yachts',
                'for' => 'Private yachts: details, photos, hourly / half-day / full-day prices, crew, documents and status.',
                'where' => 'Menu › Cars & Yachts › Yachts.',
                'who' => 'Admin (manage-fleet).',
                'steps' => ['Add a yacht' => ['"New yacht": Overview, Photos, Pricing & rules, Documents, Website & SEO.']],
                'buttons' => ['Tabs' => 'All, Available, Booked, Needs cleaning / inspection, Maintenance, Documents due, Hidden from website.'],
                'after' => [],
                'tips' => [],
            ],

            'trips' => [
                'title' => 'Yacht trips and packages',
                'for' => 'Shared yacht trips (seats per departure) and trips & packages (tours, safaris), with their dates, prices and website pages.',
                'where' => 'Menu › Cars & Yachts › Trips / Packages.',
                'who' => 'Admin (manage-fleet).',
                'steps' => [
                    'Add a trip or package' => ['"New": Basics / Overview, Itinerary, Photos, Pricing & dates, Website & SEO.', 'Add dates in the Dates / Departures tab ("Add several dates").'],
                    'Stop selling a date' => ['"Close for booking" on the date; "Open for booking" to sell it again.'],
                    'Preview' => ['"Preview" shows the page as the website receives it.'],
                ],
                'buttons' => ['Tabs' => 'Active, Paused, No upcoming dates, Hidden from website, All.'],
                'after' => [],
                'tips' => [],
            ],

            'suppliers' => [
                'title' => 'Suppliers',
                'for' => 'Car owners and rental partners: their cost rates, bills and payments, and the balance you owe.',
                'where' => 'Menu › Cars & Yachts › Suppliers (Accounts: Suppliers).',
                'who' => 'Admin and Accounts.',
                'steps' => [
                    'Add a supplier and its rate' => ['"New supplier", then the Cost rates tab: per day / per booking rates.'],
                    'Record a bill or payment' => ['On the supplier: "Add bill" or "Record payment". "Statement" shows the balance.'],
                ],
                'buttons' => [],
                'after' => ['A booking with a supplier car takes its cost from the rate; missing rate = the booking cannot be confirmed until set.'],
                'tips' => [],
            ],

            'limousine' => [
                'title' => 'Limousine & transfers',
                'for' => 'Airport pickups, point-to-point and hourly chauffeur services, priced by vehicle category and real road distance.',
                'where' => 'Menu › Limousine (services, vehicle categories, transfer rates, fixed routes).',
                'who' => 'Admin.',
                'steps' => [
                    'Set up prices' => ['Services: the kinds of ride you offer.', 'Vehicle categories: what the customer books (sedan, SUV, van).', 'Transfer rates: base, per km, per hour, minimums per category.', 'Fixed routes: a set price for a known route, used instead of the distance price.'],
                    'Book a ride' => ['New booking › service Limousine, or "Quick limousine price" from a lead.'],
                ],
                'buttons' => ['Load starter limousine list' => 'Adds a starter list of services you can edit.'],
                'after' => [],
                'tips' => ['Live drivers shows chauffeurs on a job when location sharing is on.'],
            ],

            'pricing' => [
                'title' => 'Prices',
                'for' => 'Standard rates, special rates (seasons, weekends, dates), extras and fees, and a price check.',
                'where' => 'Settings / Pricing: Rate card, Special rates, Extras & fees, Price check.',
                'who' => 'Admin (manage-pricing).',
                'steps' => [
                    'Change a standard rate' => ['Rate card: edit the rate in place (it is the same field as on the car).'],
                    'Add a season or special date' => ['Special rates › New: dates, cars / categories, the change (amount or %).'],
                    'Add an extra or fee' => ['Extras & fees › New: delivery, collection, child seat, always-added fees.'],
                    'Check a price' => ['Price check: pick the car, dates and extras; see the full breakdown. Nothing is saved.'],
                ],
                'buttons' => [],
                'after' => ['New prices are used for new quotes and bookings; saved ones keep their price.'],
                'tips' => [],
            ],

            'payments' => [
                'title' => 'Payments (money in and out)',
                'for' => 'All money received and paid: payments, refunds, expenses, the balance each customer still owes, and deposits (kept apart: not income).',
                'where' => 'Admin: Menu › Money › Transactions. Accounts: Menu › Payments.',
                'who' => 'Recording payments: Admin, Agents, Accounts. Editing, refunds, approving expenses: Admin and Accounts.',
                'steps' => [
                    'Record a payment' => ['Best from the booking ("Take payment"), so it is linked. Or "Record income" here.'],
                    'Record an expense' => ['"Record expense": category, amount, account, receipt ("Read the receipt" fills the amount and date). Agents\' expenses wait for approval.'],
                    'Collect a balance' => ['Tab "To collect": the unpaid balance of each booking. "Mark as paid" turns it into a payment.'],
                    'Approve an expense' => ['Tab "Waiting for approval": Approve or Reject.'],
                ],
                'buttons' => ['Tabs' => 'All, Income, Expenses, To collect, Waiting for approval, Estimates, This month.', 'Reverse' => 'Cancels a wrong entry with a matching reverse entry (kept for the record).'],
                'after' => ['The booking\'s paid amount and balance update straight away.'],
                'tips' => ['Bank and cash accounts are set in Settings › Bank, cash and marketplaces.'],
            ],

            'invoices' => [
                'title' => 'Invoices',
                'for' => 'Invoices and credit notes issued from bookings and monthly contracts.',
                'where' => 'Menu › Money › Invoices (Accounts: Payments › Invoices).',
                'who' => 'Admin and Accounts.',
                'steps' => ['Issue an invoice' => ['From the booking: "Issue invoice". Monthly contracts issue their own.'], 'Correct an invoice' => ['Issued invoices are never edited: void it (with the reason) or make a credit note.']],
                'buttons' => ['PDF' => 'Opens the invoice PDF.'],
                'after' => [],
                'tips' => [],
            ],

            'online-payments' => [
                'title' => 'Online payments',
                'for' => 'Every payment link sent to customers (card, Tabby, Tamara…), its status and refunds.',
                'where' => 'Menu › Money › Online payments.',
                'who' => 'Admin and Accounts.',
                'steps' => ['Send a payment link' => ['From the booking or quote: "Payment link". The customer pays on the secure page.'], 'Refund' => ['Open the payment › "Refund".']],
                'buttons' => ['Cancel link' => 'Stops an unpaid link.'],
                'after' => ['A paid link records the payment on the booking by itself.'],
                'tips' => [],
            ],

            'fines' => [
                'title' => 'Traffic fines, Salik & tolls',
                'for' => 'Traffic fines and Salik / Darb / parking: matched to the car and the booking that had it, charged to the customer or kept as a company cost, paid to the authority.',
                'where' => 'Admin: Menu › Money › Traffic fines / Salik / Toll accounts / Import statement. Accounts: Fines & Salik.',
                'who' => 'Admin and Accounts.',
                'steps' => [
                    'Add a fine' => ['"New fine" (or on a contract "Add traffic fine"): authority, ticket, date and time, car, amount, notice.', 'It is matched to the booking that had the car at that time (car swaps included).'],
                    'Import a statement' => ['"Import statement": upload the Excel / CSV from the authority or Salik, map the columns once, check the counts, import. Duplicates are skipped.'],
                    'Charge the customer' => ['Tab "To charge": "Charge to customer" (adds it with the admin fee to the booking) or select several.'],
                    'Pay the authority' => ['"Paid to authority" when you have paid it.'],
                    'Not the customer\'s' => ['"Disputed / waived / company cost" with a reason.'],
                    'Toll accounts' => ['Top up the Salik / Darb balance; a low balance gives an alert.'],
                ],
                'buttons' => [
                    'Fine tabs' => 'To charge, Charged not paid by the customer, Not paid to authority, Paid to authority, Disputed / waived / company, Needs review, All.',
                    'Salik tabs' => 'Matched not on the booking yet, Charged, Company cost, Needs review, All.',
                    'Match again' => 'Retries matching after a car or booking was corrected.',
                ],
                'after' => ['Fines and tolls also show on the customer, the car, the contract and in Rental reports › Fines & tolls.'],
                'tips' => [],
            ],

            'fuel' => [
                'title' => 'Fuel',
                'for' => 'Fill-ups sent by drivers (claims) and added by the office; confirmed ones become a fuel cost on the car and booking.',
                'where' => 'Menu › Money › Fuel (Accounts: Fuel).',
                'who' => 'Admin and Accounts confirm. Drivers add their own on the phone.',
                'steps' => ['Confirm a fill-up' => ['Tab "Waiting": check the receipt, "Confirm" (or "Reject" with a reason).'], 'Set fuel prices' => ['"Fuel prices" for the month.'], 'Monthly report' => ['Per car: litres, spend, km per litre, fuel charged to customers.']],
                'buttons' => ['Tabs' => 'Waiting, Confirmed, Rejected, All.'],
                'after' => ['The driver sees whether it was confirmed, never other money.'],
                'tips' => [],
            ],

            'reports' => [
                'title' => 'Reports',
                'for' => 'Revenue, expenses and profit; rentals out and in, car use, unpaid, VAT by emirate, cash and bank, fines & tolls; discounts given; leads and conversion.',
                'where' => 'Admin: Menu › Money (Accounts overview, Profit & reports, Rental reports, Discounts). Accounts: Reports.',
                'who' => 'Admin and Accounts.',
                'steps' => ['Read a report' => ['Pick the dates (from / until).', 'Rental reports: choose the tab (Out & in, Car use, Unpaid, VAT by emirate, Cash & bank, Fines & tolls per car / per customer).', 'Numbers open their lists where possible; "Download CSV" where offered.']],
                'buttons' => [],
                'after' => [],
                'tips' => ['Each figure\'s definition is on the Profit page.'],
            ],

            'audit' => [
                'title' => 'Audit log',
                'for' => 'Who did what, where and when across the CRM: sign-ins, records created, changed and deleted, status changes, settings, money, downloads of documents.',
                'where' => 'Settings › Audit log (or Menu › More).',
                'who' => 'Admin only. Lines cannot be changed or deleted by anyone in the CRM.',
                'steps' => [
                    'See what an agent did' => ['Filter "Who (agent / staff)" and pick the person; add a date ("Today", "Last 7 days").'],
                    'See what happened to a record' => ['Search the record number (for example the booking number) or filter by Module.'],
                    'See the details of a change' => ['Press "Open" on the line: every field before → after, IP and device, and other lines from the same click.'],
                ],
                'buttons' => [
                    'Tabs' => 'All, Changes, Deletions, Sign-ins, Money, Settings & roles, Refused / failed.',
                    'Filters' => 'Who, Module, Action, Date, Role, Result, Record type, Record number.',
                    'Summary at the top' => 'Actions today and the busiest people, sign-ins, deletions and failed sign-ins in 7 days.',
                    'Export' => 'Download the list (logged).',
                ],
                'after' => [],
                'tips' => ['A record\'s own History tab shows the same lines for just that record.'],
            ],

            'messages' => [
                'title' => 'Messages',
                'for' => 'Conversations with customers and drivers (WhatsApp, Instagram, Messenger and CRM messages) in one inbox.',
                'where' => 'Menu › Messages.',
                'who' => 'Admin and Agents (agents: their own customers). Drivers see only their own messages.',
                'steps' => ['Reply' => ['Open the conversation, type and send.', 'Open the lead or customer from the conversation to quote or book.']],
                'buttons' => [],
                'after' => ['Auto replies (Settings › Chat auto replies) answer first messages outside working hours.'],
                'tips' => [],
            ],

            'agents' => [
                'title' => 'Agents',
                'for' => 'What each agent did this month: leads accepted, quotes, bookings and money in.',
                'where' => 'Menu › Agents.',
                'who' => 'Admin.',
                'steps' => ['Check an agent' => ['Pick the month; tap a number to open that list.']],
                'buttons' => [],
                'after' => [],
                'tips' => ['The Audit log shows each action of an agent in detail.'],
            ],

            'team' => [
                'title' => 'Team (users and roles)',
                'for' => 'Staff logins and their role: Admin, Agent, Accounts, Driver.',
                'where' => 'Settings › Team.',
                'who' => 'Admin (manage-team).',
                'steps' => ['Add a team member' => ['"New user": name, email, role. Send them a login link; nobody types a password for someone else.'], 'Change what a role may do' => ['Business settings › Roles & permissions.']],
                'buttons' => [],
                'after' => [],
                'tips' => ['Switching a login off keeps their history.'],
            ],

            'settings' => [
                'title' => 'Settings',
                'for' => 'Everything the business decides for itself, grouped on one page.',
                'where' => 'Menu › Settings.',
                'who' => 'Admin (each settings screen shows only to people allowed to open it).',
                'steps' => [
                    'Find a setting' => ['Open Settings: each card is a group (Everyday, Prices, Team, Contracts, Money, Follow-ups, Chat replies, Integrations, Apps, Advanced).', 'Change it and press Save. Every change is recorded in the Audit log.'],
                    'Business settings (Advanced)' => ['Company & messages, Bookings & returns, VAT & quotations, Documents (types and which are required), Drivers (required fields and documents), Lists, Money categories, Roles & permissions.'],
                    'Contract settings' => ['Delivery charges, no-deposit fee, details needed before confirming, minimum age and licence years, contract terms, signing, monthly invoices.'],
                    'Integrations' => ['Set up an outside service (payments, maps, social, document reader): enter its keys once, "Test connection".'],
                    'Customer accounts' => ['Business settings › Customer accounts: what customers see, and "Documents first".'],
                ],
                'buttons' => [],
                'after' => ['Settings take effect at once for new work; nothing already saved is changed.'],
                'tips' => ['Amounts and rules not decided yet stay empty: nothing is charged or checked until you set them.'],
            ],

            'email' => [
                'title' => 'Email and announcements',
                'for' => 'Mailboxes the CRM sends from, email templates, the log of every email sent, and announcements to customers who agreed.',
                'where' => 'Settings › Email accounts / Email templates / Email log / Announcements.',
                'who' => 'Admin (manage-email).',
                'steps' => ['Send an announcement' => ['Announcements: choose the group and template; only customers who agreed get it, always with an unsubscribe link.'], 'Resend a failed email' => ['Email log: open the email, "Send again".']],
                'buttons' => ['Send test email' => 'Checks a mailbox works.', 'Routing' => 'Which mailbox sends which kind of email.'],
                'after' => [],
                'tips' => [],
            ],

            'website' => [
                'title' => 'Website content',
                'for' => 'What the website shows that is not a car or trip: offers, reviews, blog posts, website sections, shared forms and lead sources.',
                'where' => 'Settings › Website content / Website sections / Blog posts / Shared forms / Lead sources.',
                'who' => 'Admin.',
                'steps' => [
                    'Add an offer or review' => ['Website content: real offers and real reviews only.'],
                    'Hide a whole section' => ['Website sections: switch off Cars, Yachts or Yacht Trips; nothing is deleted.'],
                    'Make a shared form' => ['Shared forms › New link: share it; answers arrive as leads.'],
                ],
                'buttons' => [],
                'after' => ['Changes show on the website the next time the page is opened.'],
                'tips' => ['Cars, yachts and trips are published from their own page ("Show on website").'],
            ],

            'driver' => [
                'title' => 'Driver screens',
                'for' => 'Your jobs, schedule, fuel, profile and documents on your phone.',
                'where' => 'Menu: My jobs, Fuel, Messages, My profile.',
                'who' => 'Drivers, captains and crew. You see only your own jobs and never money amounts.',
                'steps' => [
                    'Do a job' => ['My jobs › open the job.', 'Accept it, then "On the way", "Arrived".', 'Hand over or collect: follow the checks (km, fuel, photos, signature). Use "Scan with camera" for photos when you can. Report damage if needed.'],
                    'Send a document' => ['My profile › "Send a document": choose the document, number and expiry, then "Scan with camera" or Choose file, "Send to the office".', 'It shows "Waiting for approval" until the office checks it. If rejected, the reason is shown: send it again.'],
                    'Add a fill-up' => ['Fuel › add km, litres, amount and the receipt photo. The office confirms it.'],
                    'Say when you cannot work' => ['My schedule › add the time off.'],
                ],
                'buttons' => [
                    'Language' => 'English, Urdu or Arabic (My profile).',
                    'Scan with camera' => 'On documents and job photos: opens the phone camera; Choose file still works if camera is blocked.',
                ],
                'after' => ['The office is told about every document and fill-up you send.'],
                'tips' => [],
            ],

            'notifications' => [
                'title' => 'My notifications',
                'for' => 'Choose what reaches you (new leads, jobs, payments…) and how (in the CRM, email, phone app).',
                'where' => 'Bell › My notifications, or My profile.',
                'who' => 'Everyone, for themselves.',
                'steps' => ['Change a notification' => ['Switch each kind on or off per channel and save.']],
                'buttons' => [],
                'after' => [],
                'tips' => [],
            ],

            'help' => [
                'title' => 'Help centre',
                'for' => 'Every help guide of the CRM in one place.',
                'where' => 'The "?" on any page › "All help guides".',
                'who' => 'Everyone.',
                'steps' => ['Find help' => ['Type a word in Search (for example "deposit" or "fine").', 'Open a guide to read it.']],
                'buttons' => [],
                'after' => [],
                'tips' => [],
            ],
        ];
    }

    /** Customer account (/account) route => the "how to" that opens first. */
    public const PORTAL_FOCUS = [
        'pages.home' => 'Complete your account',
        'pages.bookings' => 'See a booking',
        'pages.booking' => 'See a booking',
        'pages.quotations' => 'Accept a quotation',
        'pages.payments' => 'Pay',
        'pages.documents' => 'Complete your account',
        'pages.messages' => 'Send us a message',
        'pages.notifications' => 'Your notifications',
        'auth.profile' => 'Change your details or password',
    ];

    /** Customer account (/account) help. */
    public static function portal(): array
    {
        return [
            'title' => 'Your account',
            'for' => 'Your bookings, quotations, contracts, documents, payments and messages with RED PRIME RENTALS, in one place.',
            'where' => 'The menu at the top: Home, Bookings, Quotations, Payments, Documents, Messages.',
            'who' => 'Only you. You see only your own bookings, quotations, payments and documents.',
            'steps' => [
                'Complete your account' => [
                    'If the page asks for details, fill in what is missing (for example date of birth, nationality, licence number) and press Save.',
                    'Documents: upload a clear photo or PDF of each document asked for (Emirates ID or passport, driving licence…), front and back if asked. You can press "Scan with camera" or Choose file.',
                    'Each document shows its state: Waiting for a check, Approved, or Please send again (with the reason).',
                    'Our team checks them; your bookings and payments open when they are approved. You get a message when that happens.',
                ],
                'See a booking' => [
                    'Bookings: your upcoming and past bookings.',
                    'Open one to see the dates, the car or yacht, what is paid and what is still due.',
                    'From the booking: open the confirmation and contract PDF, sign the contract from the link, and see the deposit statement after the return.',
                ],
                'Accept a quotation' => [
                    'Quotations: every offer we sent you.',
                    'Open it, check the price and dates, then press Accept or Decline.',
                    'After you accept, we confirm the booking and may ask for documents or a payment.',
                ],
                'Pay' => [
                    'Payments: what you paid and what is still due.',
                    'A payment link we send you opens a secure payment page. The payment shows here once it is received.',
                ],
                'Send us a message' => ['Messages: type your message and send. A person from our team answers here and you get a notification.'],
                'Your notifications' => ['The bell at the top shows our messages, confirmations and reminders. Tap one to open it.'],
                'Change your details or password' => ['Your name at the top right › Profile: change your details or your password and save.'],
            ],
            'buttons' => [
                'Bell' => 'Notifications from our team.',
                'Your name (top right)' => 'Profile and sign out.',
            ],
            'after' => ['Every document and message you send reaches our team straight away.'],
            'tips' => ['Your documents are private: only our team can open them.', 'Questions? Send us a message or WhatsApp us.'],
        ];
    }
}
