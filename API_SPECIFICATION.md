# FatturaFacile ERP — API Specification (v1)

## 1. Global Headers

| Header            | Required           | Type   | Example / Values         | Description                        |
|-------------------|--------------------|--------|--------------------------|------------------------------------|
| `Authorization`   | Yes (except login) | String | `Bearer 1\|abcdef123...` | Sanctum Personal Access Token      |
| `X-Tenant-Id`     | Yes                | UUID   | `019488a0-7b21-...`      | Active enterprise tenant context   |
| `Accept-Language` | Optional           | String | `it`, `de`, `fr`, `en`   | Dynamic API localization           |
| `Accept`          | Yes                | String | `application/json`       | Enforces JSON response envelope    |
| `Content-Type`    | Conditional        | String | `application/json`       | Required for POST/PUT/PATCH bodies |

---

## 2. API Endpoints Catalog

### 2.1 Authentication (`/api/v1/auth`)

| Method | Endpoint                     | Description                                                 |
|--------|------------------------------|-------------------------------------------------------------|
| `POST` | `/api/v1/auth/login`         | Authenticate user and issue Sanctum token with tenant list. |
| `POST` | `/api/v1/auth/logout`        | Revoke active access token.                                 |
| `POST` | `/api/v1/auth/refresh`       | Rotate and issue a fresh access token.                      |
| `GET`  | `/api/v1/auth/profile`       | Retrieve authenticated user profile and roles.              |
| `POST` | `/api/v1/auth/switch-tenant` | Switch the active organization context.                     |

### 2.2 Dashboard & Financial Intelligence (`/api/v1/dashboard`)

| Method | Endpoint                               | Description                                            |
|--------|----------------------------------------|--------------------------------------------------------|
| `GET`  | `/api/v1/dashboard/kpi-summary`        | Executive financial health, turnover, receivables.     |
| `GET`  | `/api/v1/dashboard/cash-flow-forecast` | 30-60-90 days cash inflow projection.                  |
| `GET`  | `/api/v1/dashboard/tax-deadlines`      | Upcoming statutory deadlines (Liquidazione IVA, MWST). |

### 2.3 CRM & Contacts (`/api/v1/crm`)

| Method   | Endpoint                    | Description                                             |
|----------|-----------------------------|---------------------------------------------------------|
| `GET`    | `/api/v1/crm/clients`       | List clients with addresses and payment terms.          |
| `GET`    | `/api/v1/crm/suppliers`     | List suppliers.                                         |
| `GET`    | `/api/v1/crm/contacts`      | List contacts with full-text search.                    |
| `POST`   | `/api/v1/crm/contacts`      | Create a new cross-border contact with billing address. |
| `GET`    | `/api/v1/crm/contacts/{id}` | Contact details.                                        |
| `PUT`    | `/api/v1/crm/contacts/{id}` | Update contact.                                         |
| `DELETE` | `/api/v1/crm/contacts/{id}` | Soft-delete contact.                                    |

### 2.4 Sales, Invoicing & Cross-Border Billing (`/api/v1/sales`)

| Method | Endpoint                                       | Description                                                           |
|--------|------------------------------------------------|-----------------------------------------------------------------------|
| `GET`  | `/api/v1/sales/invoices`                       | List invoices with status, jurisdiction, and search filters.          |
| `POST` | `/api/v1/sales/invoices`                       | Create invoice with automatic tax calculations and 0.05 CHF rounding. |
| `GET`  | `/api/v1/sales/invoices/{id}`                  | Full invoice details with lines and metadata.                         |
| `POST` | `/api/v1/sales/invoices/{id}/generate-sdi-xml` | Generate Italian SDI electronic invoice XML (FatturaPA v1.2.2).       |
| `POST` | `/api/v1/sales/invoices/{id}/generate-qr-bill` | Generate Swiss QR-Bill data structure, SCOR, and SVG slip.            |
| `GET`  | `/api/v1/sales/invoices/{id}/download-qr-bill` | Download Swiss QR-Bill as SVG file attachment.                        |

### 2.5 Purchasing & Accounts Payable (`/api/v1/purchases`)

