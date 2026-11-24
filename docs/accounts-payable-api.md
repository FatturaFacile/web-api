# Accounts payable API (Mod 015, issue #333)

Module `modules/75_AccountsPayable`, prefix `/api/v1/accounts-payable`. Every response uses the envelope
`{success, message, data, meta}`; errors are RFC 7807 (`application/problem+json`) with the stable code in
`invalid_params[0].code` and its parameters in `invalid_params[0].params` (translate with
`accountsPayable.error.<code>` of the frontend dictionary). Amounts are decimal strings with two decimals, dates are
`YYYY-MM-DD`.

## Access

| Module key | Read | Write |
|---|---|---|
| `accounts_payable` | owner, admin, accountant, accountant_readonly | owner, admin, accountant |
| `accounts_payable_approval` | owner, admin, accountant, accountant_readonly | owner, admin (or a member granted `accounts_payable_approval.write`) |

`POST payment-runs/preview` and `POST supplier-terms/parse` only need read access with the grant `accounts_payable.read`.
The read-only accountant role can only issue GET requests.

## Open items (partitario fornitori)

`GET open-items` (filters `supplier_id, status, kind, currency, due_from, due_to, search, only_payable, skonto_eligible, as_of,
per_page`; `meta` has `current_page, per_page, total, last_page`), `GET open-items/{id}?as_of=`, `GET open-items/{id}/history`,
`POST open-items` (manual entry), `POST open-items/{id}/dispute|block|release` (`{reason}` required),
`PUT open-items/{id}/skonto-terms` (`{skonto_percentage, skonto_days}`, both null removes the override).

Item fields: `id, supplier_id, supplier_name, passive_invoice_id, source (electronic_xml|swiss_qr|manual), kind (invoice|credit_note),
document_number, document_date, due_date, currency, original_amount, paid_amount, discount_taken_amount, open_amount,
creditor_iban, status, hold {type: dispute|administrative_block, reason, held_at, held_by}|null, payment_block, days_past_due, notes`
plus the cash-discount block (below).

`status`: `open`, `in_proposal` (in a draft or pending run), `authorized` (in an authorized run), `paid`, `disputed`, `blocked`.
`payment_block` says why the item cannot go in a run now: `disputed`, `administrative_block`, `invoice_not_approved`,
`in_payment_run`, `settled`, or null.

History events: `created, proposed, authorized, paid, partially_paid, disputed, blocked, released, removed_from_run, run_rejected,
run_cancelled, skonto_terms_changed`, each with `from_status, to_status, reason, actor_id, context, occurred_at`.

## Cash discount (Skonto)

Fields on every open item, computed at `as_of` (default today): `skonto_eligible, skonto_percentage, skonto_deadline_date,
skonto_days_remaining (negative once expired, null without terms), skonto_savings_amount, net_payable_with_skonto,
standard_amount, skonto_terms_source (document|supplier_agreement|payment_term), skonto_net_days`.

The deadline is the document date plus the discount days; the discount is the percentage of the open balance, rounded to the
cent. Credit notes and partly paid invoices never earn it. Terms are looked up in this order: the document (`skonto-terms`), the
active supplier agreement valid on the document date, the payment term of the supplier contact.

Supplier agreements: `GET|POST supplier-terms`, `PUT|DELETE supplier-terms/{id}`. Body: `supplier_id` (create only), either
`skonto_percentage` + `skonto_days` or `clause` (`30 gg data fattura, 2% skonto entro 10 gg`, `2% Skonto innerhalb 10 Tagen, netto
30 Tage`, `2/10 net 30`), optional `net_days, reference, valid_from, valid_to, is_active, notes`.
`POST supplier-terms/parse {clause}` returns `{skonto_percentage, skonto_days, net_days}` without saving.

## Payment runs (distinte di pagamento)

Criteria (preview and create): `currency, cutoff_date, execution_date (>= today), bank_account_id?, include_skonto? (true),
net_credit_notes? (true), supplier_ids?, notes?`. An invoice is selected when it is due by the cutoff date, or when its Skonto
deadline falls by the cutoff and is still open on the execution date (the last run that earns it). The discount is taken whenever
it is available on the execution date. Credit notes of the supplier are offset (oldest due date first, after the discount, never
above what is paid). One transfer per supplier and creditor IBAN.

* `POST payment-runs/preview` returns `{items, offsets, transfers, excluded, totals}` and saves nothing.
* `POST payment-runs` creates a `draft` and reserves the items (`in_proposal`); `GET payment-runs` (`status, supplier_id, per_page`),
  `GET payment-runs/{id}`.
* `DELETE payment-runs/{id}/items/{itemId}` takes an invoice out of a draft and works the run out again (the last one cancels it).
* `POST payment-runs/{id}/submit` (draft to `pending_cfo_approval`), `recall` (back to draft), `approve` and `reject {reason}` (CFO:
  `authorized` / `rejected`), `execute` (`authorized` to `executed`), `cancel {reason?}`.

