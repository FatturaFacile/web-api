# 86_Distribution — Distribution, multi-warehouse and transfers (DRP), Issue #271

API under `/api/v1/distribution` (module key `distribution`, middleware `module.access:distribution`).

## Design decisions

- **One transfer aggregate.** Transfers keep living in the inventory `TransferOrder` (state machine, transit bin of the destination
  warehouse, lot-aware picking, discrepancies). `TransferWorkflowService` adds the network guards (blocked / counted warehouses),
  the lane lead time, the transport data (`distribution_transfer_meta`: carrier, tracking, DDT, expected arrival) and the receipt
  resolution (`write_off`, `return_to_source`, `carrier_claim`, `keep_in_transit`) so in-transit stock never stays orphaned.
  Approving a transfer does not reserve stock (the inventory aggregate checks and picks only at dispatch); the source availability
  preview (`GET availability`) and the DRP committed issues cover the planning side.
- **In-transit stock** is the quantity shipped and not yet received or reported damaged (`StockPositionService::inTransitInbound`),
  queryable per item, warehouse pair, lot, arrival date and overdue (`GET in-transit`).
- **Network profile** (`distribution_warehouse_profiles`) adds the role (internal, point of sale, transit, 3PL, consignment out / in),
  the supplying hub, the third party and the consignment terms to the existing warehouses; warehouses without a profile derive their
  role from the legacy `warehouse_type`.
- **Replenishment** (`ReplenishmentEngine`): min-max, reorder point with fixed quantity, periodic review and demand-driven DRP. The
  projected demand is the higher of the consumption forecast (outbound `dispatch` / `pick` movements of the last 60 days) and the open
  sales orders due within the horizon. A shortage is covered first by lateral balancing (peer warehouses above their upper level that a
  lane connects, shortest lead time first), then by the supplying hub; what the hub cannot cover is flagged (`shortfall`) and announced
  with `ReplenishmentShortfallDetected` for Procurement / MRP.
- **DRP** (`DrpPlanner`): daily or weekly buckets, gross requirements, scheduled receipts, projected available balance, net
  requirements, planned receipts and releases (offset by the lead time). Warehouses are planned from the stores up to the hubs, so the
  planned releases of a spoke become gross requirements of its hub. Runs are stored as snapshots with alerts (stockout risk, excess
  stock, late inbound). `php artisan drp:run [--tenant=] [--bucket=] [--horizon=] [--proposals]` or `POST drp/runs`; the scheduler runs
  `RunDistributionPlanningJob` every night.
- **Channel ATP** (`ChannelAvailabilityService`): per warehouse the free stock is carved out in a fixed order — fixed reserves, then
  percentages, then priority ranks, then the shared pool — so no unit is promised twice. Consignment and 3PL warehouses are excluded
  unless the rule asks for them; transit warehouses and blocked warehouses never count.
- **Consignment** (`ConsignmentService`): our stock at third parties (`consignment_out`) and third-party stock held by us
  (`consignment_in`), consumption settlements (stock issued, settlement `open` until invoiced), returns as a transfer, and the 3PL stock
  reconciliation.

## Texts

Interface texts live in `database/seeders/Translations/DistributionFrontendTranslations.php` (it, en, de, fr); API messages in
`lang/*/distribution.php`.
