# Mod 094 — AI cash flow and demand forecasting (Issue #221)

Deterministic forecasting in pure PHP, so the ERP runs it on SQLite and PostgreSQL with no extra service. The engine sits
behind `Domain\Contracts\ForecastingEngineInterface`: bind another implementation (Prophet, SARIMAX, LightGBM service) in
`ForecastingServiceProvider` to replace it.

## Layers

| Layer | Content |
| :--- | :--- |
| `Domain` | `PaymentRiskTier`, `ForecastHorizon`, `JurisdictionCode`, `ConfidenceBand`, `ForecastAccuracyMetrics`; entities `CustomerPaymentBehaviorModel`, `AiLiquidityForecastRun`, `SkuDemandForecast` (safety stock and reorder point formulas); services `StatutoryCashOutflowCalendarService`, `LiquidityProjectionService`, `PaymentDelayStatistics`, `NormalDistribution` |
| `Application` | Commands `TrainCustomerPaymentDelayModel`, `RunAiLiquiditySimulation`, `GenerateSkuDemandForecast`, `ApplyAiRecommendedReorderRules`; queries `GetAiCashFlowConfidenceBands`, `GetSkuForecastVsActualAccuracy`; one handler each, plus two read models for the screens |
| `Infrastructure` | Eloquent models, `ForecastingDataReader` (tenant-scoped reads of invoices, payments, sales, purchases), `HoltWintersForecastingEngine`, `StatutoryProfileResolver`, console commands |
| `Presentation` | Controllers, FormRequests, JSON resources, `routes/api.php` |

## Method

- **Payment behaviour.** Delay = last payment date − due date of every invoice settled in the last 24 months, weighted by
  `0.5 ^ (age_days / 90)`. The weighted mean is the predicted collection offset, so a customer who is getting worse is
  dated by its recent behaviour. Tier: offset > 40 days critical, > 20 high, > 5 moderate, else low. The expected dates of the
  open receivables are stored in `invoice_expected_collections` (issued invoices stay untouched).
- **Liquidity.** P10 collects every invoice at its latest plausible date, P50 at the expected date, P90 at the earliest, so
  `P10 <= P50 <= P90` holds on every day. Payables, recurring items and the statutory calendar are certain. What-if:
  customer delay shift, receipts variance, supplier payment extension, interest on a negative balance.
- **Statutory calendar.** IT: F24 on the 16th, LIPE on May 16 / Aug 20 / Nov 16 / Mar 16, VAT advance Dec 27, income tax
  advances Jun 30 (40%) and Nov 30 (60%), 13th salary in December, 14th in June. CH: MWST on May 31 / Aug 31 / Nov 30 / end of
  February, AVS/LPP installments and reconciliation, 13th salary in November or December. A weekend date rolls to Monday.
  Amounts come from the payroll runs and VAT settlements of the tenant; `settings.forecasting` overrides them.
- **Demand.** Multiplicative Holt-Winters on 24 monthly buckets, compared with a mean model on the same one-step errors.
  `SS = Z * sqrt(LT * sigma_D^2 + D^2 * sigma_LT^2)` and `ROP = D * LT + SS`, with D and sigma_D per day (a month is 30 days) and
  LT, sigma_LT measured from purchase order date to goods receipt date. Z = 1.65 (95%).

## API and access

`/api/v1/forecasting/...` (payment-models, liquidity/simulate, liquidity/confidence-bands, demand/forecasts, demand/generate,
demand/accuracy, demand/apply-reorder-rules). Module `forecasting` (`forecasting.view` reads, `forecasting.simulate` writes) and
module `forecasting_wms` (`forecasting.apply_wms`) in `config/permissions.php`.

Nightly: `forecasting:train-payment-models` (03:00), `forecasting:refresh-liquidity` (03:20), `forecasting:generate-sku-demand` (03:40).

## Verification

```bash
scripts/verify_mod094_e2e.sh [path-to-web-frontend]
```

runs `php artisan forecasting:verify-e2e` on a throw-away SQLite file: it seeds `AiForecastingDemoSeeder` (Helvetia Precision AG in
CHF, Meccanica Lombarda Srl in EUR), trains, simulates 91 and 365 days, checks the statutory dates and a what-if, forecasts, checks
the safety stock against the analytical value, applies the reorder rules, proves tenant isolation over HTTP and, with the
frontend path, runs `npm run build` there.
