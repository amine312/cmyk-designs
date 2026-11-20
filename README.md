# CMYK Designs

Print shop management software for quotes, production, inventory, invoicing and cash tracking. Built with PHP 8.2+, JavaScript and MySQL/MariaDB for a French-speaking workshop working in Moroccan dirhams.

## A job in figures

A customer orders 2.5 m² of large-format print at 200 DH/m². A 10% discount brings the net price to 450 DH. VAT at 20% adds 90 DH, for an invoice total of **540 DH**. If the workshop credits 1 m², the credit is 216 DH and the remaining 1.5 m² is worth 324 DH.

## Engineering decisions

- Issuing a document freezes its customer information and commercial lines. A later credit links back to the original invoice.
- Partial credits use cumulative allocation in integer cents so the final return exhausts the original amount exactly.
- Cash reporting reads confirmed receipts directly. Pending cheques and internal transfers are handled separately from customer revenue.

## Release baseline

Version 0.8.0 uses database schema 11. Six calculation checks cover totals, two partial credits and three invalid inputs. The baseline schema created 25 tables in MariaDB. End-to-end verification of login, order entry and PDF download is the next demonstration milestone.

[View the project roadmap](ROADMAP.md)