| Method | Endpoint                                          | Description                                                   |
|--------|---------------------------------------------------|---------------------------------------------------------------|
| `GET`  | `/api/v1/purchases/requests`                      | List internal purchase requests.                              |
| `GET`  | `/api/v1/purchases/orders`                        | List purchase orders to suppliers.                            |
| `GET`  | `/api/v1/purchases/passive-invoices`              | List incoming supplier invoices.                              |
| `POST` | `/api/v1/purchases/passive-invoices/import-sdi`   | Upload and parse Italian FatturaPA XML into supplier invoice. |
| `POST` | `/api/v1/purchases/passive-invoices/scan-qr-bill` | Scan and decode Swiss QR Code payload into passive invoice.   |
| `POST` | `/api/v1/purchases/export-pain001`                | Export payment batch to ISO 20022 `pain.001` credit transfer. |

### 2.6 Treasury & Banking (`/api/v1/treasury`)

| Method | Endpoint                                     | Description                                                   |
|--------|----------------------------------------------|---------------------------------------------------------------|
| `GET`  | `/api/v1/treasury/bank-accounts`             | List tenant bank accounts with IBAN and balances.             |
| `GET`  | `/api/v1/treasury/transactions`              | List imported bank transaction history.                       |
| `POST` | `/api/v1/treasury/reconciliation/auto-match` | Automatically match transactions via SCOR or invoice number.  |
| `POST` | `/api/v1/treasury/import-camt`               | Ingest ISO 20022 `camt.053` or `camt.054` bank statement XML. |
| `POST` | `/api/v1/treasury/import-cbi`                | Parse Italian CBI bank statement files.                       |

### 2.7 Inventory & Logistics (`/api/v1/inventory`)

| Method | Endpoint                           | Description                                                  |
|--------|------------------------------------|--------------------------------------------------------------|
| `GET`  | `/api/v1/inventory/products`       | List catalog items with customs HS codes and prices.         |
| `POST` | `/api/v1/inventory/products`       | Create catalog item.                                         |
| `GET`  | `/api/v1/inventory/warehouses`     | List storage facilities and locations.                       |
| `GET`  | `/api/v1/inventory/movements`      | Stock movement audit trail (in, out, transfer, adjustment).  |
| `GET`  | `/api/v1/inventory/delivery-notes` | Delivery transport documents (DDT) and customs declarations. |

### 2.8 Service Tickets & Field Assistance (`/api/v1/field-service`)

| Method | Endpoint                                      | Description                                     |
|--------|-----------------------------------------------|-------------------------------------------------|
| `GET`  | `/api/v1/field-service/tickets`               | Support and maintenance tickets.                |
| `GET`  | `/api/v1/field-service/work-orders`           | Technician intervention reports (*Rapportini*). |
| `POST` | `/api/v1/field-service/work-orders/{id}/sign` | Record digital client signature on work order.  |

### 2.9 Automotive & Tire Center (`/api/v1/automotive`)

| Method | Endpoint                                             | Description                                                 |
|--------|------------------------------------------------------|-------------------------------------------------------------|
| `GET`  | `/api/v1/automotive/vehicles`                        | Registered customer vehicles (IT and CH cantonal plates).   |
| `POST` | `/api/v1/automotive/tire-hotel/check-in`             | Store seasonal tire set with tread depth and rack location. |
| `POST` | `/api/v1/automotive/tire-hotel/{id}/check-out`       | Mark stored tires as fitted or disposed.                    |
| `GET`  | `/api/v1/automotive/tire-hotel/seasonal-switch-plan` | Customer call-back schedule for spring/autumn changes.      |
| `GET`  | `/api/v1/automotive/inspections/due-list`            | Vehicles due for IT Revisione or Swiss MFK test.            |
| `POST` | `/api/v1/automotive/inspections/{id}/complete`       | Record inspection pass/fail certificate.                    |

### 2.10 Fleet Rental (`/api/v1/rent`)

