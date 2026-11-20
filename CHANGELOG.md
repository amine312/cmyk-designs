# 0.8.0 — 2026-11-04

- First phase5 delivery: cash/bank accounts with immutable dated signed opening balances; planned/paid expenses, correction cancellation with reason, beneficiary/category/method/date/note, and internal two-leg transfers.
- Source-based treasury: confirmed order_payments plus paid noncancelled expenses plus transfers/openings. No copied payment ledger, no double count; pending/cancelled receipts excluded. Credit notes do not automatically remove received cash.
- New payments can select account/date; confirmation dates actual treasury receipt. Historical accounts/dates remain null; one-time explicit assignment of confirmed unassigned receipt, preserving payment identity and amount. Account opening guards prevent backdated assignment.
- Charge dates/money validated in integer cents, request-key replay protection. Cheque expense begins planned, paid only on observed debit. Payroll, stock costs and machine intervention costs never auto-post expenses. No bank instruction/refund/automatic reconciliation.
- Monthly/account-filtered journal, current account balances, selection cash-flow summary excluding openings/transfers, full journal CSV. Admin only. Current-state journal excludes corrected cancelled entries; source cancellation reasons/audit retained.
- Schema1–10 ->11 migration adds three tables and nullable payment account/date with FK; fresh install supported,25tables. No invented historic destinations.
- Validation:687 integration checks (finance73 + previous614), PHP/JS syntax, browser transfer and paid expense. Next: full cheque tracking.

# 0.7.3 — 2026-11-04

- Requested employee followup: optional monthly base salary and default gross bonus; monthly recorded attendance/absence/leave and task outcomes. No automatic employee score or absence deduction.
- Payroll drafts per employee/month with exact cent gross, manually entered social/IR/other deductions, and net; revision check, replay protection and permanent issue. Frozen employer/employee/amount snapshots, PAY numbering, automatic UTC timestamp and downloadable Unicode PDF. No automatic tax/contribution rates or money movement.
- Attestations of work and monthly gross base salary: active employee/current facts, explicit confirmation, signatory/place, frozen snapshots, independent ATT-TRV/ATT-SAL numbering and PDF. No automatic signature or sending.
- Commercial client contact position: Gérant, Admin, Directeur, Responsable or unspecified; shown in client form/list/CSV and future frozen commercial documents/PDF. Old documents unchanged.
- Schema9 adds optional salary fields and payroll_records/employee_documents; schema10 adds contact_position. Schema1–9 ->10 migration and fresh installation supported, now22 tables. Stock details from0.7.1 retained.
- Validation:614 integration checks (new payroll/client78 + previous536), PHP/JS syntax, browser payroll save/issue and client position options, demo PDF export and three HR PDF visual checks. Commercial PDF with contact position also verified.

# 0.7.1 — 2026-11-04

- Requested stock detail update: initial entry date, optional expiration, wholesale and retail sale prices in DH HT per stock unit, and unit weight in kg.
- New article forms prefill today; old historical fields stay unknown. Validate real dates, no future entry, expiry on/after entry when both set, nonnegative two-decimal prices and positive optional three-decimal weight. Zero price and unspecified value remain distinct.
- Fields appear in stock form/list/CSV. Expired/today indicators are informational; quantities/reservations/movement timestamps and existing order prices are unchanged. One set of metadata per stock item; distinct lots need distinct item records.
- Old edit forms without new fields preserve values; explicit blank clears optional values. Admin permissions retained.
- Restartable schema 1–7 ->8 migration adds five nullable stock_items columns without backfill or data replacement. Fresh schema includes them.
- Validation: 536 integration checks (32 new stock detail checks plus 504 existing checks); PHP/JS syntax and interactive edit/save verified. Next planned feature remains phase5 finance.

# 0.7.0 — 2026-11-04

