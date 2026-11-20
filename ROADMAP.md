# CMYK Designs roadmap

Version 0.8.0 is the baseline for future changes. The sequence below is proposed work, with an observable completion condition for each step.

| Order | Scope | Completion condition |
|---|---|---|
| Released · 0.8.0 | Quotes, orders, invoices, partial credits, inventory, employee records, payroll documents and initial cash/bank tracking | Source, schema 11 and calculation checks are available in this repository. |
| Verified · demonstration | Fictional print-shop walkthrough from login through a partial credit | [Screenshots and invoice PDF](docs/demo/) document the run. The browser checks a 540 DH invoice, a 216 DH credit and a 324 DH balance. |
| Next product milestone · cheques | Track cheque reference, bank, due date, deposit, clearance and rejection | A pending cheque appears in the queue. Clearing affects receipts once. Rejection preserves its history. |
| Later · complaints | Link a customer complaint to an order and record its resolution | A reviewer can trace the complaint from opening to closure. |
| Later · reporting | Build monthly indicators from verified invoices, credits and receipts | A sample month's totals reconcile to source documents, with billed and collected amounts shown separately. |