| Method | Endpoint                            | Description                                                         |
|--------|-------------------------------------|---------------------------------------------------------------------|
| `GET`  | `/api/v1/rent/vehicles`             | Rental fleet status, rates, and availability.                       |
| `GET`  | `/api/v1/rent/bookings`             | Rental reservations.                                                |
| `POST` | `/api/v1/rent/check-in-inspection`  | Check-in vehicle inspection with fuel, mileage, and damage markers. |
| `POST` | `/api/v1/rent/check-out-inspection` | Return vehicle condition inspection.                                |

### 2.11 Construction & Craftsmen (`/api/v1/construction`)

| Method | Endpoint                                       | Description                                                   |
|--------|------------------------------------------------|---------------------------------------------------------------|
| `GET`  | `/api/v1/construction/sites`                   | Construction projects with SIA / NPK norms.                   |
| `GET`  | `/api/v1/construction/sal`                     | Progress billings (Stato Avanzamento Lavori).                 |
| `POST` | `/api/v1/construction/sal`                     | Register certified SAL with contractual retention deduction.  |
| `GET`  | `/api/v1/construction/conformity-certificates` | Certificates of conformity (IT DiCo/DiRi and Swiss NIV/OIBT). |

### 2.12 Professional Practices (`/api/v1/professionals`)

| Method | Endpoint                          | Description                                                         |
|--------|-----------------------------------|---------------------------------------------------------------------|
| `GET`  | `/api/v1/professionals/cases`     | Legal and tax advisory case files (*Fascicoli*).                    |
| `GET`  | `/api/v1/professionals/time-logs` | Billable hours records.                                             |
| `POST` | `/api/v1/professionals/time-logs` | Log billable activity and rate.                                     |
| `GET`  | `/api/v1/professionals/fee-notes` | Professional fee notes (*Parcelle*).                                |
| `POST` | `/api/v1/professionals/fee-notes` | Issue fee note calculating Cassa Previdenza and Ritenuta d'acconto. |

### 2.13 Manufacturing MRP (`/api/v1/manufacturing`)

| Method | Endpoint                                       | Description                                       |
|--------|------------------------------------------------|---------------------------------------------------|
| `GET`  | `/api/v1/manufacturing/bom`                    | Multi-level Bills of Materials (Distinta Base).   |
| `GET`  | `/api/v1/manufacturing/production-orders`      | Manufacturing work orders.                        |
| `POST` | `/api/v1/manufacturing/stages/{stageId}/track` | Record actual stage hours and operational status. |

### 2.14 Retail & POS (`/api/v1/retail`)

| Method | Endpoint                      | Description                                                    |
|--------|-------------------------------|----------------------------------------------------------------|
| `GET`  | `/api/v1/retail/pos/sessions` | Cash register opening/closing sessions.                        |
| `POST` | `/api/v1/retail/pos/checkout` | Process retail sales transaction.                              |
| `POST` | `/api/v1/retail/pos/rt-sync`  | Transmit electronic fiscal receipt to Registratore Telematico. |

### 2.15 Time & Attendance (`/api/v1/time-attendance`)

| Method | Endpoint                                   | Description                                                   |
|--------|--------------------------------------------|---------------------------------------------------------------|
| `GET`  | `/api/v1/time-attendance/entries`          | List time attendance entries.                                 |
| `POST` | `/api/v1/time-attendance/clock-in`         | Clock in with optional coordinates (no geofence enforcement). |
| `POST` | `/api/v1/time-attendance/clock-out`        | Clock out and record rest breaks.                             |
| `POST` | `/api/v1/time-attendance/break-start`      | Append a break-start event to the current user's open shift.   |
| `POST` | `/api/v1/time-attendance/break-end`        | Append a break-end event and recalculate completed breaks.     |
| `GET`  | `/api/v1/time-attendance/compliance-check` | Audit compliance against IT D.Lgs. 66/2003 or Swiss ArG.      |
| `GET`  | `/api/v1/time-attendance/monthly-report`   | Comprehensive monthly attendance and labor compliance report. |
| `GET`  | `/api/v1/time-attendance/annual-report`    | Annual attendance totals and twelve monthly summaries.        |

#### Break Events

Both endpoints require authentication and tenant membership. They always operate
on the authenticated user's open shift in the active tenant; clients cannot
select a different employee or shift through the request body.

