# Intercompany & Consolidation extensions (Mod 021) — API contract

Base path: `/api/v1/intercompany`. Auth: `auth:sanctum` + `tenant` header of the **parent (owner) entity** of the consolidation group. Module key: `intercompany` (feature flag `intercompany`).
Envelope: `{ "success": bool, "message": string, "data": ..., "meta": {...}? }`. Errors: 422 `{success:false,message,errors:{field:[...]}}`, 403 (member entity out of reach), 404, **409** (stale `expected_version`, mutation of a locked run, illegal transition).
Amounts are **strings** with 4 decimals (e.g. `"1234.5000"`), dates ISO `YYYY-MM-DD`, ids UUID. Every enum is returned as its **code**; labels come from the translation store (keys below), never from the API.
Group ids / run ids are the ones of the existing `/api/v1/general-ledger/consolidation/*` API (`groups/{id}`, `runs/{id}`).

## Translation keys (served by the frontend translation seeder)
Prefix `intercompany.` for everything, plus `NAV.INTERCOMPANY*` and `PAGE_TITLE.INTERCOMPANY*`.
Enum label keys: `intercompany.enum.<enumName>.<code>` — enums: `mirrorMode`(draft,confirmed) `linkStatus`(pending,drafted,confirmed,reversed,failed,skipped) `documentType`(invoice,debit_note,credit_note) `paymentStatus`(unpaid,partial,paid) `nettingStatus`(draft,confirmed,cancelled) `matchStatus`(unmatched,matched,disputed,written_off,adjusted) `anomalyType`(none,timing,fx,amount,tax,missing_counterpart) `rateType`(closing,average,historical) `runStatus`(draft,calculating,review,locked,posted) `standard`(fer30,ifrs10,oic17) `eliminationType`(intercompany_balance,intercompany_revenue_cost,investment_equity,equity_method,translation_difference,goodwill,goodwill_amortisation,unrealised_profit,dividend,fair_value_release,provision_release,manual) `consolidationMethod`(full,proportional,equity) `partyRole`(customer,supplier) `syncEvent`(mirror_created,mirror_confirmed,mirror_reversed,payment_synced,payment_variance,netting_applied,error).
Page/section keys: `intercompany.structure.*`, `intercompany.transactions.*`, `intercompany.reconciliation.*`, `intercompany.run.*`, `intercompany.statements.*`, `intercompany.package.*`, `intercompany.common.*`, `intercompany.error.<code>`.

## GET `/lookups`
`data`: `{ enums: { <enumName>: string[] codes }, standards: [{code, name_key, framework}] }`.

## Settings
- GET `/groups/{groupId}/settings` → `data: Settings`
- PUT `/groups/{groupId}/settings` body `{mirror_mode:'draft'|'confirmed', auto_mirror:bool, sync_payments:bool, netting_enabled:bool, amount_tolerance:string, date_tolerance_days:int, default_standard:'fer30'|'ifrs10'|'oic17'}`
`Settings = {id, group_id, mirror_mode, auto_mirror, sync_payments, netting_enabled, amount_tolerance, date_tolerance_days, default_standard}`

## Party links (counterparty entity ⇄ contact in the owner's books)
- GET `/groups/{groupId}/party-links` → `PartyLink[]`
- POST `/groups/{groupId}/party-links` body `{entity_tenant_id, counterparty_tenant_id, contact_id, role:'customer'|'supplier'}` → 201
- DELETE `/party-links/{id}`
`PartyLink = {id, group_id, entity_tenant_id, counterparty_tenant_id, contact_id, role, entity_name?, counterparty_name?}`
(`entity_tenant_id` = the tenant whose books hold `contact_id`; role `customer` = that contact is the counterparty as customer of the entity, `supplier` = as its supplier.)

