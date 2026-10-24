# FatturaFacile API — Enterprise Cross-Border ERP Backend

[![PHP Version](https://img.shields.io/badge/PHP-8.3%20%2F%208.5-777BB4?logo=php)](https://php.net)
[![Laravel Framework](https://img.shields.io/badge/Laravel-11%2B%20%2F%2012%2B-FF2D20?logo=laravel)](https://laravel.com)
[![Architecture](https://img.shields.io/badge/Architecture-Clean%20%2F%20DDD%20%2F%20Multi--Tenant-blue)]()
[![Jurisdictions](https://img.shields.io/badge/Jurisdictions-Italy%20(IT)%20%26%20Switzerland%20(CH)-brightgreen)]()

**FatturaFacile** is a production-grade, API-first enterprise Resource Planning (ERP) platform designed for cross-border operations between **Italy (IT)** and **Switzerland (CH)**. It features strict multi-tenant data isolation, native Italian electronic invoicing (SDI FatturaPA v1.2.x), Swiss QR-Bill generation with recursive Modulo 10 SCOR reference calculation, and multi-jurisdictional compliance for labor laws, customs (DDT / CMR), and accounting standards.

---

## 🌟 Key Architectural Features

1. **Multi-Tenancy by Design**:
   - Dynamic global tenant scoping (`TenantScope` via `BelongsToTenant` trait).
   - Automated request tenancy resolution via `X-Tenant-ID` header and user memberships.
   - Per-tenant role-based access control (RBAC).

2. **Cross-Border Fiscal Engine**:
   - **Italy**: Full FatturaPA XML builder (v1.2.2/v1.2.1) supporting B2B/B2G (`FPR12`/`FPA12`), Split Payment (`EsigibilitaIVA = S`), Reverse Charge (`N6.x`), and Luhn/checksum validation for Partita IVA and Codice Fiscale.
   - **Switzerland**: QR-Bill generator implementing SIX Group Swiss Payment Standards v2.2, recursive Modulo 10 check-digit algorithm for 27-digit SCOR references, Swiss QR-IBAN validation (IID 30000–31999), 0.05 CHF rounding convention, and SVG payment slip rendering.

3. **Treasury & Banking Integration**:
   - ISO 20022 `pain.001` credit transfer file generation for SEPA and Swiss banks.
   - ISO 20022 `camt.053` (bank statements) and `camt.054` (credit notifications) ingestion.
   - Italian CBI parser and automated payment reconciliation via reference matching.

4. **Time & Attendance Multi-Jurisdiction Compliance**:
   - Italian Legislative Decree **D.Lgs. 66/2003** (mandatory 11 consecutive hours of daily rest, 48h weekly average limit).
   - Swiss Labour Act **ArG / OLL 1** (45h/50h weekly limits, mandatory 15/30/60 min rest breaks).
   - Geofenced mobile clock-in/out and simplified recording (ArG Art. 73b).

5. **17 Vertical Industry Modules**:
   - Core Tenancy, CRM, Sales & Invoicing, Purchasing & AP, Treasury & Banking, Inventory & Logistics (DDT & Customs CMR), Field Service (Rapportini), Automotive & Tire Hotel (Gommista), Fleet Rental, Construction & Craftsmen (Cantieri SAL & DiCo/NIV), Professional Practices (Fascicoli & Parcelle), Manufacturing MRP, Retail POS (Registratore Telematico), Time & Attendance, Cross-Border HR (Frontalieri IT-CH), Fiscal Accounting (KMU/PME & General Ledger), and GDPR/nLPD Audit Trail.

---

## 🚀 Quick Start & Installation

### Prerequisites
- PHP 8.3 or PHP 8.5+ with `pdo`, `mbstring`, `openssl`, `xml`, `bcmath`, `curl` extensions
- Composer 2.x
- SQLite (for local/testing) or PostgreSQL/MySQL 8.0+ for production

### Setup Instructions

```bash
# 1. Clone repository and enter directory
cd c:\dev\fattura-facile\web-api

# 2. Install composer dependencies
composer install

# 3. Environment configuration
cp .env.example .env
php artisan key:generate

# 4. Run database migrations
php artisan migrate:fresh

# 5. Execute automated test suite
php artisan test --compact
```

---

## ⚙️ Environment Configuration (`.env`)

Key configuration settings required for cross-border ERP operation:

```ini
APP_NAME=FatturaFacile
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=sqlite
# For MySQL / PostgreSQL:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=fattura_facile
# DB_USERNAME=root
# DB_PASSWORD=secret

# Localization & Multi-Tenancy Defaults
APP_LOCALE=it
APP_FALLBACK_LOCALE=en

# Cache & Queues
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

---

## 🔒 Authentication & API Workflow

FatturaFacile API routes are secured via **Laravel Sanctum** and require the `X-Tenant-ID` header.

### 1. Authenticate (Public Endpoint)

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "leonardo@fatturafacile.com",
  "password": "your-password"
}
```

**Response (200 OK)**:
```json
{
  "success": true,
  "message": "Authenticated successfully",
  "data": {
    "token": "1|abcdef123456789...",
    "token_type": "Bearer",
    "user": {
      "id": "019488a0-...",
      "name": "Leonardo Da Vinci",
      "email": "leonardo@fatturafacile.com",
      "locale": "it"
    },
    "active_tenant": {
      "id": "019488a0-tenant-it-...",
      "name": "FatturaFacile Italia SRL",
      "country": "IT",
      "default_currency": "EUR",
      "role": "admin"
    },
    "available_tenants": [
      { "id": "019488a0-tenant-it-...", "name": "FatturaFacile Italia SRL", "country": "IT" },
      { "id": "019488a0-tenant-ch-...", "name": "FatturaFacile Schweiz AG", "country": "CH" }
    ]
  }
}
```

### 2. Accessing Protected Tenant Endpoints

Include the Bearer token and the active `X-Tenant-ID` header on all subsequent requests:

```http
GET /api/v1/sales/invoices
Authorization: Bearer 1|abcdef123456789...
X-Tenant-ID: 019488a0-tenant-it-...
Accept-Language: it
Accept: application/json
```

---

## 🧪 Testing & Code Quality

```bash
# Run Pest test suite
php artisan test --compact

# Code style checking & auto-formatting (Laravel Pint)
vendor/bin/pint --dirty --format agent

# Run tests with coverage
php artisan test --coverage
```

---

## 📄 License
Proprietary & Confidential — FatturaFacile ERP Systems. All rights reserved.
