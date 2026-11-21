<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Enums;

enum SourceChannel: string
{
    case DmsUpload = 'dms_upload';
    case TenantEmailInbox = 'tenant_email_inbox';
    case MobileCamera = 'mobile_camera';
    case DesktopScan = 'desktop_scan';
    case ApiWebhook = 'api_webhook';
}