## Intercompany transactions (mirror postings)
- GET `/groups/{groupId}/transactions?status=&payment_status=&source_tenant_id=&mirror_tenant_id=&page=&per_page=` → paginated `Transaction[]`, `meta` = pagination + `summary:{total,drafted,confirmed,reversed,failed,unpaid,amount_by_currency:{CHF:"..",EUR:".."}}`
- POST `/groups/{groupId}/transactions/scan` body `{from?:date,to?:date}` → `data:{created:int, skipped:int, failed:int, transactions: Transaction[]}` (picks up posted invoices/debit/credit notes between group entities that have no link yet)
- POST `/transactions/{id}/mirror` body `{mode?:'draft'|'confirmed', expected_version?:int}` → creates/refreshes the mirror (idempotent: same idempotency key never duplicates)
- POST `/transactions/{id}/confirm` body `{expected_version?}` draft → confirmed
- POST `/transactions/{id}/reverse` body `{reason:string, expected_version?}` voids the mirror (credit-note/void propagation)
- POST `/transactions/{id}/sync-payment` → pulls the paid amount of the source invoice and applies it to the mirror
- GET `/transactions/{id}` → `Transaction` with `events: SyncEvent[]`
`Transaction = {id, pair_id, group_id, document_type, source_tenant_id, source_tenant_name, source_document_id, source_document_number, mirror_tenant_id, mirror_tenant_name, mirror_document_id|null, mirror_document_number|null, mirror_status:'draft'|'confirmed'|null, status, currency, net_amount, tax_amount, gross_amount, payment_status, synced_paid_amount, paid_variance, document_date, error_message|null, version, idempotency_key, created_at}`
`SyncEvent = {id, transaction_id, event, message_key|null, context:object, amount|null, occurred_at, user_id|null}`

## Netting (compensazione delle partite)
- GET `/groups/{groupId}/netting?status=` → `NettingRun[]`
- POST `/groups/{groupId}/netting` body `{entity_a_tenant_id, entity_b_tenant_id, as_of_date, currency?}` → 201 draft proposal of the open items that offset each other (A receivables vs B receivables from the other side)
- POST `/netting/{id}/confirm` body `{expected_version?}` applies the settlement on both sides (status `confirmed`)
- POST `/netting/{id}/cancel` body `{reason}`
`NettingRun = {id, group_id, entity_a_tenant_id, entity_b_tenant_id, as_of_date, currency, status, receivable_total, payable_total, net_amount, net_direction:'a_pays_b'|'b_pays_a'|'balanced', fx_variance, amount_variance, version, items: NettingItem[]}`
`NettingItem = {id, transaction_id|null, side:'a'|'b', document_number, currency, open_amount, applied_amount, fx_rate, variance}`

## Reciprocal reconciliation cockpit
- GET `/groups/{groupId}/reconciliation/batches` → `Batch[]`
- POST `/groups/{groupId}/reconciliation/batches` body `{as_of_date:date, auto_match?:bool(default true)}` → 201 `Batch` (runs exact + tolerance matching, classifies anomalies)
- GET `/reconciliation/batches/{id}?anomaly_type=&status=&entity_a_tenant_id=&entity_b_tenant_id=` → `Batch` + `items: Item[]`
- GET `/groups/{groupId}/reconciliation/matrix?as_of_date=` → `Matrix`
- POST `/reconciliation/items/{id}/match` body `{counterpart_document_id}` (manual match, unmatched → matched)
- POST `/reconciliation/items/{id}/unmatch`
- POST `/reconciliation/items/{id}/dispute` body `{reason}`
- POST `/reconciliation/items/{id}/write-off` body `{reason}`
- POST `/reconciliation/items/{id}/adjust` body `{reason, adjustment_amount}`
`Batch = {id, group_id, as_of_date, status:'open'|'closed', totals:{items,matched,unmatched,disputed,written_off,adjusted, difference_by_anomaly:{timing,fx,amount,tax,missing_counterpart}}, created_at}`
`Item = {id, batch_id, entity_a_tenant_id, entity_a_name, entity_b_tenant_id, entity_b_name, a_document_id, a_document_number, a_document_date, a_currency, a_amount, a_tax, b_document_id|null, b_document_number|null, b_document_date|null, b_currency|null, b_amount|null, b_tax|null, difference, difference_group_currency, status:matchStatus, anomaly_type, anomaly_reason|null, match_kind:'exact'|'tolerance'|'manual'|null, resolution_reason|null, resolved_by|null, resolved_at|null}`
`Matrix = {currency, as_of_date, entities:[{tenant_id,name}], cells:[{entity_a_tenant_id, entity_b_tenant_id, receivable_a, payable_b, difference, anomaly_counts:{timing,fx,amount,tax,missing_counterpart}, status:'matched'|'difference', open_items:int}]}` (cell drill-down = list items of the latest batch filtered by the two entity ids)

