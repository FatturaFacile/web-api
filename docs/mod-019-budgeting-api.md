# Mod 019 — Budgeting, spend lock and variance analysis (API)

Module `modules/53_Budgeting`, prefix `/api/v1/budgeting`, middleware `api, auth:sanctum, tenant, locale, module.access:budgeting`.
Every member reads; the roles with write access of `config/permissions.php` (`budgeting`) change data. Amounts are strings with two
decimals (quantities three, unit prices four), net of tax and in the currency of the budget version.

## Version family

| Type (`version_type`) | Meaning |
| --- | --- |
| `budget` | Original budget. Once approved it is never reopened or edited (reject is refused). |
| `revised` | Formal revision (`POST /versions/{id}/revise`, reason mandatory, one open revision at a time). Approved through the same submit/approve workflow, only while its baseline is approved or locked. The latest approved revision governs the spend lock. |
| `forecast`, `rolling_forecast` | Forecasts. A rolling forecast is a window of twelve months from `year_start`: closed months hold actuals, open months the estimates. `POST /versions/{id}/roll-forward` moves the window. |
| `scenario` | `scenario_kind` = `best_case`, `worst_case` or `custom`; built with `POST /versions/{id}/scenario` (`adjustment_percent` scales every amount). |

`granularity` is `monthly` (12 columns) or `quarterly` (4 columns). Lines are always stored by month; a quarterly cell is spread
evenly over its three months (the cents go to the last month).

| Endpoint | Purpose |
| --- | --- |
| `GET/PUT /versions/{id}/grid` | Grid read / save cells (`account_id`, `cost_center_id`, `project_id`, `slot`, `amount`, `quantity`, `unit_price`). |
| `POST /versions/{id}/revise` · `/scenario` · `/transfer` · `/roll-forward` | Revision, scenario, transfer between lines, rolling roll-forward. |
| `GET /versions/{id}/audit` | Audit trail (revisions, grid saves with before/after, transfers, imports, scenarios, roll-forwards). |
| `GET /versions/{id}/import-template` · `POST /versions/{id}/import` · `GET /versions/{id}/imports` | Excel/CSV import (see below). |
| `GET /versions/{id}/executive-pack?through_period=` | Executive pack workbook (10 sheets, translated). |

## Variance

`GET /versions/{id}/variance?through_period&group_by=account|cost_center|project|period&account_id&cost_center_id&project_id`
returns budget, actual, variance (actual - budget), percent, `is_favorable` and `traffic_light` (`green`, `amber`, `red`) per row plus the
`thresholds`. The light is green when favourable or when the adverse variance is within the amber threshold (percent of the budget),
amber up to the red threshold, red beyond (default 5 / 10, `PUT /spend-control/settings`).

`GET /versions/{id}/variance/decomposition` splits it cell by cell (account x cost centre x project) and adds the cells up:

```
Budget price    = Budget amount / Budget quantity
Volume variance = (Actual quantity - Budget quantity) x Budget price
Price variance  = Actual amount - Actual quantity x Budget price   (= (Actual price - Budget price) x Actual quantity)
Volume + Price  = Actual amount - Budget amount
```

The budget quantity comes from the lines (every line of the cell must carry one), the actual quantity from `POST /actual-volumes`
(same account, cost centre and project, per month), the actual amount from the ledger. A cell without both quantities is reported
as `undecomposed_variance` and its monetary variance is left as it is.

## Spend lock

`Available = Budget - (Actual costs + open commitments)` over the fiscal year of the document date (`GET /spend-control/availability`).
The budget is the latest approved revision, else the approved original budget; actuals are the posted ledger amounts of the expense
accounts the budget covers; commitments are open requisitions and orders. `POST /spend-control/check` is a what-if (nothing is logged).

**Rules** (`/spend-control/rules`): `mode` = `allow` | `warning` | `hard_block`, `threshold_percent` of the budget, optional `account_id`,
`cost_center_id`, `project_id`, `document_types` (`requisition`, `purchase_order`) and `stages` (`create`, `submit`, `approve`). The most
specific active rule wins, then the highest priority, then the strictest mode. Without a budget nothing is controlled unless
`enforce_without_budget` is set. Purchasing documents carry a cost centre and a project but no ledger account, so account-scoped rules
apply through the check endpoint and to documents of other modules that pass an account.

**Commitments** (`/spend-control/commitments`): written when a requisition or an order is approved (net amount, converted with the exchange
rate of the order), consumed by the larger of received, invoiced and cancelled quantity per line, released on cancellation. An order
replaces its requisition up to its own amount, so nothing is counted twice, and the requisition counts again if the order is cancelled.
`php artisan budgeting:reconcile-commitments` (nightly) repairs any drift.

**Overrides (deroga)** (`/spend-control/overrides`): a member requests, an approver (`owner`, `admin`, or `budgeting.override.approve`) approves
(optionally a lower `approved_amount` and `valid_until`) or rejects (comment mandatory); nobody decides their own request. A `document`
override lets one requisition or order through once; an `allowance` covers an account, cost centre or project until used up or expired.
`GET /spend-control/checks` is the log of warnings, blocks and overridden blocks; `/overrides/{id}/history` the audit trail.
A blocked document answers 422 with `errors.budget` (field `budget`).

## Import

Long format, one row per cell: `account_code, cost_center_code, project_number, period, amount, quantity, unit_price, notes`
(`period` is 1-12, or 1-4 for a quarterly version). `.xlsx` and `.csv` (`;` or `,`, decimal comma accepted) up to 5000 rows; `.xls` is
refused. The whole file is validated first and every error is reported with its row; the import is all or nothing. `mode=replace` empties the
version, `merge` overwrites the cells of the file, `validate_only=1` checks without importing.

## Permissions

`budgeting.approve` (versions), `budgeting.control.manage` (rules, thresholds, actual volumes; owner, admin, accountant), `budgeting.override.approve`
(override decisions; owner, admin).
