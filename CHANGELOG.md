# Changelog

All notable changes to the FatturaFacile ERP API are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **Dashboard KPI summary** (#71): `total_unpaid` now includes the outstanding balance (gross minus paid) of partially
  paid invoices, and `total_revenue` includes the amount already collected on them.
### Added

- **MRP I & II** (#182, Mod 063, `modules/71_MrpPlanning`, `api/v1/mrp`): time-phased multi-level net requirements (low-level codes with cycle detection, gross-to-net on daily buckets with the safety stock as a floor, lot sizing LotForLot / MOQ / multiple / EOQ / fixed days with scrap), pegging of every proposal to its sales order line, MPS line, safety stock or parent proposal, supplier shutdown calendars (Ferragosto, Swiss factory holidays) and customs clearance days in the lead time, expedite / defer / cancel advice on open orders, infinite and finite capacity scheduling on work-centre buckets with overload flags, alternative work centres and auto-leveling, manual Gantt rescheduling, and firm / release / dismiss of proposals into purchase requisitions and production orders. Run modes regenerative and net change; firm orders carry over between runs. The MPS (Mod 062) is read through `MasterScheduleGateway` and is empty until that module exists.
- **Cross-border customs hub** (#143, `modules/32_Customs`): Passar and AIDA sandbox channels (payload builder plus simulated MRN; no transport to BAZG or ADM, payloads are not schema-validated), MRN validation and assignment (Union 18 characters, Swiss 18 to 21), regime and T1/T2 transit data with a bilingual tracking sheet, bilingual IT/EN and IT/DE customs pro-forma invoice with the preferential origin clause, invoice links with export-proof (eVV) matching and a "missing eVV" dashboard, import customs bills (duty kept apart from import VAT, broker invoice reconciliation, ledger posting), and product fields for Swiss HS code, weights, duty rate and preferential origin.
- **Subscription indexation** (`modules/19_SubscriptionBilling`): automatic annual price adjustment on ISTAT FOI (IT) and BFS LIK (CH) series, with contractual ratio, cap and no-decrease floor, recalculation sheet, once-per-cycle idempotency, customer notice by email (it/en/de/fr) and a nightly job that runs before the billing run; `churn_reasons` (by reason code, with lost MRR) in the subscription metrics; `qr_bill` payment method.
- **Customer registry 360 (CH/IT)**: formal validation that blocks saving on a wrong Partita IVA, Codice Fiscale (omocodia
  aware), Swiss UID `CHE-xxx.xxx.xxx` (modulo 11) or SDI code; `POST /api/v1/crm/fiscal/lookup` and
  `/contacts/{id}/fiscal/verify` autofill and certify from VIES (IT) and Zefix (CH, `ZEFIX_USERNAME`/`ZEFIX_PASSWORD`),
  degrading to the checksum when a registry is down; company hierarchy (`parent_id`, holding / subsidiary / branch, loop
  protection, `/hierarchy`); role-based contact persons; unlimited legal / billing / shipping / site addresses with one
  default per type; `/contacts/{id}/overview` 360 card
  (open balance, credit limit and residual, quotes, orders in progress, DDT to invoice, invoices, open tickets, privacy regime and consents,
  interactions, optional group consolidation); `/contacts/{id}/interactions` history with `updated_since` sync cursor
  for the mobile app.

- **Logistics module** (`modules/32_Logistics`): enterprise DDT (IT DPR 472/96 and CH layout), progressive numbering per
  tenant / fiscal year / section, fulfilment from one or more sales orders, cumulative deferred invoicing with
  DDT-line to invoice-line links, two-step stock transfers (ship to transit + automatic DDT, receive with discrepancy
  approval workflow), consignment / subcontracting register, periodic consumption declarations that draft a sales
  invoice or purchase order, and a balance-sheet valuation that excludes vendor-owned stock (Issue #158).
- Warehouse fields `legal_owner_type`, `linked_partner_id`, `company_entity_id` and audit columns; granular
  `logistics.*` permissions and the `logistics_manager` role; IT/EN/DE/FR translations (UI, DDT PDF and business-error messages, delivered already translated by the API) in `TranslationSeeder`.

- **DDT tracking and triangular shipments**: generated tracking code and carrier waybill (AWB, `POST
  /logistics/delivery-notes/{id}/awb`) on the DDT; `shipment_flow` (direct / triangular / dropshipping) with final
  recipient and a sender printed on the PDF as company, blank (obscured) or custom; transport reason "conto visione"
  (`on_approval`), excluded from every deferred-invoicing path until the customer accepts it (`POST
  /logistics/delivery-notes/{id}/accept-approval`).

## [1.0.0] - 2026-10-23

First production release: a complete multi-tenant ERP API for Italian and Swiss businesses. Database migrations are
additive only, so every phase can be applied to an existing installation.

### Core

- **Multi-tenancy** (phases 1-2): UUID keys everywhere, tenant scoping on every model, `X-Tenant-Id` identification with
  membership checks, per-tenant preferences, document numbering and locale handling.
- **Contacts and CRM**: customers and suppliers with addresses, countries (ISO 2 letters, foreign suppliers included),
  insights and payment terms.
- **Sales invoicing** (phases 3-4): quotes, orders, delivery notes (DDT), invoices, credit notes, proformas, payments
  with partial settlement, reminders, price lists, attachments and e-mail delivery of documents.
- **Italian e-invoicing (SDI)**: FatturaPA generation, outbound/inbound webhooks, notifications, passive invoice
  reception and duplicate detection.
- **Swiss QR-bill** (phase 9): SIX-compliant Swiss QR code with QR-IBAN (27 digit QR reference) and standard IBAN (ISO
  11649 or no reference), embedded in the invoice PDF.
- **PDF generation** (phase 8): dompdf behind a `PdfGenerator` contract for invoices, quotes, work orders and
  delivery notes; PDFs attached to e-mails.
- **Purchasing**: purchase orders, goods receipts, supplier invoices.
- **Point of sale and retail**: POS sessions, sales, refunds, loyalty cards, fiscal printer gateway.
- **Field service and operations**: work orders with signatures, time and attendance, subscriptions and recurring
  billing, alerts.
- **Inventory** (phases 5-6, 10, 12): warehouses, stock movements and levels, product variants with matrix
  generation, automatic SKU/barcode, minimum stock, auto-deactivation at zero stock, barcode and label printing (EAN-13,
  EAN-8, Code 128, QR; A4 and thermal presets, tenant presets).
- **E-commerce sync** (phase 11): WooCommerce, Shopify and PrestaShop drivers, order import, stock and product push,
  signed webhooks, scheduled order sync.

### Finance and treasury

- **Dashboard builder** (phase 13): modular widgets (revenue, cash flow, overdue invoices, low stock, e-commerce
  status, top products), saved per-user layouts and factory reset.
- **Backups** (phases 14, 17): manual and scheduled (02:00) tenant backups to a ZIP of the relational data and private
  files, retention of the latest 7, secure download, atomic restore with manifest/checksum validation, maintenance
  lock and automatic safety snapshot.
- **Bank statement import** (phase 15): CBI (fixed 120 character records), SEPA CAMT.053 and Swiss CAMT.054 with
  automatic format detection and streaming parsers.
- **Reconciliation** (phases 15-16): incoming payments by QR reference, invoice number or counterpart; outgoing
  payments on supplier invoices; bank fees, cent tolerances, partial payments, manual match, unmatch; automatic
  categorisation of F24 tax payments, payroll and bank charges with ledger accounts.
- **Accounting** (phase 17): general ledger (prima nota) in balanced double entry for invoices, supplier invoices,
  receipts, supplier payments and categorised bank lines, with preview and CSV export for accountants (`;` delimiter,
  decimal comma, UTF-8 BOM).

### Enterprise

- **Supplier fee alignment** (phase 18): a bank fee on a supplier transfer is a company cost and no longer reduces the
  supplier invoice debt, so the supplier ledger and the general ledger agree.
- **Multi-bank ledger mapping** (phase 18): `accounting.bank_accounts` maps every IBAN to its ledger account in the
  general ledger.
- **Safety snapshot before restore** (phase 18): `pre-restore-safety-*.zip`, with its own retention of 3.
- **Vendor aging** (phase 18): `GET /api/v1/accounting/vendor-aging`, open supplier balances by due-date bucket,
  per currency, with the projected cash outflow.

### Release engineering (phase 19)

- OpenAPI 3.1 specification (`docs/api/openapi.yaml`, `openapi.json`) and Postman collection v2.1, generated by
  `php artisan docs:export`; interactive documentation at `GET /api/documentation`.
- Client SDK generation for Angular and Flutter (`scripts/generate-clients.sh`) and the authentication guide
  (`docs/clients/`).
- `php artisan release:check` production readiness check and the production checklist
  (`docs/deployment/PRODUCTION_CHECKLIST.md`).
- Feature tests for the phase 18 refinements.

[Unreleased]: https://example.com/compare/v1.0.0...HEAD
[1.0.0]: https://example.com/releases/tag/v1.0.0