- Completes phase 4 with immutable full/partial credit notes from an issued invoice. Select remaining quantities, review exact HT/TVA/TTC, provide a reason and explicitly confirm issue. Independent AVR yearly numbering, source invoice reference, automatic timestamp and PDF.
- Frozen issuer, customer, terms and commercial lines come from the original invoice. Credit items retain source line indices and selected quantities. Historical single-line invoices use issued amounts without recalculation.
- Cumulative integer allocation of net HT, discount and VAT by quantity prevents split-rounding drift. Last residual exactly exhausts source amounts. Overflow-safe proportional calculation for 64-bit PHP. No arbitrary price/amount input, negative quantity, excess quantity or zero-TTC note.
- Order-first, invoice-second locks serialize credits and payments. Unique request key makes replay idempotent; reviewed position fingerprint rejects forms made stale by another issued credit.
- Client CA is invoice HT less credit HT. Invoice net TTC and outstanding due deduct credits; confirmed payments remain intact. Excess paid is shown separately as a favourable customer balance, without automatically offsetting another invoice. Pending payments retain their state and cannot be confirmed above the corrected amount due.
- Invoice credit history, order/client balance displays, credit document filter and commercial document CSV with source number and explicit amount direction. PDFs show positive amounts to deduct; original invoice PDF keeps issued lines/totals and adds current financial status.
- No automated refund, transfer, credit application across invoices, inventory return or charge. Issued credit notes have no edit/delete/reversal action in this version. Admin-only commercial access retained.
- Non-destructive, restartable schema 1–6 →7 migration adds invoice reference, unique issue key and reason to documents. Fresh install includes both document relationships; 20 tables retained. Keep existing app/config.php and database on update.
- Validation: 504 integration checks pass (credits81, quotes81, team156, fresh31, lines53, commerce37, stock65). PHP/JS syntax pass. Real PHP form estimates and inline partial-credit workflow checked in browser. Credit, invoice-after-credit and 50-line credit PDFs visually reviewed; 50 lines fit three pages with repeated table headings. Demo PDF export checked independently.
- Next phase: finance, expenses, cash/bank and cheque tracking. Complaints and analytics/targets remain planned.

# 0.6.0 — 2026-11-04

- Second phase-4 delivery: standalone quote drafts before production orders; edit/archive/reactivate, optimistic revision check and idempotent draft creation/issue/copy.
- Issue freezes the customer/company identity, commercial title, description, line items, totals and optional validity. Shared DEV numbering with existing quotes. No order, payment or turnover created at draft/issue/acceptance.
- Manual customer agreement with required reference, explicit confirmation, UTC entry timestamp and audit actor; refusal and expiry handling. No customer message or electronic signature is sent.
- Convert an accepted quote to exactly one order, protected by transaction locks and a unique source quote constraint. Simultaneous/repeated clicks open the same order. Existing linked historical quotes reuse their order if still matching and uninvoiced.
- Accepted customer/lines locked on resulting order. Invoice references its accepted quote and retains its frozen commercial information, even after live company/customer records change. Production status/brief remain editable.
- Preparing a new proposition copies lines into a separate draft and retains a link to the prior quote; it does not automatically withdraw the prior proposition.
- Admin-only quote pages/actions; current operational manager/employee permissions unchanged. Existing PDF/payment/stock/team workflows retained.
- Restartable schema 1–5 →6 migration: standalone quote support on documents, nullable document order link, one source-quote link per order and quote_drafts table (20 tables total). Historical snapshots/amounts untouched.
- Validation: 417 integration checks pass, including concurrent conversion, legacy compatibility, role restrictions and all previous suites; browser workflow and unsaved-draft issue guard checked; standalone quote and converted invoice PDFs visually reviewed.
- Next delivery: credit notes (avoirs). Advanced finance/expenses/banking, complaints and analytics/targets remain planned.

# 0.5.0 — 2026-11-04

- First phase-4 delivery: 1–50 ordered service/product lines per order, with designation, quantity/unit, unit price HT, percentage discount and percentage VAT for each line.
- Exact integer calculations: round gross HT to cents, then discount to cents, then VAT on net HT to cents per line; sum rounded lines. Mixed tax rates have an explicit grouped breakdown.
- Quotes and invoices freeze every line and its calculation version. Existing historical document snapshots and amounts remain unchanged. An issued invoice locks client, line labels, order, quantities, units, prices, discounts and VAT; operational status/brief can still change.
- Order/payment/client balances and turnover use summed net HT and tax. Cannot reduce an order below paid/pending amounts; parent-order transaction locks retained. Single-invoice-per-order and numbering protections retained.
- Add/remove line editor, live totals, responsive layout, all-line PDF table with repeated headers and whole rows; detailed line CSV and summary tax/TTC/count columns.
- Authenticated restartable migration schema 1/2/3/4 → 5 adds nullable lines_json and tax_amount to orders, without recalculating legacy orders or editing issued snapshots. Nineteen tables retained.
- Verification: 319 integration checks pass, including existing commercial, stock, employee-role and fresh-install tests. Real server form and inline demo checked in browser; 50-line PDFs rendered and reviewed. Demo PDF exporter checked separately.
- Quote acceptance/conversion and credit notes are the next phase-4 deliveries. Expenses/banking, complaints and analytics/targets remain planned.

