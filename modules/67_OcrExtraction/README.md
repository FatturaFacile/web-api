# Mod 092 — Intelligent document and invoice OCR extraction (Issue #220)

The AI Inbox. Vendor invoices, expense receipts, delivery notes (DDT / Lieferschein) and vendor quotes arrive by DMS upload,
a dedicated mailbox or the mobile app, are read **deterministic-first**, and wait in a review workbench until an accountant
confirms them. Nothing is posted without that confirmation unless the tenant switched touchless posting on.

## Pipeline (`ProcessDocumentExtractionPipelineHandler`, queue `ai-ocr`)

| Step | What happens |
| :--- | :--- |
| A. Inspect | **FatturaPA XML** and a **ZUGFeRD / Factur-X** XML attached to a PDF/A-3 are mapped exactly (`EmbeddedXmlOrQr`, confidence 100, 0 tokens, no HTTP call). A **Swiss QR-bill (SPC 0200)** fixes IBAN / QR-IBAN, creditor, currency, amount and QRR/SCOR exactly and the OCR engine reads only the rest (`HybridQrPlusOcr`); the QR fields override what the engine read and keep confidence 100. Anything else is read in full by the tenant's engine (`VisionLlmOcr`). |
| B. Vendor and coding | The vendor is found by UID (CHE), VAT number or IBAN. `PredictAccountingCodingForLinesHandler` suggests GL account, VAT code and cost center per line from the vendor's history: exact signature, then the most similar (Levenshtein / token cosine), then the vendor's usual coding. |
| C. Three-way match | Open purchase orders (`purchase_orders`, Mod 046) of the vendor that the document names, or whose total equals it, are evaluated line by line against the order and the goods receipts (`purchase_receipts`, Mod 047): price and quantity variances against the tolerance of `procurement_settings` (Mod 048). |
| D. Ready | Status `ready_for_review`, audit entry, `DocumentExtractionReadyBroadcast` on the private channel `tenant.{id}.ocr-inbox` (Reverb). |

A failure at any step leaves the job `Failed` with its reason. If the OCR engine cannot be reached for a QR-bill, the exact QR
fields are kept with the warning `ocr_unavailable`.

## Engines (`OcrVisionProviderInterface`)

`OllamaVisionAdapter` (default, local, `/api/chat` with the payload JSON Schema as `format`), `LayoutLmV3Adapter` (local service,
token-level boxes), `TesseractOcrAdapter` (local binary + `HeuristicInvoiceTextParser`, no model), and the cloud engines
`GeminiVisionAdapter`, `OpenAiVisionAdapter`, `AzureDocumentIntelligenceAdapter`. Each tenant chooses a primary and a fallback in
`tenant_ocr_configurations`; settings and API keys are stored encrypted and never returned. **A cloud engine is skipped unless the
tenant consented to external AI providers** (`TenantAiConsentInterface`), and it always uses the tenant's own key. Tag another
engine with `ocr.vision_providers` to plug it in. PDFs are turned into page images with Imagick or `pdftoppm` (poppler); without
either, engines that read images report a provider error (Gemini sends the PDF as it is).

## Confirmation (`ConfirmAndConvertExtractedDocumentHandler`)

In one transaction: the fiscal period must be open, VAT codes must belong to the company's jurisdiction (CH or IT), totals must
reconcile (CHF 0.05, other currencies 0.01) and a linked purchase order must match within tolerance or carry an override reason.
Then, through the services of the owning modules:

| Category | Result |
| :--- | :--- |
| `vendor_invoice` | received supplier invoice with lines, posted to the ledger (`PostPassiveInvoiceAction`) |
| `expense_receipt` | expense (`CreateExpenseAction`, category from the `expense_category` lookup) |
| `transport_ddt` | inbound goods receipt against the purchase order (`ProcessGoodsReceiptAction`) |
| `vendor_quote` | supplier quote on a request for quotation (`RfqService::recordQuote`) |

The vendor coding model learns from the confirmation (`TrainVendorCodingFeedbackHandler`): an accepted suggestion counts a hit, a
changed coding counts a correction on the superseded model and stores the new combination. The DMS file is linked to the new
record and marked `preservation_candidate` in its metadata; sealing is the package run of the digital preservation module. Every
extraction, draft, rejection, re-run, confirmation (with the list of corrected fields and any match override reason) and
configuration change is written to the audit ledger.

## Channels

| Channel | Entry |
| :--- | :--- |
| DMS | first version of a document in the `AI Inbox` folder (`EnqueueOnInboxUpload`) |
| E-mail | `POST /api/v1/webhooks/inbound-email`, `X-Signature: sha256=<hmac of the raw body>` with `OCR_INBOUND_EMAIL_SECRET`; tenant found by the recipient alias; redelivery of a message is idempotent |
| Mobile | `POST /api/v1/ocr-inbox/capture`, one `file` or the ordered `pages` (joined into one PDF), `client_reference` makes retries idempotent |

## API

`GET /api/v1/ocr-inbox` (queue, counters, `job_id` for the workbench), `GET /{id}`, `POST /documents`, `PUT /{id}/draft`,
`POST /{id}/confirm`, `POST /{id}/reject`, `POST /{id}/rerun`, `GET|PUT /configuration`, signed
`GET /api/v1/ocr-inbox-downloads/{job}` (source file for the viewer). Permissions: `ocr_inbox` (finance), `ocr_capture` (every member),
`ocr_settings` (owners and admins).

## Configuration

`config/ocr_extraction.php`: queue name, engine endpoints and models, inbound mail secret and domain, coding similarity threshold,
default match tolerance. Run the worker with `php artisan queue:work --queue=ai-ocr`.
