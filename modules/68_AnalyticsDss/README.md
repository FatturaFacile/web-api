# Executive DSS and analytical data mart (Mod 093, Issue #214)

Star-schema analytical layer and strategic decision support engine of the executive cockpit (Domain 11). It feeds and extends
the ad-hoc report builder (#215, `modules/20_Analytics`) and the role-based KPI dashboards (Mod 090,
`modules/56_BusinessIntelligence`); it does not duplicate their services or routes.

## Layers

| Layer | Content |
| :--- | :--- |
| `Domain` | Pure calculators with no I/O: `ProfitabilityMatrixCalculator` (CM I/II, EBITDA, net operating profit, Pareto, Whale Curve), `CashConversionCycleCalculator`, `VarianceDecompositionEngine`, `WhatIfScenarioSimulator`, `ExecutiveAnomalyDetector`, `HolidayCalendar`, value objects, enums |
| `Application` | CQRS queries/command and their handlers, `ExecutiveFigures` |
| `Infrastructure` | Eloquent mart models, `MartProjector` (incremental), `MartReader`, `MartReconciler`, `UpdateAnalyticalMartJob`, the event subscriber, `bi:consolidate-marts`, board pack renderer, tenant cache |
| `Presentation` | Thin controllers, FormRequests, OpenAPI annotations |

The module lives under `modules/` like every other domain module of the monolith (not under `App\Domain`).

## Data mart (`database/migrations/2027_12_16_000100_create_analytics_dss_tables.php`)

`dim_date`, `dim_legal_entity`, `dim_counterparty`, `dim_product_service`, `dim_cost_center`, `fact_financial_transactions`,
`fact_commercial_velocity`, `fact_operational_efficiency`, `agg_executive_daily_snapshots`. Every table has a nullable
`tenant_id`; a tenant without company rows is its own single legal entity (`legal_entity_id` = tenant id).

* Ledger sign: `fact_financial_transactions.amount_*` is debit minus credit; the readers flip revenue and payables to natural sign.
* Each line carries the transaction currency amount, the accounting (functional) amount and the CHF and EUR amounts translated at the
  last `exchange_rates` row on or before the posting date (direct, inverse or crossed through EUR).
* Categories (`revenue`, `cogs`, `opex`, `depreciation`, `interest`, `tax`, `cash`, `receivable`, `payable`, `inventory`, `balance`) come
  from the chart of accounts type, the reconciliation type and the code prefixes of `config/analytics_dss.php`. Adjust the prefixes to
  the chart of accounts of the tenant.

## Projection

`JournalPostedEvent`, `InvoicePostedEvent`, `InvoicePaidEvent`, `CollectionReceivedEvent`, `ProjectTimesheetApprovedEvent` and
`StockMovementRecorded` enqueue `UpdateAnalyticalMartJob` on the `analytics` queue. The job rebuilds the facts of one document
(delete and insert, so a retry never doubles a figure), refreshes the daily snapshots it touched and flushes the tenant cache.

```bash
php artisan bi:consolidate-marts --tenant=<id>            # rebuild from the operational tables
php artisan bi:consolidate-marts --reconcile              # also prove the mart against the ledger; exit code 1 on any variance
```

Reconciliation compares, per account, the posted ledger balance (`debit - credit` in the accounting currency) with the sum of the facts.

## API (`/api/v1/analytics/dss`)

| Endpoint | Ability |
| :--- | :--- |
| `GET overview`, `profitability-matrix`, `variance-bridge`, `drill-down`, `legal-entities` | `bi.dss.view` |
| `POST simulate` | `bi.dss.simulate` |
| `POST export-board-pack` | `bi.dss.export` |
| `GET admin/reconciliation`, `POST admin/consolidate` | `bi.dss.admin` |

All endpoints take the global filter: `legal_entity_ids[]`, `cost_center_ids[]`, `currency` (CHF/EUR), `period_preset`
(MTD/QTD/YTD/L12M/CUSTOM with `date_from`/`date_to`), `comparison_baseline` (PRIOR_PERIOD/PRIOR_YEAR/BUDGET) and `as_of`.

### Notes for the other clients

* The what-if formulas are documented on `WhatIfScenarioSimulator`; the desktop and mobile calculators must reproduce them. The response
  of `simulate` returns the `inputs` block so an offline client can run the same computation locally.
* Alerts carry a stable `code` and numeric `context`; clients translate the code. The board pack labels live in `lang/*/analytics_dss.php`.
* The variance bridge uses invoice line quantities as units. Without them it falls back to one notional unit (`volume_basis` =
  `unit_fallback`) and the whole revenue change is a price effect. A budget has no units, so its volume is the actual one.
* "On-time delivery %" has no data source yet: the KPI is returned with `available: false`.