```http
POST /api/v1/time-attendance/break-start
Content-Type: application/json
X-Tenant-Id: <tenant UUID>

{"occurred_at":"2026-11-02T12:00:00Z"}
```

```http
POST /api/v1/time-attendance/break-end
Content-Type: application/json
X-Tenant-Id: <tenant UUID>

{"occurred_at":"2026-11-02T12:30:00Z"}
```

- `occurred_at` is optional and defaults to server time. Explicit timestamps are
	accepted, including backdated or future values, subject to chronological checks;
	these endpoints do not implement an approved correction workflow.
- Successful requests return `201` with the updated `TimeEntryResource` in the
	standard envelope. Entry/exit responses, entry listings and monthly reports
	now include `is_on_break` and an ordered `events` array.
- Events contain `id`, progressive `sequence`, `type` (`break_start` / `break_end`),
	`occurred_at`, server-side `recorded_at` and `recorded_by`. They are stored in
	`time_entry_events`; this release provides no event update/delete endpoint.
- Valid flow: clock in, start/end one or more breaks, then clock out. Starting an
	already open break, ending without a start or clocking out during a break
	returns `409`. No open shift returns `422`.
- Break timestamps cannot precede clock-in or the previous event. Clock-out
	cannot precede the last event. Invalid dates or ordering return `422`.
- Completed break durations are summed in seconds and converted to whole minutes
	by rounding down the combined total. The original event timestamps are retained.
	An ongoing break is visible but is not yet included in `break_minutes`.
- When break events exist, `clock-out` must omit non-null `break_minutes`: events
	are authoritative, and manual overrides return `422`. Shifts without events
	retain the legacy manual-minute behavior. Existing shifts are not backfilled.
- Entry/exit/break writes use database transactions and serialize by locking the
	user row; event sequence numbers are also unique per shift. Recognized retries
	replay their original response before state transitions are evaluated.
- These events currently cover pauses only. Entry/exit remain fields on the shift;
	immutable audit, approved corrections, retention policies and HR synchronization
	are separate work items, not guarantees of this release.

#### Idempotent Attendance Requests

All four mutation endpoints (`clock-in`, `clock-out`, `break-start`, `break-end`)
accept an optional `Idempotency-Key` header or `idempotency_key` JSON field. The
header takes precedence. Keys must contain 1-128 ASCII letters, digits or the
characters `.`, `_`, `:` and `-`; a UUID is suitable.

```http
POST /api/v1/time-attendance/break-start
Idempotency-Key: 8eef0614-e5ab-41e1-9af0-245d8c99e77b
Content-Type: application/json
X-Tenant-Id: <tenant UUID>

{}
```

- Generate one key per logical action, and reuse that same key and payload for
	every retry, including network retries. Generate a new key for the next action.
- Keys are scoped to tenant and authenticated user, across all four actions.
	Reusing a key with a different action or meaningful payload returns `409`
	Problem Details (`Idempotency Conflict`); it never changes the original record.
- A recognized retry returns the original HTTP status and JSON body, including
	server-generated timestamps, with `Idempotency-Replayed: true`. This header is
	exposed through CORS. No shift, event, pause minutes or notes are written again.
- The replay is a historical response, not the current shift state. Refresh
	`/entries` before using it as current UI state. Late retries cannot reopen a
	completed shift, restart an old pause or close a subsequently opened shift.
- Without a client key, an explicit `clock_in`, `clock_out` or `occurred_at`
	provides automatic identity by action and normalized UTC timestamp. Identical
	retries replay; changed payload at that same identity returns `409`.
	Equivalent timezone offsets and numeric representations are normalized.
- Without both a key and an explicit timestamp, no reliable request identity is
	available: existing state checks remain, but there is no replay guarantee.
	No fuzzy time window is used to silently discard potentially legitimate work.
- The receipt and mutation commit atomically under a user-row lock. A database
	unique constraint on `(tenant_id, user_id, key_hash)` reinforces uniqueness.
	Failed operations roll back without consuming their request identifier.
