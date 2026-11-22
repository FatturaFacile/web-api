# Accounts Receivable and Credit Management API (Mod 013, issue #165)

Base: `/api/v1/invoicing/receivables`, headers `Authorization: Bearer`, `X-Tenant-Id`. Amounts are decimal **strings** with 2 decimals
(never floats); dates are `YYYY-MM-DD`; the response envelope of the read endpoints is `{ "data": ... }` (the paginated
list and `items` return the Laravel resource pagination `{ data, links, meta }`). Writes need a credit-manager role
(`owner`, `admin`, `accountant`, `sales_manager`), otherwise 403. Validation errors are 422 `{message, errors}`.
Currency: every aggregate is in one currency (`currency` query param, default = company currency); other currencies are
listed in `available_currencies`.

## Shared vocabulary
- Item status: `open`, `partially_paid`, `paid`, `overdue` (derived: open/partially paid and past due), `disputed`, `written_off`.
- Allocation `type` (= `source_type`): `payment`, `credit_note`, `debit_adjustment` (raises the open amount, stored negative), `write_off`, `discount`.
- Reaction policy: `warning`, `block_order`, `block_delivery_note`, `credit_hold`. Override scope: `all`, `order`, `delivery_note`.
- Credit decision outcome: `ok`, `warning`, `block`.
- Aging bucket keys (default bounds 30/60/90/120): `current`, `1_30`, `31_60`, `61_90`, `91_120`, `121_plus`. Always render buckets
  from the `buckets` array of the response (`{key, from, to}`), never hardcode them; "over 90" = `over_90`.
- Risk rating: `low` (<25), `medium` (<50), `high` (<75), `critical`.
- Exposure = open_invoices + unbilled_delivery_notes + confirmed_orders - unreconciled_payments.
- DSO = receivables / credit_sales * period_days (default 90); `dso` is `null` when there are receivables but no sales.
- Provision per bucket = open_amount * rate_percent / 100; default rates 0.5, 1, 5, 10, 25, 50.

## Endpoints
| Method | Path | Notes |
|---|---|---|
| GET | `/dashboard?currency=` | `{as_of, currency, kpis:{total_exposure,total_open,overdue_amount,over_90,dso,bad_debt_provision,net_realizable_value,blocked_customers,active_overrides}, aging, blocked:[{customer_id,reason,exposure,limit}]}` |
| GET | `/aging?customer_id=&currency=` | `{as_of,currency,available_currencies,buckets:[{key,from,to}],totals:{key:amount},total_open,total_overdue,over_90,customers:[{customer_id,customer_name,buckets:{key:amount},total_open,total_overdue,items}]}` |
| GET | `/items?customer_id&status&document_type&currency&due_from&due_to&issued_from&issued_to&value_from&value_to&bucket&open_only&per_page&page` | paginated; drill-down from an aging bucket = `bucket=<key>&customer_id=` |
| GET | `/items/{id}` | item with `allocations` |
| POST | `/items/{id}/dispute` | `{disputed:boolean, reason?}` (reason required when disputed) |
| POST | `/allocations` | `{allocations:[{receivable_item_id,type,amount,value_date,source_id?,reference?,notes?}]}` -> 201 `{data:{allocations,items}}`; `payment` with `source_id` = ledger entry id from unreconciled payments, `credit_note` needs `source_id` = credit note invoice id |
| POST | `/allocations/{id}/reverse` | 200 allocation |
| GET | `/customers/{id}/ledger?from&to&document_type&status&currency` | `{movements:[{date,value_date,kind,document_type,reference,receivable_item_id,allocation_id,currency,debit,credit,due_date,status,balance}],closing_balance}` |
| GET | `/customers/{id}/unreconciled-payments` | `[{ledger_entry_id,reference,date,currency,amount,unallocated}]` |
| GET | `/customers/{id}/credit` | `{customer_id,as_of,limit:CreditLimit|null,exposure:{open_invoices,unbilled_delivery_notes,confirmed_orders,unreconciled_payments,total_exposure,overdue_amount,overdue_items,max_days_past_due},utilization_percent|null,available_credit|null,insured_cover,uninsured_exposure,is_credit_hold,is_blocked,decision,overrides:[Override]}` |
| GET | `/customers/{id}/risk` | `{customer_id,score,rating,factors:[{key,value,points}],blocked}` |
| GET | `/customers/{id}/credit-limits` | history, newest first |
| POST | `/credit-limits` | `{customer_id,currency,granted_limit,insured_limit?,insurer_name?,policy_number?,coverage_percentage?,max_overdue_days?,overdue_tolerance_amount?,reaction_policy,valid_from,valid_to?,is_active?,notes?}` -> 201 |
| PUT | `/credit-limits/{id}` | partial update |
| DELETE | `/credit-limits/{id}` | 204 |
| POST | `/customers/{id}/overrides` | `{scope,reason(min 5),expires_at?}` -> 201 |
| POST | `/overrides/{id}/revoke` | `{reason?}` |
| GET | `/credit-check?customer_id&scope=order|delivery_note&amount` | `{outcome,breaches[],reason,exposure,limit,overridden,reaction_policy}` |
| GET | `/dso?customer_id&period_days&currency` | `{as_of,period_days,currency,receivables,credit_sales,dso}` |
| GET | `/provision?customer_id&currency` | `{as_of,currency,lines:[{key,from,to,open_amount,rate_percent,provision,is_default_rate}],total_open,total_provision,net_realizable_value}` |
| PUT | `/provision/rates` | `{rates:{bucketKey:percent}}` -> `{data:{key:percent}}` |
| GET | `/customers/{id}/statement?from&to&currency` | `{tenant,customer,currency,period,as_of,opening_balance,movements,closing_balance,open_items,total_open,total_overdue,aging,credit}` |
| GET | `/customers/{id}/statement/pdf` , `/statement/xlsx` | file download |
| POST | `/customers/{id}/statement/send` | `{from,to,recipient?,locale?(it|de|fr|en)}` -> 202 `{data:{id,status:'queued',recipient}}` |

CreditLimit: `{id,customer_id,currency,granted_limit,insured_limit,insured_cover,insurer_name,policy_number,coverage_percentage,max_overdue_days,overdue_tolerance_amount,reaction_policy,valid_from,valid_to,is_active,notes}`.
Override: `{id,customer_id,scope,reason,authorized_by,expires_at,revoked_at,revoke_reason,created_at}`.
Item: `{id,customer_id,invoice_id,sequence,document_type,document_number,currency,original_amount,open_amount,settled_amount,issue_date,due_date,value_date,status,stored_status,days_past_due,dispute_reason,allocations?}`.
Allocation: `{id,receivable_item_id,source_type,origin,source_id,source_reference,amount,value_date,notes,allocated_by,reversed_at}`.

Enforcement (server side): order confirmation (`block_order`/`credit_hold`) puts the order on credit hold; issuing a delivery note
(`block_delivery_note`/`credit_hold`) fails with 422 `errors.credit`. An active override for the scope lets the operation through.
