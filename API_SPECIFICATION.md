# FatturaFacile ERP — API Specification (v1)

## 1. Global Headers

| Header | Required | Type | Example / Values | Description |
|---|---|---|---|---|
| `Authorization` | Yes (except login) | String | `Bearer 1\|abcdef123...` | Sanctum Personal Access Token |
| `X-Tenant-ID` | Yes | UUID | `019488a0-7b21-...` | Active enterprise tenant context |
| `Accept-Language` | Optional | String | `it`, `de`, `fr`, `en` | Dynamic API localization |
| `Accept` | Yes | String | `application/json` | Enforces JSON response envelope |
| `Content-Type` | Conditional | String | `application/json` | Required for POST/PUT/PATCH bodies |

---

## 2. API Endpoints Catalog

### 2.1 Authentication (`/api/v1/auth`)

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/v1/auth/login` | Authenticate user and issue Sanctum token with tenant list. |
| `POST` | `/api/v1/auth/logout` | Revoke active access token. |
| `POST` | `/api/v1/auth/refresh` | Rotate and issue a fresh access token. |
| `GET` | `/api/v1/auth/profile` | Retrieve authenticated user profile and roles. |
| `POST` | `/api/v1/auth/switch-tenant` | Switch the active organization context. |

### 2.2 Dashboard & Financial Intelligence (`/api/v1/dashboard`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/dashboard/kpi-summary` | Executive financial health, turnover, receivables. |
| `GET` | `/api/v1/dashboard/cash-flow-forecast` | 30-60-90 days cash inflow projection. |
| `GET` | `/api/v1/dashboard/tax-deadlines` | Upcoming statutory deadlines (Liquidazione IVA, MWST). |

### 2.3 CRM & Contacts (`/api/v1/crm`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/crm/clients` | List clients with addresses and payment terms. |
| `GET` | `/api/v1/crm/suppliers` | List suppliers. |
| `GET` | `/api/v1/crm/contacts` | List contacts with full-text search. |
| `POST` | `/api/v1/crm/contacts` | Create a new cross-border contact with billing address. |
| `GET` | `/api/v1/crm/contacts/{id}` | Contact details. |
| `PUT` | `/api/v1/crm/contacts/{id}` | Update contact. |
| `DELETE` | `/api/v1/crm/contacts/{id}` | Soft-delete contact. |

### 2.4 Sales, Invoicing & Cross-Border Billing (`/api/v1/sales`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/sales/invoices` | List invoices with status, jurisdiction, and search filters. |
| `POST` | `/api/v1/sales/invoices` | Create invoice with automatic tax calculations and 0.05 CHF rounding. |
| `GET` | `/api/v1/sales/invoices/{id}` | Full invoice details with lines and metadata. |
| `POST` | `/api/v1/sales/invoices/{id}/generate-sdi-xml` | Generate Italian SDI electronic invoice XML (FatturaPA v1.2.2). |
| `POST` | `/api/v1/sales/invoices/{id}/generate-qr-bill` | Generate Swiss QR-Bill data structure, SCOR, and SVG slip. |
| `GET` | `/api/v1/sales/invoices/{id}/download-qr-bill` | Download Swiss QR-Bill as SVG file attachment. |

### 2.5 Purchasing & Accounts Payable (`/api/v1/purchases`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/purchases/requests` | List internal purchase requests. |
| `GET` | `/api/v1/purchases/orders` | List purchase orders to suppliers. |
| `GET` | `/api/v1/purchases/passive-invoices` | List incoming supplier invoices. |
| `POST` | `/api/v1/purchases/passive-invoices/import-sdi` | Upload and parse Italian FatturaPA XML into supplier invoice. |
| `POST` | `/api/v1/purchases/passive-invoices/scan-qr-bill` | Scan and decode Swiss QR Code payload into passive invoice. |
| `POST` | `/api/v1/purchases/export-pain001` | Export payment batch to ISO 20022 `pain.001` credit transfer. |

### 2.6 Treasury & Banking (`/api/v1/treasury`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/treasury/bank-accounts` | List tenant bank accounts with IBAN and balances. |
| `GET` | `/api/v1/treasury/transactions` | List imported bank transaction history. |
| `POST` | `/api/v1/treasury/reconciliation/auto-match` | Automatically match transactions via SCOR or invoice number. |
| `POST` | `/api/v1/treasury/import-camt` | Ingest ISO 20022 `camt.053` or `camt.054` bank statement XML. |
| `POST` | `/api/v1/treasury/import-cbi` | Parse Italian CBI bank statement files. |

### 2.7 Inventory & Logistics (`/api/v1/inventory`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/inventory/products` | List catalog items with customs HS codes and prices. |
| `POST` | `/api/v1/inventory/products` | Create catalog item. |
| `GET` | `/api/v1/inventory/warehouses` | List storage facilities and locations. |
| `GET` | `/api/v1/inventory/movements` | Stock movement audit trail (in, out, transfer, adjustment). |
| `GET` | `/api/v1/inventory/delivery-notes` | Delivery transport documents (DDT) and customs declarations. |

### 2.8 Service Tickets & Field Assistance (`/api/v1/field-service`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/field-service/tickets` | Support and maintenance tickets. |
| `GET` | `/api/v1/field-service/work-orders` | Technician intervention reports (*Rapportini*). |
| `POST` | `/api/v1/field-service/work-orders/{id}/sign` | Record digital client signature on work order. |

