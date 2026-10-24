# FatturaFacile ERP — System Architecture & Design Documentation

## 1. Architectural Philosophy & DDD Principles

FatturaFacile is engineered following **Clean Architecture** and **Domain-Driven Design (DDD)** concepts, separating infrastructure concerns from core fiscal and domain rules.

```
┌─────────────────────────────────────────────────────────────┐
│                      Presentation Layer                     │
│    Controllers, API Resources, FormRequests, Middlewares    │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                        Domain Layer                         │
│  Entities, Value Objects, Domain Events, Enums, Contracts   │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                     Application Services                    │
│   QrBillGeneratorService, FatturaPaBuilderService,          │
│   AttendanceComplianceService, InvoiceService               │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│                    Infrastructure Layer                     │
│    TenantScope, Eloquent ORM, Storage, XML / ISO Parsers    │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Multi-Tenancy Architecture

### 2.1 Schema Strategy
FatturaFacile implements a **Single-Database, Shared-Schema, Scoped Data Isolation** architecture with UUID primary and foreign keys. This provides:
- High resource efficiency and simplified database migration management.
- Complete data isolation enforced at the Eloquent query engine level.
- Multi-company membership (a user can hold different roles across multiple tenants).

### 2.2 Dynamic Global Tenant Scope
Every tenant-scoped model implements `App\Models\Concerns\BelongsToTenant`:

```php
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model): void {
            if (empty($model->tenant_id)) {
                $tenantContext = app(TenantContext::class);
                if ($tenantContext->hasTenant()) {
                    $model->tenant_id = $tenantContext->getTenantId();
                }
            }
        });
    }
}
```

The global scope `TenantScope` automatically injects:
```sql
WHERE `table`.`tenant_id` = 'active-tenant-uuid'
```
into every query, preventing accidental cross-tenant data leakage.

### 2.3 Tenancy Resolution Lifecycle
1. Incoming HTTP requests pass through `App\Http\Middleware\IdentifyTenant`.
2. The middleware reads the `X-Tenant-ID` header (or defaults to the user's primary company).
3. The tenant is resolved from the database.
4. Membership and authorization are verified.
5. The active `Tenant` instance is bound into the container singleton `App\Services\Tenancy\TenantContext`.

---

## 3. Cross-Border Fiscal Systems Engine

### 3.1 Italy (IT Jurisdiction)
- **Schema & Protocols**: Official Agenzia delle Entrate XML schema `FatturaElettronica` v1.2.2.
- **Recipient Routing**:
  - `CodiceDestinatario`: 7-character alphanumeric code for B2B/B2C or 6-character code for Public Administrations (PA).
  - Default `0000000` routes via registered `PECDestinatario`.
- **Fiscal Regimes**: Supports standard Ordinary regime (`RF01`), Forfettario (`RF19`), Minimi (`RF02`).
- **Fiscal Identifiers**:
  - **Partita IVA**: 11-digit numerical identifier verified via modified Luhn checksum.
  - **Codice Fiscale**: 16-character alphanumeric code verified through strict odd/even character weight calculation and modulo 26 control character mapping.
- **VAT Special Schemes**:
  - **Split Payment**: Generates `<EsigibilitaIVA>S</EsigibilitaIVA>` in `<DatiRiepilogo>` for transactions with public entities.
  - **Reverse Charge**: Injects `<Natura>N6.x</Natura>` codes for domestic reverse charge (construction, electronics) and intra-community acquisitions.

### 3.2 Switzerland (CH Jurisdiction)
- **SIX Group Swiss QR-Bill Guidelines v2.2**:
  - **QR-IBAN**: Special IBAN range with Institution Identification (IID) between `30000` and `31999`.
  - **SCOR Reference**: 27-digit numeric reference computed using a **Modulo 10 recursive check-digit table** algorithm. Formatted in 5-digit visual blocks (e.g., `21 00000 00003 13947 14300 09017`).
  - **0.05 CHF Rounding Convention**: In Switzerland, cash transactions and invoice gross amounts are legally rounded to the nearest 5 centimes:
    $$\text{Gross}_{\text{rounded}} = \frac{\text{round}(\text{Gross} \times 20)}{20}$$
    The rounding delta is stored in `rounding_amount` to maintain strict accounting ledger balances.
  - **Swiss QR Code Payload**: Structured text payload format starting with `SPC` (Swiss Payments Code) version `0200` followed by Creditor, Debtor, Amount, Currency, and SCOR blocks.
- **Banking Standards**:
  - Outgoing payments: ISO 20022 `pain.001.001.03` XML generation.
  - Ingoing bank statements: ISO 20022 `camt.053` and `camt.054` credit notifications with automated SCOR reconciliation.

---

## 4. Labor Compliance Engine (Time & Attendance)

Cross-border companies frequently employ workers under both Italian and Swiss employment frameworks:

| Feature | Italian Labor Law (D.Lgs. 66/2003) | Swiss Labor Act (ArG / OLL 1) |
|---|---|---|
| **Daily Rest** | Minimum **11 consecutive hours** in every 24h period (Art. 7). | Minimum **11 consecutive hours** daily rest (ArG Art. 15a). |
| **Max Weekly Hours** | 48 hours average (including overtime) per 4-month reference period. | **45 hours** for industrial/office staff; **50 hours** for other sectors (ArG Art. 9). |
| **Mandatory Breaks** | > 6h daily shift: min 10 minutes break (or CCNL provisions). | > 5.5h: min 15m; > 7h: min 30m; > 9h: min 60m (ArG Art. 15). |
| **Cross-Border Commuters** | Monitored under IT-CH Frontalieri tax agreement 2024. | Cantonal source withholding (*Imposta alla Fonte* TI, GR, VS). |

---

## 5. Standardized Response & Error Contracts

### 5.1 Success Envelope
```json
{
  "success": true,
  "message": "Resource retrieved successfully",
  "data": { ... },
  "meta": {
    "current_page": 1,
    "total": 100
  }
}
```

### 5.2 Error Contract (RFC 7807 Problem Details)
```json
{
  "type": "https://httpstatuses.com/422",
  "title": "Unprocessable Entity",
  "status": 422,
  "detail": "The provided Partita IVA checksum is invalid.",
  "instance": "/api/v1/crm/contacts",
  "invalid_params": {
    "vat_number": ["The Partita IVA checksum does not match Agenzia delle Entrate specifications."]
  }
}
```

---

## 6. API Versioning Strategy

All endpoints reside under the `/api/v1/` route group. Backwards-incompatible changes will be introduced in `/api/v2/` without breaking legacy mobile and web client integrations.
