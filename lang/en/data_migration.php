<?php

declare(strict_types=1);

return [
    'metadata_loaded' => 'Migration metadata loaded.',
    'template_saved' => 'Mapping template saved.',
    'job_uploaded' => 'Source archive uploaded and parsed.',
    'mapping_updated' => 'Column mapping updated.',
    'dry_run_completed' => 'Dry-run simulation completed.',
    'row_patched' => 'Staging row updated.',
    'job_committed' => 'Migration committed successfully.',
    'job_rolled_back' => 'Atomic rollback completed.',
    'empty_file' => 'The uploaded file is empty.',
    'max_rows_exceeded' => 'The file exceeds the maximum limit of :max rows.',
    'invalid_zip_archive' => 'Invalid or corrupted ZIP archive.',
    'empty_zip_archive' => 'The ZIP archive contains no valid XML, CSV, or XLSX files.',
    'dry_run_required' => 'Dry-run simulation must pass with zero errors before commit.',
    'rollback_only_completed' => 'Rollback is only allowed for completed migration jobs.',
];