Run: `id, run_number, status, currency, cutoff_date, execution_date, bank_account_id, include_skonto, net_credit_notes,
totals {debit_total, credit_netted_total, skonto_total, net_total, items_count, transfers_count}, excluded, notes,
workflow {created_by, submitted_by/at, approved_by/at, rejected_by/at/rejection_reason, executed_by/at}, items, transfers, offsets`.
`items[]`: `open_item_id, transfer_id, supplier_id, document_number, item_status, kind, selection_reason (due|skonto|credit_note),
open_amount, skonto_percentage, skonto_deadline, skonto_amount, credit_applied_amount, cash_amount, credit_used_amount`.
`transfers[]`: `supplier_id, creditor_iban, currency, gross_amount, skonto_amount, credit_netted_amount, amount, items_count,
reference, status (pending|paid|netted)`. `excluded[]`: `open_item_id, document_number, supplier_id, reason` with reason
`disputed, administrative_block, invoice_not_approved, missing_iban, in_treasury_proposal`.

Rules enforced with `409` and a code: a run holding a disputed or blocked item cannot be submitted, approved or executed
(`run_contains_held_items`); an item whose balance or status changed after the proposal stops the run (`run_item_changed`);
the approver must be neither the author nor the submitter (`403 segregation_of_duties`).

On execution each invoice receives, through `RecordSupplierPayment`: the cash transfer (`payment`), the discount (`cash_discount`,
posted Dr payables / Cr the account of the role `cash_discount_received`, default 4090 "Sconti e abbuoni attivi") and the credit
notes offset (`credit_offset` on the invoice, Dr payables / Cr payables of the credit note, and `credit_used` on the credit note).
Manual items are settled in this module only.

## Payables aging

`GET aging?as_of=&supplier_id=&currency=&detail=` returns `{as_of, currency, available_currencies, buckets, summary, suppliers}`.
Buckets are `current, 1_30, 31_60, 61_90, over_90` (days past the due date) in the summary, in every supplier row and in every
detail line (`suppliers[].items[].bucket`). They hold the invoices; `credit_notes` is reported next to them and subtracted in
`net_total`. Disputed and blocked items stay in the buckets and are also reported in `held_total`.
`GET aging/export/xlsx` and `GET aging/export/pdf` (same parameters plus `locale=it|de|fr|en`) download the report with localized
labels.

## Client contract (`/api/v1/accounting/payables`)

The web client (`web-frontend`, `PayablesApiService`) speaks a second contract over the same services; every rule above applies.
Responses carry `{data}` (lists add the Laravel `links` and `meta`); validation errors are the Laravel `errors` bag, rule violations the
problem details above. Access is the same two module keys.

| Client route | Maps to |
|---|---|
| `GET items` (`supplier_id, status, document_type, currency, due_from, due_to, skonto_only, open_only, search, per_page, page`) | open items; `status` `in_batch` is `in_proposal`; `document_type` is `xml_invoice`, `qr_bill`, `manual_bill`, `credit_note` |
| `GET items/summary?currency=` | `{total_open, total_overdue, skonto_savings, blocked_count, currency}` (invoices only) |
| `GET items/{id}` | item with `block_history` (`kind` dispute, block, release) |
| `POST items/{id}/block {kind: dispute|block, reason}`, `POST items/{id}/release {reason}` | dispute, block, release |
| `POST batches/preview`, `POST batches` | payment run proposal and creation: `cutoff_date, prioritize_skonto (= include_skonto), currency?, supplier_id?, item_ids?, set_off_ids?`; creation needs `item_ids` (min 1) and `set_off_ids` (may be empty); the run is paid today |
| `GET batches`, `GET batches/{id}` | payment runs; `status` `pending_approval` is `pending_cfo_approval`, `approved` is `authorized` |
| `POST batches/{id}/submit|approve|reject|execute|cancel` | same transitions (approve and reject: CFO permission; reject needs `reason`) |
| `GET aging`, `GET aging/export/{xlsx|pdf}` | payables aging |

Item: `id, supplier_id, supplier_name, supplier_iban, document_type, document_number, issue_date, due_date, currency, original_amount,
open_amount, status, block_reason, batch_id, skonto {percent, deadline, days_remaining, savings, net_payable, terms}|null`
(`skonto` is null for a credit note or an item without terms; once the deadline passed it stays with negative `days_remaining`).
Batch: `id, number, status, cutoff_date, currency, prioritize_skonto, totals {gross_amount, set_off_amount, skonto_savings, net_amount,
transfers, lines}, transfers[{iban, supplier_id, supplier_name, currency, gross_amount, set_off_amount, skonto_savings, net_amount,
lines[{item_id, supplier_id, supplier_name, document_number, due_date, open_amount, pay_amount, skonto_taken, skonto_savings}]}],
set_offs[{credit_note_id, credit_note_number, supplier_id, supplier_name, amount, applied_to_item_ids}], approval {user_name, decision,
reason, decided_at}|null, executed_at, created_at`. Preview: `{cutoff_date, currency, candidates, credit_notes, excluded[{item_id, reason:
disputed|blocked|in_batch}], batch}` with `batch.id = "preview"`. Aging: `{as_of, currency, available_currencies, buckets, totals,
total_open, total_overdue, suppliers[{supplier_id, supplier_name, buckets, total_open, total_overdue, items}]}`.
