<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum KmsProviderType: string
{
    case SecurosysPrimusHsm = 'SECUROSYS_PRIMUS_HSM';
    case AwsKms = 'AWS_KMS';
    case AzureKeyVault = 'AZURE_KEY_VAULT';
    case GoogleCloudEkm = 'GOOGLE_CLOUD_EKM';
    case HashicorpVault = 'HASHICORP_VAULT';
    case KmipGeneric = 'KMIP_GENERIC';
    /** The platform's own key hierarchy: only for the Platform-Managed mode. */
    case Platform = 'PLATFORM';
}
