<?php

declare(strict_types=1);

namespace App\Services\Banking\Iso20022;

/**
 * The pain.001 (customer credit transfer initiation) versions supported for export.
 */
enum Pain001Version: string
{
    case V03 = '03';
    case V09 = '09';

    public function namespace(): string
    {
        return 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.'.$this->value;
    }

    public function schemaPath(): string
    {
        return resource_path('schemas/iso20022/pain.001.001.'.$this->value.'.xsd');
    }

    /**
     * Name of the BIC element of a financial institution identification.
     */
    public function bicElement(): string
    {
        return $this === self::V03 ? 'BIC' : 'BICFI';
    }

    /**
     * pain.001.001.03 states the execution date as a plain date; later versions wrap it in a date choice.
     */
    public function wrapsExecutionDate(): bool
    {
        return $this === self::V09;
    }
}