# 0.4.0 — 2026-11-04

- Phase 3: employee profiles, employment state, archive/reactivation and linked personal accounts.
- Three server-enforced roles: administrator, production manager and employee. Manager sees order summaries without prices and manages team tasks; employee sees only own assignments. HR, customer contacts, financial pages, documents, PDF and exports stay administrator-only.
- Single-use activation code (24 h), hashed at rest; account owner sets own password. No email sending. Account/role changes and password changes invalidate old sessions; protect the last active activated administrator.
- Tasks linked optionally to an order, assignee, estimated hours, priority, due date, progress and event history. An employee cannot cancel or reassign tasks.
- Attendance/absence/leave: one entry per employee/day, corrections by re-entry, exact decimal hours and date validation.
- Manual review history: appreciation 1–5, strengths, improvements and required objectives. No automatic ranking, payroll, hiring or termination decision.
- Restartable authenticated migration schema 1/2/3 → 4; existing administrator and commercial/stock data preserved. New installs contain no sample records.
- Integration verification: 266 checks passed, including roles, ownership, activation, migration, fresh installation and commercial/stock regression. Browser demo checked for employee/task/progress/attendance forms.
- Phase 4 remains next: multi-line documents, quote conversion and credit notes. Finance charges, banking, complaints and analytics/targets remain planned.

# CMYK Designs — changelog

## 0.3.0 — 2026-11-04

Stock master data, exact three-decimal movement ledger, supplier/location fields, thresholds and alerts, order-linked reservations, release/consume workflow with a single outbound movement. Parent-row locks serialize concurrent stock mutations; reserved quantities cannot be withdrawn directly. Delivery/cancellation is blocked until reservations are resolved. Units lock after history; nonempty items cannot be archived. Search, pagination, CSV exports and preserved audit trail.

Equipment master data, states, service dates, intervention log/costs and archive/reactivation. Maintenance updates current state and next service; costs remain operational records, not auto-booked expenses.

Schema 3 adds five tables without resetting existing commerce. Authenticated, restartable upgrade from schema 1 or 2. Fresh installs initialize schema 3. Validation: 65 inventory/migration checks, 37 commerce regression checks and 18 fresh-install checks passed (120 total), including concurrent withdrawal and browser prototype workflows.


## 0.2.0 — 2026-11-04

Client IF, computed outstanding invoiced credit and net invoiced turnover. Automatic order timestamps in configured timezone. Confirmed/pending/cancelled payment ledger, methods and references, cheque confirmation, overpayment guard and duplicate-submit key. Immutable single-service quote/invoice snapshots, transaction-safe yearly numbering, one invoice per order, HTML view and bundled TCPDF downloads with Unicode support. Company identity and tax settings. Authenticated idempotent schema 1-to-2 migration, preserving old records.

Validation: 37 commerce/migration checks + 14 fresh-install checks passed. Short and multi-page PDF output rendered and inspected. Preview PDF generator independently executed and inspected; browser Blob download capture was unavailable. No real credentials or demo records in the delivery. See MISE_A_JOUR_V02.html.


## 0.1.0 — 2026-11-03

First installable cPanel delivery. Administrator installation and sign-in, client CRUD with archive/reactivation, linked orders with exact cent rounding, status/priority/due date, dashboard from actual records, CSV exports, company settings, password change, and audit trail.

Schema version 1. No automatic migration engine in this version. No config.php or real credentials are shipped. Never rerun the installer to update an installed database.

Future modules are planned in PLAN_TRAVAIL.html, not yet implemented. The inline preview uses temporary fictional data and is separate from the server database.

Validation: 39 integration checks passed on PHP 8.3 / MariaDB 10.11. Preview client creation and decimal amount update verified in browser. Server visual QA on cPanel remains to be performed after installation.
