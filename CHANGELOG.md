# Changelog

All notable changes to the FatturaFacile ERP API are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **Dashboard KPI summary** (#71): `total_unpaid` now includes the outstanding balance (gross minus paid) of partially
  paid invoices, and `total_revenue` includes the amount already collected on them.
### Added

- **Multi-currency engine, ECB / SNB / ESTV rates and realised and unrealised exchange differences** (#249, Mod 003, `modules/24_Treasury/src/Currency`, `api/v1/currencies`, `api/v1/accounting/fx-revaluations`, `api/v1/settings/currencies`): the exchange-rate table gets a rate type (`mid`, `buy`, `sell`, `contract`, `vat_estv_monthly`, `closing`), a publisher, a derived flag and versions (a rate is never edited: a correction is a new version and the old one points to it); `RateSelector` picks the rate of the day, the latest one within five days (weekends, holidays), the inverse or a cross rate through EUR (flagged derived), contract rates by reference, and refuses with 422 `exchange_rate_missing` beyond the window (no built-in rates anywhere: the legacy ECB provider lost its fallback and the SNB stub was removed). Daily ECB and SNB and monthly ESTV synchronisation (timeout, retry with back-off, schema validation, fake providers for tests) with a run history, `POST currencies/rates/sync` (202, 409 while running), failure alerts to finance and the daily-movement threshold alert; manual and contract rates (reason mandatory for a contract), tenant-isolated beside the shared official rates; CSV / XLSX audit export. Realised differences against the frozen document rate on `PaymentAllocated` (the ledger payment postings announce it; nothing is posted twice), the period-end revaluation (simulate, preview, post with an `Idempotency-Key`, reverse on the first day of the next period by request or by `ReverseRevaluationsJob`, losses always booked, gains recognised, provisioned or left out per company, Italian reserve tracking, four-eyes), the currency exposure dashboard, and the CH / IT VAT conversion strategies (ESTV monthly average or daily rate kept one tax period; Art. 13 c. 4 DPR 633/72). Document emission and the ledger read the rate through the engine (`InvoiceExchangeRateResolver`, `AccountingExchangeRate` are adapters), so a foreign-currency invoice needs a rate within five days of its date. The ESTV and SNB endpoints are configurable (`config/currency_fx.php`) and their identifiers and schemas must be confirmed against the live publications.

- **Budgeting and variance analysis: spend lock, revisions, scenarios, rolling roll-forward, Excel import and executive pack** (#266, Mod 019, `modules/53_Budgeting`, `api/v1/budgeting`): multidimensional grid (account x cost centre x project, monthly or quarterly, with quantity and unit price) and audit of every saved cell; original budget immutable once approved (a revised budget with mandatory reason replaces it, one revision at a time), best/worst/custom scenarios, transfers between lines, roll-forward of the rolling forecast (closed months at actual); spend lock `Available = Budget - (Actual + open commitments)` on requisitions and purchase orders (`Modules\Procurement\Contracts\SpendControlInterface`, rules per company/account/cost centre/project: allow, warning or hard block at create, submit and approve; commitments written on approval, consumed by receipt/invoice, released on cancellation, requisition replaced by its order without double counting, nightly `budgeting:reconcile-commitments`); override (deroga) requests decided by the CFO (four-eyes, partial amounts, expiry, temporary allowances, full history); traffic-light variance and exact volume/price decomposition; xlsx/csv import with row-by-row validation (all or nothing) and template; one-workbook executive pack (10 sheets, translated). New permissions `budgeting.control.manage` and `budgeting.override.approve`.
- **Sales commissions and agent management** (#338, Mod 041, `modules/77_AgentCommissions`, `api/v1/agent-commissions`): agent profiles on top of the sales representatives (direct, sub-agent, multi-mandate, single-mandate; Enasarco, AEC and Swiss direct-commission regimes; commission on salary or by invoice), multi-level rules by product category, customer and zone with a deterministic precedence (priority, then specificity, then the rule of the agent), fixed amounts, progressive yearly tiers and a precedence simulator, and sub-agent splits (additional rate or share of the sub-agent commission). The commission ledger accrues per invoice line on the invoiced or the collected basis; a cash-basis commission is released in proportion to partial collections only once the bank statement line of the payment is reconciled, is taken back when a reconciliation is undone, and credit notes reduce the commission of the invoice they correct (a commission already settled becomes a clawback line). The Italian fiscal engine reads seeded, tenant-overridable parameters (Enasarco agent and company shares by mandate, yearly massimale and minimale, FIRR brackets, withholding, VAT): the contribution stops exactly at the massimale, the minimale and the FIRR are tracked per agent and year. Commission statements (draft, approved, commission invoice / self-billing data prepared, paid, void) with PDF, email dispatch to the agent, a payout check against reconciled collections, and CSV exports for the Certificazione Unica and the Enasarco payment trace. Statutory defaults are seed data to review every January against the Enasarco circular.
- **Accounts payable: supplier ledger, Skonto, payment runs and payables aging** (#333, Mod 015, `modules/75_AccountsPayable`, `api/v1/accounts-payable`): open items (partite aperte) of the received XML invoices, Swiss QR bills, credit notes and manual entries, kept in line with the payments of the invoice and with the status `open`, `in_proposal`, `authorized`, `paid`, `disputed` or `blocked`; disputes and administrative blocks with an audited reason, history and release, which keep an item out of every proposal, approval and execution; the cash-discount (Skonto) engine, with the terms read from the item, the supplier agreement (also from the text of a clause such as `30 gg data fattura, 2% skonto entro 10 gg`) or the payment term of the supplier, exposing `skonto_eligible`, `skonto_percentage`, `skonto_deadline_date`, `skonto_days_remaining`, `skonto_savings_amount` and `net_payable_with_skonto`; payment runs (draft, `pending_cfo_approval`, `authorized`, `executed`, or rejected and cancelled) that select the invoices due by a cutoff date or at the last day of their discount, offset the supplier credit notes, group the transfers by creditor IBAN, require a CFO approval different from the author and submitter, and on execution record the payments, the active cash discount (new posting role `cash_discount_received`, default account 4090) and the credit-note offsets (`credit_offset` / `credit_used`) in the ledger; the payables aging by supplier in the buckets `current`, `1_30`, `31_60`, `61_90` and `over_90` with Excel and PDF exports in the four locales.
- **Italian VAT registers, plafond, LIPE, annual VAT and CU** (#163, Mod 029, `modules/74_ItalianVat`, `api/v1/italian-vat`): sectional sales, purchases and daily-receipts registers with progressive protocol numbers per sectional and year, VAT rate and nature totals, deductible and non-deductible VAT with the pro-rata of art. 19-bis (rounded up, provisional and definitive), ventilation of the takings in proportion to the purchases, a register of goods on account of third parties with balances, and the definitive print (page numbering, virtual stamp reference, content hash, locked lines and periods, integrity check, PDF; corrections are reversals). Plafond guard of the habitual exporters (check before issue, expiry, revocation, erosion monitor with alerts; the issue of the invoice already consumes it). Quarterly LIPE with one module per month (or the quarterly one), structural schema check, reconciliation with the Erario c/IVA account, detached signature verification and the draft / validated / signed / ready / transmitted / rejected flow. Annual VAT reconciliation (periods, pro-rata adjustment, previous credit, payments, ledger difference) and Certificazione Unica for professionals and agents from the withholding register (validation, PDF, fixed-length telematic file with pre-export checks) with F24 tracking and reconciliation. The CU file layout and the LIPE schema are structural profiles: both must still pass the control software of the Agenzia delle Entrate.
- **Industrial and analytical cost accounting** (#168, Mod 018, `modules/72_AnalyticalAccounting`, `api/v1/analytical`): hierarchical cost and profit centers (cycle-free tree, materialized path, effective dates, safe archive), a management ledger kept apart from the statutory general ledger (correlation ids only; hooks on posted invoices and journals), multi-dimensional allocation vectors (cost center, profit center, project, product line, cost element) split by percentage or amount with Largest Remainder rounding, prioritized derivation rules with provenance (manual, rule, master default), configurable allocation drivers with period values (area, headcount, machine hours, revenue, fixed percentages, custom), a pure step-down apportionment engine (ordered cascade, no flow back into closed senders, exact rounding, full allocation trace) with preview, consolidate and reverse, the contribution margin income statement (margin I, margin II, operating result, hierarchy roll-up, CSV) and the direct-versus-apportioned comparison. Web, desktop and mobile clients read the same contract and capture entries offline.
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
