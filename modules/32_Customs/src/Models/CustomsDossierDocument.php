<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

final class CustomsDossierDocument extends CustomsRecord
{
    protected $hidden = ['storage_path'];
}