- `attendance_requests` stores hashed identifiers, normalized-payload hashes,
	action, original response and creation time. No automatic expiry is configured;
	deleting receipts removes their replay guarantee and needs a retention policy.
- Only requests recorded after this migration are covered; historic events are
	not backfilled. Different explicit client keys identify different requests.

#### Annual Attendance Report

`GET /api/v1/time-attendance/annual-report?year=2026`

- Requires authentication and the active tenant context (`X-Tenant-Id`).
- `year`: optional integer from 2000 to 2100; defaults to the current year.
- `user_id`: optional user UUID; defaults to the authenticated user. Another
	user's report requires `view-all-attendance`. The selected user must be an
	active (not soft-deleted) member of the current tenant.
- Success uses the standard `success` / `message` / `data` envelope.
	`data` contains `user`, `period`, `summary` and `months`.
- `period` contains `year`, `start_date` and `end_date`. `months` always contains
	twelve objects, ordered January to December, each with `month` and `summary`.
- Each summary contains `total_shifts`, `closed_shifts`, `open_shifts`,
	`gross_hours`, `break_hours` and `net_hours_worked`. Hours are rounded to two
	decimal places. Open shifts are counted but do not contribute hours or breaks.
- Shifts belong entirely to the month/year of `clock_in`, using the application's
	configured timezone, matching the monthly report. A shift starting on December
	31 and ending on January 1 is counted entirely in December. Hours and aggregate
	break minutes are not split at calendar boundaries.
- Annual reports contain no individual entries or annual compliance verdict;
	use the monthly report for entry details and the existing compliance endpoints
	for their limited checks. Contractual overtime, leave balances and payroll
	exports are not calculated by this endpoint.
- Invalid parameters or users outside the tenant return `422`; requesting another
	user's report without the required permission returns `403`.

Monthly reports retain their existing fields and now also expose `closed_shifts`
and `open_shifts` in `summary`. Monthly and annual totals share the same duration
calculation; individual entry `duration_hours` uses a forward time difference.

#### Attendance Class Map

| Component | Responsibility |
|-----------|----------------|
| `app/Http/Controllers/Api/V1/TimeAttendanceController.php` | Eight API actions; delegates mutations and summaries, formats responses. |
| `app/Services/Attendance/AttendanceTrackingService.php` | Transactional clock-in/out, break transitions, locking, chronology and derived pause minutes. |
| `app/Services/Attendance/AttendanceIdempotencyService.php` | Tenant/user-scoped request identity, payload normalization, transactional receipts and original-response replay. |
| `app/Services/Attendance/AttendanceReportService.php` | Shared totals and twelve-month annual aggregation. |
| `app/Services/Attendance/AttendanceComplianceService.php` | Existing partial Italian/Swiss rest, pause and weekly-hours checks; not legal certification. |
| `app/Models/TimeEntry.php` | Tenant/user shift, timestamps, cached pause minutes, notes and ordered event relationship. |
| `app/Models/TimeEntryEvent.php` | Tenant-scoped event with sequence, declared/server time and recorder. |
| `app/Models/AttendanceRequest.php` | Persistent tenant-scoped receipt of a successful attendance mutation. |
| `app/Enums/TimeEntryEventType.php` | Supported break-start and break-end values. |
| `app/Exceptions/AttendanceStateException.php` | Invalid state with title/status, translated to Problem Details by the controller. |
| `app/Http/Requests/ClockInRequest.php` | Entry timestamp, optional coordinates, simplified-record flag and notes validation. |
| `app/Http/Requests/AttendanceMutationRequest.php` | Shared validation of client idempotency keys and header precedence for all attendance mutations. |
| `app/Http/Requests/ClockOutRequest.php` | Exit timestamp, legacy pause minutes and notes validation. |
| `app/Http/Requests/BreakEventRequest.php` | Break timestamp validation and authenticated tenant-context requirement. |
| `app/Http/Requests/AttendanceReportRequest.php` | Report period, jurisdiction and tenant-scoped target-user validation/authorization. |
| `app/Http/Resources/TimeEntryResource.php` | Shift JSON, net duration, pause state and events. |
| `app/Http/Resources/TimeEntryEventResource.php` | Event JSON without exposing the underlying model directly. |

