<?php

declare(strict_types=1);

namespace Modules\SDI\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\SDI\Enums\SignatureMode;

/**
 * @property SignatureMode $signature_mode
 */
class SdiConfiguration extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'transmitter_country_code', 'transmitter_fiscal_code', 'progressivo_invio_counter', 'channel_type',
        'encrypted_credentials', 'default_codice_destinatario', 'pec_address', 'signature_mode', 'encrypted_certificate',
        'certificate_reference', 'auto_sign_on_send', 'legal_archiving_enabled', 'sandbox_simulator_enabled',
    ];

    protected $hidden = ['encrypted_credentials', 'encrypted_certificate'];

    protected function casts(): array
    {
        return [
            'encrypted_credentials' => 'encrypted:array',
            'encrypted_certificate' => 'encrypted',
            'signature_mode' => SignatureMode::class,
            'auto_sign_on_send' => 'boolean',
            'legal_archiving_enabled' => 'boolean',
            'sandbox_simulator_enabled' => 'boolean',
        ];
    }
}