### 2.9 Automotive & Tire Center (`/api/v1/automotive`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/automotive/vehicles` | Registered customer vehicles (IT and CH cantonal plates). |
| `POST` | `/api/v1/automotive/tire-hotel/check-in` | Store seasonal tire set with tread depth and rack location. |
| `POST` | `/api/v1/automotive/tire-hotel/{id}/check-out` | Mark stored tires as fitted or disposed. |
| `GET` | `/api/v1/automotive/tire-hotel/seasonal-switch-plan` | Customer call-back schedule for spring/autumn changes. |
| `GET` | `/api/v1/automotive/inspections/due-list` | Vehicles due for IT Revisione or Swiss MFK test. |
| `POST` | `/api/v1/automotive/inspections/{id}/complete` | Record inspection pass/fail certificate. |

### 2.10 Fleet Rental (`/api/v1/rent`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/rent/vehicles` | Rental fleet status, rates, and availability. |
| `GET` | `/api/v1/rent/bookings` | Rental reservations. |
| `POST` | `/api/v1/rent/check-in-inspection` | Check-in vehicle inspection with fuel, mileage, and damage markers. |
| `POST` | `/api/v1/rent/check-out-inspection` | Return vehicle condition inspection. |

### 2.11 Construction & Craftsmen (`/api/v1/construction`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/construction/sites` | Construction projects with SIA / NPK norms. |
| `GET` | `/api/v1/construction/sal` | Progress billings (Stato Avanzamento Lavori). |
| `POST` | `/api/v1/construction/sal` | Register certified SAL with contractual retention deduction. |
| `GET` | `/api/v1/construction/conformity-certificates` | Certificates of conformity (IT DiCo/DiRi and Swiss NIV/OIBT). |

### 2.12 Professional Practices (`/api/v1/professionals`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/professionals/cases` | Legal and tax advisory case files (*Fascicoli*). |
| `GET` | `/api/v1/professionals/time-logs` | Billable hours records. |
| `POST` | `/api/v1/professionals/time-logs` | Log billable activity and rate. |
| `GET` | `/api/v1/professionals/fee-notes` | Professional fee notes (*Parcelle*). |
| `POST` | `/api/v1/professionals/fee-notes` | Issue fee note calculating Cassa Previdenza and Ritenuta d'acconto. |

### 2.13 Manufacturing MRP (`/api/v1/manufacturing`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/manufacturing/bom` | Multi-level Bills of Materials (Distinta Base). |
| `GET` | `/api/v1/manufacturing/production-orders` | Manufacturing work orders. |
| `POST` | `/api/v1/manufacturing/stages/{stageId}/track` | Record actual stage hours and operational status. |

### 2.14 Retail & POS (`/api/v1/retail`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/retail/pos/sessions` | Cash register opening/closing sessions. |
| `POST` | `/api/v1/retail/pos/checkout` | Process retail sales transaction. |
| `POST` | `/api/v1/retail/pos/rt-sync` | Transmit electronic fiscal receipt to Registratore Telematico. |

### 2.15 Time & Attendance (`/api/v1/time-attendance`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/time-attendance/entries` | List time attendance entries. |
| `POST` | `/api/v1/time-attendance/clock-in` | Clock in with optional GPS geofencing coordinates. |
| `POST` | `/api/v1/time-attendance/clock-out` | Clock out and record rest breaks. |
| `GET` | `/api/v1/time-attendance/compliance-check` | Audit compliance against IT D.Lgs. 66/2003 or Swiss ArG. |
| `GET` | `/api/v1/time-attendance/monthly-report` | Comprehensive monthly attendance and labor compliance report. |

### 2.16 Cross-Border HR & Payroll (`/api/v1/hr`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/hr/employees` | Employee records and profiles. |
| `GET` | `/api/v1/hr/frontalieri` | Cross-border commuters subject to the 2024 IT-CH agreement. |
| `GET` | `/api/v1/hr/withholding-taxes` | Cantonal withholding rates and Italian INPS parameters. |

### 2.17 Fiscal & Statutory Accounting (`/api/v1/accounting`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/accounting/chart-of-accounts` | Plan of accounts (Italian General Ledger / Swiss KMU-PME). |
| `GET` | `/api/v1/accounting/ledger-entries` | General journal entries. |
| `POST` | `/api/v1/accounting/ledger-entries` | Post balanced double-entry transaction. |
| `GET` | `/api/v1/accounting/vat-settlements` | Monthly / quarterly VAT declarations. |

### 2.18 Settings & Tenancy (`/api/v1/settings`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/settings/tenant` | Active organization configuration. |
| `PUT` | `/api/v1/settings/tenant` | Update organization details. |
| `GET` | `/api/v1/settings/branches` | Branch locations. |
| `POST` | `/api/v1/settings/exchange-rates/sync` | Sync EUR/CHF central bank rates. |
| `GET` | `/api/v1/settings/users` | List tenant members and roles. |
| `GET` | `/api/v1/settings/roles` | System RBAC roles. |
| `GET` | `/api/v1/settings/audit-logs` | Tamper-evident GDPR / Swiss FADP audit trail. |

---

## 3. Error Codes & Problem Details Catalogue

Errors return HTTP status codes with `Content-Type: application/problem+json` according to RFC 7807:

| Status Code | Title | Scenario |
|---|---|---|
| `400 Bad Request` | Missing Tenant Context | Missing `X-Tenant-ID` header. |
| `401 Unauthorized` | Invalid Credentials | Missing or invalid Sanctum Bearer token. |
| `403 Forbidden` | Forbidden Tenant Access | Authenticated user is not a member of the requested tenant. |
| `404 Not Found` | Resource Not Found | Record does not exist or has been soft-deleted. |
| `409 Conflict` | Already Clocked In | Worker attempted to clock in while an active shift was open. |
| `422 Unprocessable` | Unbalanced Ledger Entry / Validation | FormRequest validation failed or double-entry credits != debits. |
