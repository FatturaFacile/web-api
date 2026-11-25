<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * A signature engine: the built-in one or a qualified trust service provider.
 */
enum ProviderType: string
{
    case Native = 'native';
    case Swisscom = 'swisscom';
    case InfoCert = 'infocert';
    case Namirial = 'namirial';

    /**
     * Whether a recipient signs with a hash signature on the PDF the platform keeps (the provider only sees the digest), so
     * the signatures are applied one after the other on a single working copy.
     */
    public function signsHashes(): bool
    {
        return $this === self::Swisscom || $this === self::InfoCert;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
