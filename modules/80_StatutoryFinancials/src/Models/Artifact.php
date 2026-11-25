<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A stored file of a dossier: the XBRL instance or a PDF/A document.
 *
 * @property string $dossier_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string $file_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $pdfa_conformance
 * @property array<string, mixed>|null $verification
 */
final class Artifact extends BaseEnterpriseModel
{
    protected $table = 'sfs_artifacts';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'verification' => 'array',
        ];
    }
}
