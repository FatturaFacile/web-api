<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

use Modules\Customs\Models\CustomsCountry;
use Modules\Customs\Models\CustomsDocumentType;
use Modules\Customs\Models\CustomsMasterRecord;
use Modules\Customs\Models\CustomsPackagingType;
use Modules\Customs\Models\CustomsStatisticalKey;
use Modules\Customs\Models\CustomsTariffNumber;

/**
 * The code tables of the customs domain, as named in the URLs.
 */
enum MasterDataType: string
{
    case TariffNumbers = 'tariff-numbers';
    case StatisticalKeys = 'statistical-keys';
    case PackagingTypes = 'packaging-types';
    case Countries = 'countries';
    case DocumentTypes = 'document-types';

    /**
     * @return class-string<CustomsMasterRecord>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::TariffNumbers => CustomsTariffNumber::class,
            self::StatisticalKeys => CustomsStatisticalKey::class,
            self::PackagingTypes => CustomsPackagingType::class,
            self::Countries => CustomsCountry::class,
            self::DocumentTypes => CustomsDocumentType::class,
        };
    }
}
