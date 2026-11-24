# AI Services & Cognitive Automation Layer (Mod 093, issue #219)

A multi-tenant, privacy-first orchestration layer that turns unstructured input into draft ERP records, proposes accounting
codings and bank matches, raises predictive and compliance alerts, and answers questions about the company's data. Nothing the
layer proposes becomes an ERP record without either a person or an explicit, per-workflow policy of the tenant.

It builds on what already exists and adds no second copy of it:

| Concern | Reused from |
| --- | --- |
| Chat/embedding wire clients (OpenAI-compatible, Ollama, Anthropic, Gemini, local hash embedding) | `62_Copilot` (`Llm`, `Embeddings`) |
| External-provider consent (`TenantAiConsentInterface`) | `packages/ai-gateway` |
| Governed, parameterized, tenant-scoped query executor and the closed entity registry | `62_Copilot` (`Semantic`) |
| Prompt delimiter defusing | `62_Copilot` (`PromptSanitizer`) |
| Cash flow bands, payment behaviour models, expected collections | `66_Forecasting` |
| Draft creation (quote, purchase order, ticket) | `CreateSalesQuoteAction`, `CreatePurchaseOrderAction`, `CreateTicketAction` |

## Architecture

```
Domain/           enums, value objects, contracts (AiProviderGatewayInterface, AiAdapterInterface), exceptions
Application/      commands, queries and handlers (CQRS); handlers run inside the tenant context (AiTenantRunner)
Infrastructure/   models, adapters, services, executors, jobs
Presentation/     controllers, FormRequests, resources
routes/api.php    /api/v1/ai/...
```

### Gateway (`AiProviderGateway`)

The single door to every model. Per provider that the router offers, in order: circuit breaker, monthly token budget and
per-minute rate limit, PII masking (hosted providers only), response cache, the call with exponential backoff, re-hydration,
usage/cost log (`ai_usage_logs`), audit entry. A failing provider hands over to the next allowed one.

* **Router** (`ModelRouter`): a provider accepts data up to the classification the tenant set for it (`public`, `internal`,
  `confidential_pii`). A hosted provider with PII masking on sees masked text, so it counts one level lower. `confidential_pii`
  prefers local providers; light tasks (classification, tagging, accounting coding, query translation) prefer local/cheap
  models; reasoning tasks prefer a hosted frontier model when the tenant has one.
* **Providers**: local Ollama (zero egress; also the implicit provider of a tenant without configuration), and bring-your-own-key
  OpenAI, Anthropic, Gemini and Azure OpenAI. A hosted provider only runs for a tenant that allowed external AI providers.
  Keys are encrypted at rest (model cast), never returned by the API and never written to the audit trail. The settings
  endpoint only accepts the official host of each hosted provider, so a key cannot be sent to an arbitrary address.
* **Cache**: keyed by tenant, provider, model and the normalised masked prompt (case and whitespace folded). This is an exact
  match on the normalised text, not an embedding-similarity cache.
* **PII masking** (`PiiMaskingService`): IBAN (mod-97 verified), Swiss AHV/AVS, Italian Codice Fiscale, VAT numbers, emails,
  phone numbers, and person names (after a salutation or title, plus names the caller knows). Same value, same token
  (`[[IBAN_1]]`); the answer is re-hydrated locally. Name detection is heuristic: it is a defence in depth, not a guarantee.

### Review queue (human in the loop)

`ai_suggestions` holds proposals with field-level confidences, reasoning, citations and the source text.

* `SuggestionService::propose` stores a proposal (one open suggestion per source reference), then asks `AutomationPolicy`
  whether it may be executed at once: automatic approval on, confidence at or above the threshold, amount below the limit.
  Without a rule of the tenant nothing is approved without a person. Alerts and recommendations are never auto-approved.
* Approve / Edit & Approve / Reject (reason required) go through executors (`ErpDraftExecutor`, `ReconciliationExecutor`,
  `AcknowledgeExecutor`). A proposal that cannot become a record stays open with a readable reason.
* Reconciliation approval only links the bank transaction to the invoice (`matched_invoice_id`); registering the payment on the
  invoice stays an accountant action. Grouped payments link the transaction to the first invoice of the group and keep all
  invoice ids in the suggestion.

### Pipelines

| Pipeline | Entry | Notes |
| --- | --- | --- |
| Extraction (email, RFQ, delivery note, ticket, contact) | `POST /ai/suggestions/extract` | the customer/supplier is matched by email, VAT number or exact name; an unmatched party caps the confidence at 0.50 |
| Accounting coding | `POST /ai/accounting/coding` | the model may only pick codes from the tenant's own chart of accounts, VAT codes and cost centers; an invented code scores 0 |
| Reconciliation | `POST /ai/accounting/reconciliation` | amount, reference, name similarity, local semantic similarity; exact, partial and grouped (2-3 invoices) |
| Anomaly guard | nightly job, `POST /ai/insights/generate` | duplicate invoices, supplier IBAN differing from master data (BEC), non-statutory VAT rates, fields missing before QR-bill/SDI. Deterministic rules, no model |
| Predictive insights | nightly job, `POST /ai/insights/generate` | cash trough (from `66_Forecasting` bands), late-payment risk, churn (order frequency drop), reorder (stock at or below reorder point) |
| Copilot | `POST /ai/copilot/query` | analytics: model fills a structured query, executed by the governed executor (never SQL); knowledge: tenant RAG |
| Drafts | `POST /ai/copilot/drafts` | it-CH, de-CH, fr-CH, en, it-IT; nothing is sent |

### RAG

`ai_embeddings` stores chunks of contacts, invoices, quotes, tickets and the Swiss/Italian fiscal rules, with the vector as
JSON text; the cosine ranking runs in PHP over the tenant's candidates, identical on SQLite and PostgreSQL (a pgvector index
can replace it behind `VectorIndexService`). `RebuildTenantVectorIndexJob` re-embeds only changed chunks.

### Audit trail

`ai_audit_trails` is append-only (model refuses update/delete) and hash-chained per tenant (`AiAuditor::verify`). It records
inferences (prompt and answer fingerprints, never the text), suggestions, confidences, citations, every human decision with the
fields that were corrected, configuration changes (no secrets), and index/insight runs.

## Endpoints (`/api/v1/ai`, Sanctum + tenant)

| Module access (permission grants) | Endpoints |
| --- | --- |
| `ai_copilot` (`ai.copilot.use`) | `POST copilot/query`, `POST copilot/drafts` |
| `ai_review` (`ai.suggestions.review`) | `GET suggestions`, `GET suggestions/{id}`, `POST suggestions/extract`, `POST suggestions/{id}/approve`, `POST suggestions/{id}/reject`, `POST accounting/coding`, `POST accounting/reconciliation`, `GET insights`, `POST insights/generate` |
| `ai_admin` (`ai.admin.configure`) | `GET settings`, `PUT settings/providers/{provider}`, `PUT settings/rules/{workflow}`, `POST copilot/index/rebuild`, `GET usage-audit` |

## Known limits

* Provider token pricing in `config/ai_cognitive.php` is a static list price table.
* Reorder recommendations compare available stock with the sum of the reorder points of an item across warehouses.
* Grouped payments are linked through the single `matched_invoice_id` column of a bank transaction.
* No fine-tuning loop: rejection reasons and corrections are stored and audited, but no model is retrained from them.
* Name masking is heuristic.

## Tests

`tests/Unit/AiCognitive`, `tests/Feature/AiCognitive` (SQLite, deterministic fake adapters and gateway, no network).