Routes are registered in `routes/api.php`. The original attendance migration
creates `time_entries`, `leave_requests` and `leave_balances`; the separate
`2026_11_02_145205_create_time_entry_events_table.php` migration adds break events.
`tests/Feature/Api/HighImpactControllersTest.php` covers HTTP flows and report
authorization; `tests/Feature/AttendanceComplianceTest.php` covers existing
jurisdiction checks. `database/seeders/TimeEntrySeeder.php` is an empty scaffold.

The HR context is separate: `Modules\HumanResources\Models\AttendanceRecord`
stores employee/day attendance, while `Modules\HumanResources\Services\LeaveAndTimeService`
manages leave, attendance and project timesheets. Its `recordAttendance()` currently
uses fixed 08:30-17:30 times and a 60-minute break; it does not consume these API
events. `AttendanceRecordFactory` supports HR tests, not the operational event stream.
Shared `TenantContext`, `IdentifyTenant`, `BelongsToTenant`, `User` and `ApiResponse`
provide platform tenancy, authentication relationships and response conventions.

### 2.16 Cross-Border HR & Payroll (`/api/v1/hr`)

| Method | Endpoint                       | Description                                                 |
|--------|--------------------------------|-------------------------------------------------------------|
| `GET`  | `/api/v1/hr/employees`         | Employee records and profiles.                              |
| `GET`  | `/api/v1/hr/frontalieri`       | Cross-border commuters subject to the 2024 IT-CH agreement. |
| `GET`  | `/api/v1/hr/withholding-taxes` | Cantonal withholding rates and Italian INPS parameters.     |

### 2.17 Fiscal & Statutory Accounting (`/api/v1/accounting`)

| Method | Endpoint                               | Description                                                |
|--------|----------------------------------------|------------------------------------------------------------|
| `GET`  | `/api/v1/accounting/chart-of-accounts` | Plan of accounts (Italian General Ledger / Swiss KMU-PME). |
| `GET`  | `/api/v1/accounting/ledger-entries`    | General journal entries.                                   |
| `POST` | `/api/v1/accounting/ledger-entries`    | Post balanced double-entry transaction.                    |
| `GET`  | `/api/v1/accounting/vat-settlements`   | Monthly / quarterly VAT declarations.                      |

### 2.18 Settings & Tenancy (`/api/v1/settings`)

| Method | Endpoint                               | Description                                   |
|--------|----------------------------------------|-----------------------------------------------|
| `GET`  | `/api/v1/settings/tenant`              | Active organization configuration.            |
| `PUT`  | `/api/v1/settings/tenant`              | Update organization details.                  |
| `GET`  | `/api/v1/settings/branches`            | Branch locations.                             |
| `POST` | `/api/v1/settings/exchange-rates/sync` | Sync EUR/CHF central bank rates.              |
| `GET`  | `/api/v1/settings/users`               | List tenant members and roles.                |
| `GET`  | `/api/v1/settings/roles`               | System RBAC roles.                            |
| `GET`  | `/api/v1/settings/audit-logs`          | Tamper-evident GDPR / Swiss FADP audit trail. |

---

## 3. Error Codes & Problem Details Catalogue

Errors return HTTP status codes with `Content-Type: application/problem+json` according to RFC 7807:

| Status Code         | Title                                | Scenario                                                         |
|---------------------|--------------------------------------|------------------------------------------------------------------|
| `400 Bad Request`   | Missing Tenant Context               | Missing `X-Tenant-Id` header.                                    |
| `401 Unauthorized`  | Invalid Credentials                  | Missing or invalid Sanctum Bearer token.                         |
| `403 Forbidden`     | Forbidden Tenant Access              | Authenticated user is not a member of the requested tenant.      |
| `404 Not Found`     | Resource Not Found                   | Record does not exist or has been soft-deleted.                  |
| `409 Conflict`      | Already Clocked In                   | Worker attempted to clock in while an active shift was open.     |
| `422 Unprocessable` | Unbalanced Ledger Entry / Validation | FormRequest validation failed or double-entry credits != debits. |
