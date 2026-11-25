<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Illuminate\Contracts\Container\Container;
use Modules\ElectronicSignature\Contracts\SignableDocumentSource;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Sources\DocumentSignableSource;
use Modules\ElectronicSignature\Sources\EmploymentContractSignableSource;
use Modules\ElectronicSignature\Sources\PurchaseOrderSignableSource;
use Modules\ElectronicSignature\Sources\QuoteSignableSource;
use Modules\ElectronicSignature\Sources\UploadSignableSource;

/**
 * The kinds of document that can be sent for signature and the code that renders each and reacts to its outcome.
 */
final class SignableDocumentRegistry
{
    public function __construct(private readonly Container $container) {}

    public function source(SignableType $type): SignableDocumentSource
    {
        return $this->container->make(match ($type) {
            SignableType::Quote => QuoteSignableSource::class,
            SignableType::PurchaseOrder => PurchaseOrderSignableSource::class,
            SignableType::EmploymentContract => EmploymentContractSignableSource::class,
            SignableType::Document => DocumentSignableSource::class,
            SignableType::Upload => UploadSignableSource::class,
        });
    }
}