## Period FX rates (override of `exchange_rates` for consolidation)
- GET `/groups/{groupId}/period-rates?currency=&rate_type=&period_end=` → `PeriodRate[]`
- POST `/groups/{groupId}/period-rates` body `{currency, rate_type:'closing'|'average'|'historical', period_end:date, rate:string}` (upsert by group+currency+type+period_end)
- DELETE `/period-rates/{id}`
`PeriodRate = {id, group_id, currency, group_currency, rate_type, period_end, rate, note}`
A closing/average/historical row with `period_end` equal to the run's period end wins over the published rates in `ConsolidationTranslator::ratesFor`.

## Scope validation (step 1 of the guided run, before a run exists)
- GET `/groups/{groupId}/scope-validation?fiscal_period_id=` → `{ready:bool, checks:[{code, passed:bool, entity_tenant_id|null, detail|null}]}`; check codes: `has_parent, has_members, ownership_valid, holder_in_group, currency_defined, rate_available, trial_balance_ready` (labels: `intercompany.structure.check.<code>`). Create the run itself with the existing `POST /api/v1/general-ledger/consolidation/groups/{id}/runs` `{fiscal_period_id}`.

## Run workflow (`runId` = id of a run of the existing GL consolidation API)
- GET `/runs/{runId}/workflow` → `{run_id, status:'draft'|'calculating'|'review'|'locked'|'posted', version:int, allowed_transitions:string[], manual_adjustments:int, history:[{id, from_status|null, to_status, action:'advance'|'lock'|'post'|'reopen', reason|null, user_id|null, created_at}]}`. A run that was never touched starts `draft` (or `locked` when the GL run is already locked).
- POST `/runs/{runId}/workflow/advance` body `{to, expected_version:int (required)}` → same shape as GET. Allowed: draft→calculating, calculating→review|draft, review→locked|calculating, locked→posted. `review` verifies the run inputs against the ledgers (409 `intercompany.error.inputs_changed` when they moved); `locked` locks the GL run. **409** with `error_code` for a stale `expected_version` (`stale_version`), an illegal transition (`illegal_transition`) or a mutation of a locked/posted run (`run_locked`).
- POST `/runs/{runId}/reopen` body `{reason:string(min 10), expected_version:int}` → workflow (status `review`). Only from `locked`/`posted` (else 409 `run_not_locked`); unlocks the GL run and records the reason in the history.
Error body of every 422/409 of this module: `{success:false, message, errors:{intercompany:[message]}, error_code:'intercompany.error.<code>'}` — show the label of `error_code` from the translation store.

