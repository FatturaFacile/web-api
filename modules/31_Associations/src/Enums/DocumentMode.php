<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * What an enrolment form does with a document: blocks without it, accepts it, or only offers it for download.
 */
enum DocumentMode: string
{
    case Required = 'required';
    case Optional = 'optional';
    case DownloadOnly = 'download_only';
}
