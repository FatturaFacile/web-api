<?php

declare(strict_types=1);

namespace Modules\Esterometro\Exceptions;

use RuntimeException;

final class EsterometroException extends RuntimeException
{
    public static function notFound(string $id): self
    {
        return new self(__('esterometro.errors.not_found', ['id' => $id]), 404);
    }

    public static function invalidStateTransition(string $from, string $to): self
    {
        return new self(__('esterometro.errors.invalid_state_transition', ['from' => $from, 'to' => $to]), 422);
    }

    public static function xmlValidationFailed(string $details): self
    {
        return new self(__('esterometro.errors.xml_validation_failed', ['details' => $details]), 422);
    }

    public static function missingSupplierData(string $field): self
    {
        return new self(__('esterometro.errors.missing_supplier_data', ['field' => $field]), 422);
    }

    public static function missingTenantFiscalData(string $field): self
    {
        return new self(__('esterometro.errors.missing_tenant_fiscal_data', ['field' => $field]), 422);
    }

    public static function duplicateSelfInvoice(string $passiveInvoiceId): self
    {
        return new self(__('esterometro.errors.duplicate_self_invoice', ['id' => $passiveInvoiceId]), 409);
    }

    public static function sdiDispatchFailed(string $reason): self
    {
        return new self(__('esterometro.errors.sdi_dispatch_failed', ['reason' => $reason]), 502);
    }

    public static function fxRateUnavailable(string $currency, string $date): self
    {
        return new self(__('esterometro.errors.fx_rate_unavailable', ['currency' => $currency, 'date' => $date]), 422);
    }

    public static function vatReconciliationFailed(string $reason): self
    {
        return new self(__('esterometro.errors.vat_reconciliation_failed', ['reason' => $reason]), 422);
    }
}