## Consolidation analytics of a run
- GET `/runs/{runId}/elimination-ledger?type=&entity_tenant_id=` → `{run_id, currency, entries:[{adjustment_id, elimination_type (eliminationType code incl. 'manual'), description, relation_id|null, member_tenant_id|null, reference|null (manual only), amount, lines:[{line_number, entity_tenant_id|null, account_code, account_name, account_type, debit, credit, pre_elimination_amount, post_elimination_amount}]}], totals_by_type:{<type>:string}}`
- GET `/runs/{runId}/nci-schedule` → `{run_id, currency, rows:[{member_tenant_id, name, ownership_percentage, nci_percentage, consolidation_method, net_assets, nci_net_assets, nci_profit, nci_opening, nci_movement}], total_nci_net_assets, total_nci_profit}`
- GET `/runs/{runId}/shareholdings` → `{run_id, currency, rows:[{member_tenant_id, name, holder_tenant_id|null, direct_percentage, effective_percentage, voting_percentage (= direct: no separate voting data), consolidation_method, consolidation_share, currency, consolidation_basis, is_parent}]}`
- GET `/runs/{runId}/statements?standard=fer30|ifrs10|oic17&statement=all|balance_sheet|income_statement|cash_flow` (standard defaults to the group setting) →
  `{run_id, standard, currency, period_start, period_end, translation_reserve, nci:{net_assets, profit}, presentation:{standard, framework, name_key, balance_sheet_order:string[], income_layout:'function'|'nature', income_sections:string[], nci_in_equity:bool, extraordinary_items:bool, goodwill_treatment:'offset_or_capitalise'|'capitalise_impairment_only'|'capitalise_amortise', cash_flow_method:'indirect', cash_flow_reference, translation_reserve_label_key, nci_label_key}, balance_sheet?:{sections:[{code:'assets'|'liabilities'|'equity', label_key, lines:[{account_code, account_name, standalone, eliminations, consolidated}], total:{standalone, eliminations, consolidated}}] (in `presentation.balance_sheet_order`), totals:{total_assets, total_liabilities, total_equity, translation_reserve, non_controlling_interests, equity_attributable_to_owners, total_liabilities_and_equity, is_balanced}}, income_statement?:{layout, sections:[{code:'revenue'|'cogs'|'operating_expenses'|'value_of_production'|'cost_of_production', label_key, lines, total:{...}}], totals:{total_revenue, gross_profit, income_tax, net_income, net_income_attributable_to_non_controlling_interests, net_income_attributable_to_owners}}, cash_flow?: CashFlow}`
  Section labels: `intercompany.statements.section.<code>`. The equity section carries a synthetic line `account_code:'CONS-RESULT'` (result of the period) — label with `intercompany.statements.result`.
- GET `/runs/{runId}/cash-flow` → `CashFlow = {run_id, currency, method:'indirect', opening_run_id|null, opening_available:bool, operating, investing, financing, fx_effect, net_change, opening_cash, closing_cash, unreconciled, lines:[{code, section:'operating'|'investing'|'financing'|'fx', amount}], unclassified_accounts:string[]}`; line labels `intercompany.statements.cashflow.line.<code>`, totals `intercompany.statements.cashflow.<key>`.

## Manual adjustments (rettifiche manuali, overlay on the run; refused with 409 `run_locked` while the run is locked/posted)
- GET `/runs/{runId}/manual-adjustments` → `ManualAdjustment[]`
- POST `/runs/{runId}/manual-adjustments` body `{reference (required), reason (min 10), lines:[{entity_tenant_id?, account_code, account_name?, account_type:'asset'|'wip'|'liability'|'equity'|'revenue'|'cogs'|'expense', debit?, credit?}] (min 2, balanced, one side per line)}` → 201
- DELETE `/manual-adjustments/{id}` → voids it (row kept)
`ManualAdjustment = {id, run_id, reference, reason, status:'active'|'void', amount, lines:[...], created_at}`

## Packages (fascicolo di bilancio consolidato)
- GET `/runs/{runId}/packages` → `Package[]` (without `payload`/`sections`) ; POST `/runs/{runId}/packages` body `{standard}` → 201 `Package`
- GET `/packages/{id}` → `Package` **with** `payload` and `sections`
- GET `/packages/{id}/download?format=json|csv|xlsx` → file (attachment)
`Package = {id, run_id, standard, checksum, generated_at, sections:string[] (only on show), payload|null (only on show)}`; `payload = {meta:{standard, run_id, run_version, currency, period_start, period_end, status, group_id}, balance_sheet, income_statement, cash_flow, presentation, shareholdings, nci_schedule, reciprocal_reconciliation: {…existing GL report: rows:[{relation_id, mapping_type, entity_a:{name, account_code, group_amount…}, entity_b:{…}, difference, status}], summary}, elimination_ledger, translation:{translation_reserve, rates}}`. Section titles: `intercompany.package.section.<key>`.

## Notes for the client
- All write endpoints need the finance role of the owner tenant (`owner|admin|accountant`); read endpoints also `accountant_readonly`. 403 when the user cannot read the ledger of every member entity.
- Mirror documents are supplier documents in the counterparty's books: `draft` = registered, not posted to the ledger; `confirmed` = posted. `transactions/{id}` returns `events` (sync log, newest last).
- Concurrency: send `expected_version` taken from the last read of the transaction/netting/run; 409 `stale_version` means reload.
