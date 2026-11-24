<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

use Modules\MarketingAutomation\Domain\Enums\MailProvider;

final class InfomaniakMailAdapter extends SmtpMailAdapter
{
    public function provider(): MailProvider
    {
        return MailProvider::Infomaniak;
    }
}
