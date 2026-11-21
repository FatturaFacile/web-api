<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Delivery or action channel through which an approval decision was submitted.
 */
enum ApprovalChannel: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case EmailOneClick = 'email_one_click';
    case PushOneClick = 'push_one_click';
}
