# MRP I & II — Material and Capacity Requirements Planning (Mod 063)

Multi-level net requirements, pegging and finite-capacity scheduling. Namespace `Modules\MrpPlanning`, tables `mrp_*`,
routes `api/v1/mrp`, permission modules `mrp_planning` (read: every member; write: production planners and admins) and
`mrp_release` (firm / release / dismiss), config `config/mrp_planning.php`.

The older regenerative run of the manufacturing module (`api/v1/manufacturing/mrp`, `mfg_mrp_*`) is untouched; this module
reads the same masters (items, BOM, routing, work centres) and adds the time-phased engine, the proposal workbench, the
pegging graph and the Gantt.

## Layers

| Layer | Content |
| :--- | :--- |
| `Domain` | enums, value objects, the pure engine (`MrpCalculationService`, `LowLevelCodeCalculator`, `LotSizingCalculator`, `PlanningCalendar`, `CapacityScheduler`, `PeggingTreeBuilder`) and the ports (`MrpSnapshotLoaderInterface`, `MasterScheduleGatewayInterface`, `ReleaseGatewayInterface`). No database access. |
| `Application` | CQRS: commands `ExecuteMrpPlanningRun`, `AutoLevelFiniteCapacitySchedule`, `RescheduleGanttBlock`, `FirmAndReleaseMrpProposals`; queries `ListMrpRuns`, `GetMrpRunSummary`, `ListMrpProposals`, `GetMrpPeggingTree`, `GetGanttCapacitySchedule`; one handler each. |
| `Infrastructure` | Eloquent models, `EloquentMrpSnapshotLoader` (reads the upstream modules), `MrpPlanPersister` (proposals, blocks, capacity buckets), the release and MPS adapters. |
| `Presentation` | thin controller, FormRequests, JsonResources. |

## Calculation

* Low-level code by topological order; a cyclic BOM raises `BomCycleException` with the path and the run ends `Failed`.
* Per item and day: `projected(t) = projected(t-1) + receipts(t) - gross(t)`, starting at `on hand - allocated`;
  `net(t) = max(0, safety - projected(t))`. The safety stock is a floor, so it is never counted twice.
* Lot sizing after scrap: `LotForLot`, `MinimumOrderQuantity`, `OrderMultiple`, `EconomicOrderQuantity`, `FixedDaysOfSupply`.
  The scrap percentage is added to the need first (so MOQ and multiples hold on the quantity actually ordered); the good
  output (`order / (1 + scrap)`) is what the projection is credited with.
* Dependent demand: a make or subcontract order explodes its start quantity through the BOM (line scrap included) into
  demand of the next level at its release date; phantoms are collapsed by the loader.
* Lead time counts back from the need date, skipping supplier shutdown days (Ferragosto, Swiss factory holidays,
  `mrp_shutdown_periods` + `config/mrp_planning.php`); a release date never falls on a weekend or a shutdown. Customs
  clearance days (the Swiss border, either direction) are taken off the need date before the supplier lead time.
* Advice on open supplies: expedite (needed before it arrives), defer (arrives long before it is needed), cancel excess.
* Capacity: daily buckets. Infinite capacity fills the nominal minutes of each work centre ignoring other loads (stacked
  orders show as overloaded buckets); finite capacity takes only free minutes, tries alternative work centres and falls
  back to a forward pass from today. Firm orders and manual placements are fixed blocks that load the buckets first.

## Edge cases handled

Cycles, shared components (netted once, after all parents), over-allocated stock, demand past due or beyond the horizon,
release dates in the past (`PastDueRelease`), zero-capacity work centres, forward scheduling late deliveries
(`LateDelivery`), all-or-nothing firm/release, tenant isolation on every query, net-change runs that keep unchanged items
(and replace the items downstream of any change).

## Upstream modules and stubs

| Module | Use | State |
| :--- | :--- | :--- |
| Mod 060 BOM / Mod 061 work centres, routing | `MfgItem`, `BillOfMaterialsService`, `RoutingService`, `MfgWorkCenter*` | real |
| Mod 037 sales orders | open `sales_order_items` of confirmed / in-production orders | real |
| Mod 051 inventory / safety stock | `StockQuant`, `StockReservation`, `MfgItem.safety_stock` | real |
| Mod 044 / 046 procurement | open `purchase_order_items`; release creates a draft `PurchaseRequisition` | real |
| Mod 064 production orders | open `MfgProductionOrder`; release uses `ProductionPlanningService::planOrder` | real |
| Mod 062 MPS | `MasterScheduleGateway` reads `mps_lines(id, tenant_id, mfg_item_id, due_date, quantity)` **if the table exists**, otherwise the schedule is empty | **stub** — adapt the gateway when Mod 062 lands |
| In-transit stock | open lines of inventory transfer orders (`in_transit`, `partially_received`) | real |
| Mod 031 customs lead time | per-item `customs_clearance_days` (default `config('mrp_planning.default_customs_days')`) and supplier country in `mrp_item_parameters` | parameters, no customs-module call |

Supplier country, lot-sizing rule, EOQ and fixed days live in `mrp_item_parameters` because the manufacturing item does not
carry them; items without a row keep the item's minimum lot / multiple.

## Offline clients

Responses and the `mrp.run.completed` / `mrp.proposals.released` outbox events carry plain scalars (decimal strings, ISO
dates, uuids) so the WPF and Flutter clients can consume them unchanged.
