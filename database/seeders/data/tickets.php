<?php

/*
 * Sample tickets for TicketSeeder: what customers wrote and how the team answered.
 *
 * customer  index into UserSeeder::CUSTOMERS
 * flow      where the ticket ends up (TicketSeeder::FLOWS):
 *           new, stale (unassigned + breached), assigned, working, waiting, approaching, breached,
 *           resolved, late (resolved after the deadline), closed, reopened
 * ago       roughly how many hours ago the customer wrote (the seeder adjusts it to fit the flow)
 * a1/c1/a2  first agent reply, the customer's answer, the agent's closing reply
 * note      internal note (staff only)        c2  customer reopening a resolved ticket
 * raise     admin raises the priority to this after triage
 * agent     who handles it (index into UserSeeder::AGENTS); otherwise a specialist is picked
 * reassign  admin moves the ticket to this agent (index) after the first agent went on leave
 */

return [
    // ---------------------------------------------------------------- Billing
    [
        'customer' => 0, 'category' => 'billing', 'priority' => 'critical', 'flow' => 'closed', 'ago' => 610,
        'subject' => 'Charged twice for the October subscription',
        'description' => "Hi,\n\nOur company card was charged twice on 1 October for the Business plan (2 x BDT 4,500). The two transactions have the same reference on our bank statement. Please refund the duplicate charge.\n\nThanks,\nMahmudul",
        'a1' => 'Hi Mahmudul, thank you for flagging this and sorry for the trouble. I can see two successful payments on 1 October: the first renewal attempt timed out on our side and the retry went through as well. I have started a refund of BDT 4,500 for the second payment.',
        'c1' => 'Thanks for the quick response. How long does the refund usually take to show up?',
        'a2' => "Refunds normally reach the card within 5 to 7 working days, depending on your bank. The refund reference is RF-20481. I'll mark this as resolved, but just reply here if it hasn't arrived by next week.",
        'note' => 'Payment gateway retried after a timeout. Logged with the payments team as part of the duplicate-charge issue.',
    ],
    [
        'customer' => 3, 'category' => 'billing', 'priority' => 'medium', 'flow' => 'closed', 'ago' => 470,
        'subject' => 'Need VAT number added to our invoices',
        'description' => "Hello,\n\nOur accountant needs our VAT registration number (BIN 004512398-0101) printed on the invoices we receive from you. Can you add it to our account and re-issue the invoices for August and September?\n\nRegards,\nPriya Sharma\nBluePeak Consulting",
        'a1' => "Hi Priya, I've added BIN 004512398-0101 to your billing profile. From now on it will appear on every invoice. I've also re-issued the August and September invoices; you can download them from Settings > Billing > Invoice history.",
        'c1' => 'Perfect, the new invoices show the number. Thank you!',
        'a2' => 'Glad that worked. Closing this one now. Have a great day!',
    ],
    [
        'customer' => 6, 'category' => 'billing', 'priority' => 'high', 'flow' => 'late', 'ago' => 70,
        'subject' => 'Card declined when upgrading to the Business plan',
        'description' => "Every time I try to upgrade from Starter to Business, the payment page says \"Card declined\". The card works everywhere else and our bank says they don't see any declined attempt. We need the Business plan before Sunday because we're adding three more staff.\n\nOmar Faruk\nFaruk Logistics",
        'a1' => "Hi Omar, I checked our payment logs: the attempts are stopped by the gateway's 3-D Secure check before they reach your bank, which is why the bank sees nothing. Could you try once more and keep the bank's OTP page open until it confirms? If it fails again, please tell me the time of the attempt.",
        'c1' => 'Tried again at 3:40 PM, same error. No OTP page appeared at all.',
        'a2' => "Thanks, that helped. The gateway had your card's country set incorrectly, so it skipped the OTP step. That's fixed, and I've applied the Business plan to your account manually from today, with the payment link sent by email. Sorry it took longer than it should have.",
        'note' => 'Gateway misclassified the BIN as foreign. Escalated to payments, workaround: manual upgrade plus payment link.',
    ],
    [
        'customer' => 12, 'category' => 'billing', 'priority' => 'low', 'flow' => 'waiting', 'ago' => 20,
        'subject' => 'Can we switch to annual billing?',
        'description' => "Hi team,\n\nWe've been on monthly billing for 8 months and would like to move to the annual plan to get the discount. Is the discount applied immediately and what happens to the current month we already paid?\n\nKamal Uddin\nUddin Pharma",
        'a1' => 'Hi Kamal, yes: the annual plan is 2 months cheaper than paying monthly. If you switch now, we credit the unused part of this month against the annual price. Could you confirm which plan you want (Business or Business Plus) and whether the invoice should go to the same billing email?',
    ],
    [
        'customer' => 9, 'category' => 'billing', 'priority' => 'medium', 'flow' => 'resolved', 'ago' => 30,
        'subject' => 'Invoice shows the wrong company address',
        'description' => "Our latest invoice still shows our old address (House 12, Road 5, Dhanmondi). We moved in September; the new address is already saved in our profile. Can you correct the invoice?\n\nMehnaz",
        'a1' => "Hi Mehnaz, the invoice was generated from the address saved at the start of the billing period, which was before you updated it. I've regenerated the November invoice with your new address in Gulshan. Future invoices will use the new address automatically.",
        'c1' => 'Got it, the new PDF is correct. Thanks.',
        'a2' => "You're welcome! I'll close this ticket. Feel free to write again any time.",
    ],
    [
        'customer' => 1, 'category' => 'billing', 'priority' => 'high', 'flow' => 'approaching', 'ago' => 8,
        'subject' => 'Refund for unused seats after downsizing',
        'description' => "Hi,\n\nWe reduced our team from 12 seats to 7 last week but were billed for 12 again this month. Could you refund the 5 unused seats and make sure next month is billed for 7?\n\nEmily Carter\nNorthwind Studio",
        'a1' => "Hi Emily, thanks for writing in. I can see the seat change was saved on the 3rd, one day after the renewal ran, so this month was still billed for 12. I'm calculating the pro-rated refund for the 5 seats now and will confirm the amount shortly.",
        'note' => 'Pro-rated refund for 5 seats: needs finance approval because it is over BDT 10,000.',
    ],

    // ------------------------------------------------------- Account & Login
    [
        'customer' => 4, 'category' => 'account-login', 'priority' => 'high', 'flow' => 'closed', 'ago' => 520,
        'subject' => 'Locked out after enabling two-factor authentication',
        'description' => "I turned on two-factor authentication yesterday and then my phone was reset. Now I can't log in because I don't have the authenticator codes anymore. I'm the account owner, so nobody else can approve invoices this week.\n\nJonathan Lee",
        'a1' => "Hi Jonathan, I'm sorry about that. For security I need to verify the request first: please reply from your account email with the last 4 digits of the card on file and the date of your last invoice.",
        'c1' => 'Card ending 4417, last invoice was 1 October.',
        'a2' => "Thank you, that matches. I've reset two-factor authentication on your account, so you can log in with your password and set up a new authenticator. Please save the recovery codes this time, they let you get in without your phone.",
        'note' => 'Identity verified with card digits and last invoice date, per the account-recovery checklist.',
    ],
    [
        'customer' => 7, 'category' => 'account-login', 'priority' => 'medium', 'flow' => 'closed', 'ago' => 340,
        'subject' => 'Password reset email never arrives',
        'description' => "I've requested a password reset three times today but nothing arrives, not even in spam. My email is laura.bennett@oakridge-dental.test.\n\nLaura",
        'a1' => "Hi Laura, our mail logs show the reset emails were rejected by your clinic's mail server, which blocks messages with links from new senders. Could you ask your IT provider to allow emails from our domain? In the meantime, I've sent a one-time login link to your personal address on file.",
        'c1' => 'Our IT person added you to the safe list and the reset email came through. All sorted.',
        'a2' => 'Great news, thanks for letting me know. Closing this one.',
    ],
    [
        'customer' => 10, 'category' => 'account-login', 'priority' => 'medium', 'flow' => 'working', 'ago' => 6,
        'subject' => 'Transfer account ownership to a new manager',
        'description' => "Our office manager Hasan is leaving at the end of the month. Please transfer account ownership from hasan@kimarchitects.test to me (david.kim@kimarchitects.test). I'm already an admin on the account.\n\nDavid Kim",
        'a1' => "Hi David, I can help with that. Ownership transfers need confirmation from the current owner, so I've sent Hasan an email with a confirmation link. As soon as he clicks it, the account moves to you.",
    ],
    [
        'customer' => 13, 'category' => 'account-login', 'priority' => 'low', 'flow' => 'new', 'ago' => 3,
        'subject' => 'How do I add a teacher as a read-only user?',
        'description' => "Hi, we'd like our head teacher to see the fee reports but not be able to edit anything. Is there a read-only role, and does it count as a paid seat?\n\nAyesha Siddika\nBrightMinds School",
    ],
    [
        'customer' => 2, 'category' => 'account-login', 'priority' => 'high', 'flow' => 'resolved', 'ago' => 50, 'raise' => 'critical',
        'subject' => 'Suspicious login from another country',
        'description' => "We received a \"new sign-in\" email for our account from Vietnam at 2:14 AM. None of our staff were travelling. Please check what happened and secure our account.\n\nRashed Chowdhury",
        'a1' => "Hi Rashed, thanks for reporting this quickly. I've signed out all active sessions on your account and forced a password reset for the user who signed in. The sign-in used the correct password, so the password was probably reused on another site. Please ask that user to choose a new, unique password.",
        'c1' => 'Done. Can we make two-factor authentication mandatory for everyone?',
        'a2' => "Yes. Go to Settings > Security and turn on \"Require two-factor authentication\". Everyone will be asked to set it up the next time they sign in. I'll mark this resolved, but let us know if you see anything else unusual.",
        'note' => 'Login IP is on a known credential-stuffing list. No data export happened during the session.',
    ],
    [
        'customer' => 5, 'category' => 'account-login', 'priority' => 'medium', 'flow' => 'stale', 'ago' => 75,
        'subject' => "Can't log in on the mobile app",
        'description' => "The website works fine but the Android app says \"Session expired\" right after I enter my password. I've reinstalled the app twice.\n\nTasnim Akter\nDhaka Fresh Mart",
    ],

    // ------------------------------------------------------ Technical Support
    [
        'customer' => 8, 'category' => 'technical-support', 'priority' => 'critical', 'flow' => 'breached', 'ago' => 40,
        'subject' => 'Sales sync from our POS stopped completely',
        'description' => "Since yesterday afternoon, no sales from our three shops are coming into the dashboard. The POS shows \"sync pending\" for about 600 transactions. We do our daily stock order from these numbers, so this is urgent.\n\nImran Siddiqui\nSiddiqui Electronics",
        'a1' => 'Hi Imran, I understand how urgent this is. I can see the sync requests from your POS failing with an expired API token since 3:52 PM yesterday. Could you open the POS integration settings and click "Reconnect"? That issues a new token.',
        'c1' => 'Reconnected, but only shop 1 started syncing. Shops 2 and 3 still show pending.',
        'note' => 'Shops 2 and 3 run an old POS build that does not refresh tokens. Asked the integrations team for a manual token.',
    ],
    [
        'customer' => 11, 'category' => 'technical-support', 'priority' => 'high', 'flow' => 'closed', 'ago' => 400,
        'subject' => 'CSV export of invoices times out',
        'description' => "When I export all invoices for the year to CSV, the page loads for a long time and then shows \"Something went wrong\". Exporting a single month works.\n\nSumaiya\nPetal & Pot",
        'a1' => 'Hi Sumaiya, thanks for the details. A full-year export is processed in the background, and that job was timing out for accounts with more than 5,000 invoices. Our team has raised the limit. Could you try the export again?',
        'c1' => 'It worked this time, I got the file by email after about a minute.',
        'a2' => "Excellent. Large exports are emailed rather than downloaded directly, so that's expected. Closing this ticket. Thanks for your patience!",
    ],
    [
        'customer' => 0, 'category' => 'technical-support', 'priority' => 'medium', 'flow' => 'closed', 'ago' => 280,
        'subject' => 'Connecting our domain email for sending invoices',
        'description' => "We want invoices to be sent from accounts@greenleaf-traders.test instead of your default address. What DNS records do we need to add?\n\nMahmudul",
        'a1' => "Hi Mahmudul, you'll need to add three records at your DNS provider: one TXT record for SPF and two CNAME records for DKIM. You can copy the exact values from Settings > Email > Custom sender. After adding them, click \"Verify\" (DNS can take up to an hour).",
        'c1' => 'Added the records and it says verified now.',
        'a2' => 'Perfect, invoices will now go out from accounts@greenleaf-traders.test. Closing this one.',
    ],
    [
        'customer' => 6, 'category' => 'technical-support', 'priority' => 'medium', 'flow' => 'waiting', 'ago' => 26,
        'subject' => 'Barcode scanner not detected in the browser',
        'description' => "We bought a USB barcode scanner (Zebra DS2208) for the warehouse. It types into Notepad fine, but nothing happens on the stock-in screen.\n\nOmar",
        'a1' => "Hi Omar, the stock-in screen only listens for scans when the \"Scan item\" box is focused. Could you click into that box first and try again? If it still doesn't work, please tell me which browser and version you're using and send a short screen recording.",
    ],
    [
        'customer' => 3, 'category' => 'technical-support', 'priority' => 'low', 'flow' => 'assigned', 'ago' => 18,
        'subject' => 'Dashboard numbers look different from the report',
        'description' => "The dashboard says our October revenue is BDT 18.2 lakh, but the monthly report says BDT 17.6 lakh. Which one is right?\n\nPriya",
    ],
    [
        'customer' => 9, 'category' => 'technical-support', 'priority' => 'medium', 'flow' => 'reopened', 'ago' => 60,
        'subject' => 'Product images not loading in the online catalogue',
        'description' => "Since this morning, our online catalogue shows grey boxes instead of product photos. Customers are messaging us about it.\n\nMehnaz Rahman\nCraftNest",
        'a1' => 'Hi Mehnaz, sorry about this. One of our image servers had a configuration problem this morning that affected some catalogues. It has been fixed, and the images should load again after a refresh.',
        'c1' => 'Yes, they are back. Thanks!',
        'a2' => "Great, thanks for confirming. I'll mark this resolved.",
        'c2' => 'The photos are gone again since about 11 AM, at least on mobile. Desktop looks fine.',
        'note' => 'Mobile uses the resized-image endpoint, which still points to the old server. Checking with infrastructure.',
    ],
    [
        'customer' => 12, 'category' => 'technical-support', 'priority' => 'medium', 'flow' => 'working', 'ago' => 10,
        'subject' => 'Printing delivery challans cuts off the last column',
        'description' => "When we print delivery challans on A4, the \"Amount\" column is cut off on the right side. Print preview in Chrome shows the same.\n\nKamal Uddin",
        'a1' => "Hi Kamal, thanks for the screenshot. That happens when the printer margin is set to \"Default\" in Chrome. Could you set Margins to \"Minimum\" in the print dialog and try again? I'm also checking whether we can make the template fit default margins.",
        'c1' => 'Minimum margins work. It would be good if the template fit by default though, my staff always forget.',
        'note' => 'Raised a template fix with the documents team: reduce the column padding on A4.',
    ],
    [
        'customer' => 1, 'category' => 'technical-support', 'priority' => 'high', 'flow' => 'resolved', 'ago' => 45,
        'subject' => 'Webhook to our CRM keeps failing',
        'description' => "Our webhook for \"invoice.paid\" has failed 37 times since Monday according to the delivery log. Our endpoint responds fine when I test it with Postman.\n\nEmily",
        'a1' => "Hi Emily, the delivery log shows your endpoint returning HTTP 301, a redirect from http to https. Webhooks don't follow redirects for security reasons. Could you update the webhook URL to start with https://?",
        'c1' => 'Changed it to https and the test delivery succeeded. Can the failed ones be resent?',
        'a2' => "Yes, I've re-queued the 37 failed deliveries and they've all gone through now. Marking this as resolved.",
    ],

    // ------------------------------------------------------------- Bug Report
    [
        'customer' => 4, 'category' => 'bug-report', 'priority' => 'high', 'flow' => 'closed', 'ago' => 560, 'agent' => 4,
        'subject' => 'Discount applied twice on invoices with multiple items',
        'description' => "When an invoice has more than one line and we apply a 10% discount, the total is reduced by 19% instead of 10%. Example: INV-2026-0342.\n\nJonathan",
        'a1' => "Hi Jonathan, thank you for the example, that made it easy to reproduce. The discount was applied once per line and again on the subtotal. Our developers have a fix ready. I've corrected INV-2026-0342 manually in the meantime.",
        'c1' => 'Thanks. Do we need to check older invoices as well?',
        'a2' => 'The fix has been released. I ran a check on your account: only INV-2026-0342 and INV-2026-0347 were affected, and both are now corrected. Closing this ticket.',
        'note' => 'Bug confirmed: discount on subtotal plus per line. Fix shipped in release 4.18.2.',
    ],
    [
        'customer' => 8, 'category' => 'bug-report', 'priority' => 'medium', 'flow' => 'closed', 'ago' => 300,
        'subject' => 'Date picker shows the wrong month',
        'description' => "On the expense form, the date picker opens on January every time instead of the current month. Small thing but annoying.\n\nImran",
        'a1' => "Hi Imran, thanks for reporting this. I can reproduce it when the browser language is set to Bangla. It's a bug in the date picker, and I've passed it to the developers with the steps.",
        'c1' => 'OK, thanks.',
        'a2' => 'The fix was released this morning, and the date picker now opens on the current month. Thanks again for reporting it!',
    ],
    [
        'customer' => 13, 'category' => 'bug-report', 'priority' => 'medium', 'flow' => 'working', 'ago' => 7, 'agent' => 4, 'reassign' => 1,
        'subject' => 'Fee receipts show the wrong student name',
        'description' => "Two parents told us the receipt they received has another student's name, although the amount and invoice number are right. This is a privacy problem for us.\n\nAyesha Siddika",
        'a1' => "Hi Ayesha, thank you for telling us right away. I'm treating this as a priority. Could you send the invoice numbers of the two receipts? Please don't forward the receipts themselves.",
        'c1' => 'INV-2026-1189 and INV-2026-1192.',
        'note' => 'Both receipts were generated in the same second. Looks like a race condition in the receipt template cache.',
    ],
    [
        'customer' => 10, 'category' => 'bug-report', 'priority' => 'low', 'flow' => 'new', 'ago' => 1,
        'subject' => 'Typo on the payment confirmation page',
        'description' => "Small thing: the payment confirmation page says \"Your paymnet was successful\".\n\nDavid",
    ],
    [
        'customer' => 7, 'category' => 'bug-report', 'priority' => 'medium', 'flow' => 'approaching', 'ago' => 20,
        'subject' => 'Appointment reminders sent at the wrong time',
        'description' => "Our appointment reminder SMS are going out at 3 AM instead of 9 AM the day before. We changed nothing in the settings.\n\nLaura Bennett\nOakridge Dental",
        'a1' => "Hi Laura, sorry for the early-morning messages. Your account's time zone is set to UTC instead of Asia/Dhaka, which explains the 6-hour shift. Did anyone change the account settings recently? I'll check the audit log as well.",
        'note' => 'Audit log: the time zone changed during last week\'s settings migration, not by the customer. Other accounts may be affected.',
    ],
    [
        'customer' => 2, 'category' => 'bug-report', 'priority' => 'critical', 'flow' => 'breached', 'ago' => 30,
        'subject' => 'Stock levels going negative after returns',
        'description' => "When a customer returns an item, the stock goes down instead of up. We now have 14 products with negative stock and can't create purchase orders for them.\n\nRashed Chowdhury",
        'a1' => "Hi Rashed, I've reproduced this: returns created from the mobile app subtract stock instead of adding it. I've escalated it to the development team as a critical bug. Meanwhile, please create returns from the web app, which works correctly.",
        'note' => 'Mobile app v3.2 sends quantity with the wrong sign for returns. Dev team is aware; also need a script to fix the stock levels.',
    ],

    // -------------------------------------------------------- Feature Request
    [
        'customer' => 11, 'category' => 'feature-request', 'priority' => 'low', 'flow' => 'closed', 'ago' => 480,
        'subject' => 'Allow bKash as a payment option on invoices',
        'description' => "Most of our customers pay by bKash. Could you add a bKash payment button to the invoices we send?\n\nSumaiya",
        'a1' => "Hi Sumaiya, thank you for the suggestion. A bKash integration is on our roadmap for next quarter. I've added your request to it so you'll be notified when it's available.",
        'c1' => 'Great, looking forward to it.',
        'a2' => "I'll close this ticket for now; you'll get an email as soon as bKash payments go live.",
    ],
    [
        'customer' => 0, 'category' => 'feature-request', 'priority' => 'low', 'flow' => 'waiting', 'ago' => 34,
        'subject' => 'Bangla language for customer-facing invoices',
        'description' => "Some of our retail customers would prefer invoices in Bangla. Is it possible to choose the invoice language per customer?\n\nMahmudul Karim",
        'a1' => 'Hi Mahmudul, invoice language can already be set per customer under Customers > Edit > Language, and Bangla is supported for the invoice template. The item names stay as you enter them, though. Would that cover what you need, or do you also need the item names translated?',
    ],
    [
        'customer' => 5, 'category' => 'feature-request', 'priority' => 'medium', 'flow' => 'assigned', 'ago' => 12,
        'subject' => 'Low-stock alerts by SMS',
        'description' => "We get low-stock alerts by email, but our shop managers don't check email often. Can the alerts also be sent by SMS?\n\nTasnim",
    ],
    [
        'customer' => 3, 'category' => 'feature-request', 'priority' => 'low', 'flow' => 'closed', 'ago' => 240,
        'subject' => 'Export reports to Google Sheets',
        'description' => "It would save us a lot of time if the monthly reports could be sent straight to a Google Sheet instead of downloading Excel files.\n\nPriya",
        'a1' => "Hi Priya, thanks for the idea. You can already schedule reports to be emailed as CSV every month (Reports > Schedule), which Google Sheets can import automatically with the IMPORTDATA function. A direct integration isn't planned yet, but I've logged your vote for it.",
        'c1' => 'The scheduled CSV works for now. Thanks.',
        'a2' => "Glad that helps! I'll close this ticket.",
    ],
    [
        'customer' => 6, 'category' => 'feature-request', 'priority' => 'low', 'flow' => 'new', 'ago' => 5,
        'subject' => 'Route planning for deliveries',
        'description' => "Do you plan to add delivery route planning? We have 6 vans and plan routes by hand every morning.\n\nOmar Faruk",
    ],

    // -------------------------------------------------------- General Inquiry
    [
        'customer' => 12, 'category' => 'general-inquiry', 'priority' => 'low', 'flow' => 'closed', 'ago' => 650,
        'subject' => 'Do you offer training for new staff?',
        'description' => "We're onboarding 5 new accountants next month. Do you offer training sessions?\n\nKamal",
        'a1' => "Hi Kamal, yes: we run free online onboarding sessions every Tuesday at 11 AM, and Business Plus customers can book a private session. I've sent you the booking link by email.",
        'c1' => 'Booked the Tuesday session for the 15th. Thanks!',
        'a2' => 'Great, you will receive a reminder the day before. Closing this ticket.',
    ],
    [
        'customer' => 8, 'category' => 'general-inquiry', 'priority' => 'medium', 'flow' => 'closed', 'ago' => 200,
        'subject' => 'Where is our data stored?',
        'description' => "Our bank is asking where our financial data is stored and whether it is backed up. Can you send us something we can forward to them?\n\nImran Siddiqui",
        'a1' => "Hi Imran, your data is stored in data centres in Singapore, encrypted at rest, with backups every 6 hours kept for 30 days. I've attached our security overview PDF, which is written for exactly this kind of request.",
        'c1' => 'That is exactly what they needed. Thank you.',
        'a2' => "You're welcome. Closing this one.",
    ],
    [
        'customer' => 4, 'category' => 'general-inquiry', 'priority' => 'low', 'flow' => 'resolved', 'ago' => 22,
        'subject' => 'Office hours during the holidays',
        'description' => "Will support be available during the upcoming public holidays? We close our books at the end of the month.\n\nJon",
        'a1' => 'Hi Jon, support is closed on public holidays, but urgent issues (system down, payment problems) are still handled by the on-call team: just mark the ticket as critical. Normal hours resume the next working day.',
        'c1' => 'Good to know, thanks.',
        'a2' => 'Anytime! Marking this as resolved.',
    ],
    [
        'customer' => 1, 'category' => 'general-inquiry', 'priority' => 'low', 'flow' => 'new', 'ago' => 0.5,
        'subject' => 'Discount for non-profit organisations?',
        'description' => "My sister runs a small charity and is interested in using your software. Do you offer a non-profit discount?\n\nEmily",
    ],
    [
        'customer' => 10, 'category' => 'general-inquiry', 'priority' => 'medium', 'flow' => 'working', 'ago' => 9,
        'subject' => 'Moving data from our old accounting software',
        'description' => "We're switching from our old desktop accounting software. Can you help us import our customers, products and last year's invoices?\n\nDavid Kim",
        'a1' => "Hi David, happy to help. We can import customers and products from Excel or CSV, and invoices from the last 12 months. I've sent you our import templates by email. Once you've filled them in, attach them here and I'll check them before we run the import.",
    ],
    [
        'customer' => 13, 'category' => 'general-inquiry', 'priority' => 'low', 'flow' => 'closed', 'ago' => 360,
        'subject' => 'Can parents pay fees online?',
        'description' => "We'd like parents to pay school fees online through a link instead of coming to the office. Is this possible?\n\nAyesha",
        'a1' => 'Hi Ayesha, yes. Turn on online payments under Settings > Payments, and every fee invoice will include a "Pay now" link that accepts cards and mobile banking. Fees are 1.8% per transaction.',
        'c1' => 'We enabled it and the first parent already paid online. Thanks!',
        'a2' => 'Wonderful! Closing this ticket.',
    ],
    [
        'customer' => 5, 'category' => 'billing', 'priority' => 'medium', 'flow' => 'closed', 'ago' => 380,
        'subject' => 'Receipt for last payment not received',
        'description' => "We paid the September invoice by bank transfer on the 5th but didn't receive a receipt.\n\nTasnim",
        'a1' => "Hi Tasnim, the transfer arrived on the 8th and the invoice is now marked paid. Receipts for bank transfers are generated when we match the payment, so it went out on the 8th to accounts@dhakafreshmart.test. I've resent it to your address as well.",
        'c1' => 'Received, thank you.',
        'a2' => 'Great, closing this one.',
    ],
    [
        'customer' => 2, 'category' => 'technical-support', 'priority' => 'medium', 'flow' => 'closed', 'ago' => 420, 'agent' => 4,
        'subject' => 'Thermal printer prints blank receipts',
        'description' => "Our thermal printer (Xprinter XP-58) prints blank paper when printing receipts from the POS screen. Test pages from Windows print fine.\n\nRashed",
        'a1' => "Hi Rashed, blank thermal prints usually mean the receipt is sent as an image that the printer can't render. In POS settings, switch \"Receipt format\" from Image to Text and print again.",
        'c1' => 'Switched to Text and it prints correctly now.',
        'a2' => 'Perfect, closing this ticket. Happy selling!',
    ],
    [
        'customer' => 11, 'category' => 'account-login', 'priority' => 'low', 'flow' => 'closed', 'ago' => 260,
        'subject' => 'Change the email address on my account',
        'description' => "I'd like to change my login email from my personal Gmail to sumaiya@petalandpot.test.\n\nSumaiya",
        'a1' => "Hi Sumaiya, you can change it yourself under Profile > Email. We'll send a confirmation link to the new address. If you can't access the old address, let me know and I'll verify you another way.",
        'c1' => 'Done, confirmed the new address. Thanks.',
        'a2' => 'Great, closing this ticket.',
    ],
    [
        'customer' => 9, 'category' => 'feature-request', 'priority' => 'low', 'flow' => 'working', 'ago' => 14,
        'subject' => 'Variants (size and colour) for products',
        'description' => "We sell handmade bags in different colours and sizes. Right now we create a separate product for each one. Could you add product variants?\n\nMehnaz",
        'a1' => 'Hi Mehnaz, product variants are being built right now and should be available in about a month. I can add your account to the early-access group so you can try them first. Would you like that?',
        'c1' => 'Yes please, add us to early access!',
    ],
    [
        'customer' => 7, 'category' => 'billing', 'priority' => 'low', 'flow' => 'new', 'ago' => 2,
        'subject' => 'Question about the price change in January',
        'description' => "We received an email saying prices change in January. Does that affect our plan, which we paid for a year in advance?\n\nLaura",
    ],
];
