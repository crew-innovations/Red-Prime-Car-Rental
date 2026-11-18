<?php

/*
 * 6 Nov ("Make it simple", S4 / C.2): the "?" on each page (three short lines) and the text of
 * empty lists. Keyed by the page's route name without "filament.admin.".
 */
return [
    'pages' => [
        'pages.dashboard' => ['What needs you today.', 'Numbers in red need action now; tap one to open the list.', 'Use the buttons at the top to add a lead, quote or booking.'],
        'resources.leads.index' => ['New enquiries go to every agent: the first to accept owns the customer.', '"Waiting for an agent" shows leads nobody has taken yet.', 'Open a lead to call, WhatsApp, quote or book.'],
        'resources.leads.create' => ['Name, phone and what they want are enough.', 'Turn on "More options" for WhatsApp, email and budget.', 'You become the owner of a lead you add.'],
        'resources.leads.edit' => ['The big red button is the next step for this lead.', 'The bar under the title shows how far the sale has come.', 'Lost needs a reason; won creates the customer for you.'],
        'resources.quotations.index' => ['Quotes you made, and their status.', 'A sent quote is locked: make a new version to change it.', 'The customer can accept from the link in the email.'],
        'resources.quotations.create' => ['Four steps: customer, dates, price, check.', 'The price is worked out from the rate card; add a discount if allowed.', '"Save and send" opens the send window straight away.'],
        'resources.quotations.edit' => ['The big red button is the next step for this quote.', 'Accepted quotes become a booking with one tap.', 'Everything else is under "More".'],
        'resources.customers.index' => ['Customers and their history.', 'Agents see only their own customers.', '"Documents to review" lists passports and licences waiting for a check.'],
        'resources.bookings.index' => ['"Today" shows cars going out and coming back today.', 'Late returns appear as their own tab while there are any.', 'Other views (cancelled, completed…) are under "More views".'],
        'resources.bookings.create' => ['Four steps: customer and car, dates, price, check.', 'The price fills in from the rate card.', 'You can jump between steps; saving checks everything.'],
        'resources.bookings.edit' => ['The big red button is the next step: payment, hand over, receive back or settle the deposit.', 'The bar under the title shows where the booking stands.', 'Documents and other actions are under "More" and "Documents".'],
        'pages.calendar' => ['Every car, yacht and trip on one calendar.', 'Tap a booking to open it.', 'Gaps are free to book.'],
        'resources.cars.index' => ['Your fleet and its status.', '"Car readiness" lists cars that need cleaning, documents or service.', 'Status follows the bookings by itself; Maintenance stays until you change it.'],
        'resources.fuel.index' => ['Fill-ups from drivers wait here for Accounts.', 'Confirm adds the fuel cost to the car (and its booking). Reject needs a reason.', 'Set this month\'s fuel prices with "Fuel prices".'],
        'resources.transactions.index' => ['All money in and out.', '"To collect" is what customers still owe.', 'Security deposits are kept apart: they are not income.'],
        'pages.settings' => ['All settings in a few groups.', 'Prices and Team are the ones used most.', '"Advanced" holds every business rule: change with care.'],
        'pages.agents' => ['How each agent is doing this month.', 'Leads accepted, quotes, bookings and money in.', 'Admin can move a customer to another agent from the lead.'],
        'pages.pipeline-board' => ['Leads by stage. Drag a card to move it.', 'Won creates the customer; Lost needs a reason.', 'Each card opens the lead.'],
        'pages.dispatch' => ['Today\'s deliveries and collections by driver.', 'Drag a job to give it to another driver.', 'Red jobs have no driver yet.'],
    ],

    'empty' => [
        'leads' => 'No leads here. New enquiries appear here and on your phone.',
        'bookings' => 'No bookings in this view. Try "All", or another tab.',
        'customers' => 'No customers here yet. A won lead becomes a customer by itself.',
        'quotations' => 'No quotes here. Make one from a lead with "Create quotation".',
        'transactions' => 'Nothing here. Payments and expenses appear as they are recorded.',
        'default' => 'Nothing here yet. Try another tab, or clear the filters.',
    ],
];
