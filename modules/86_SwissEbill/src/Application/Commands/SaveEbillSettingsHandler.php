<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use Illuminate\Support\Str;
use Modules\SwissEbill\Domain\Enums\EbillConnectionStatus;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Domain\Support\BillerIdValidator;
use Modules\SwissEbill\Models\EbillSetting;

final class SaveEbillSettingsHandler
{
    private const array PLAIN = [
        'ebill_enabled', 'provider', 'channel_protocol', 'api_base_url', 'client_id', 'webhook_auth_mode', 'webhook_mtls_fingerprint',
        'auto_send_on_invoice_issue', 'attach_courtesy_pdf', 'default_ebinterface_version',
    ];

    private const array SECRETS = ['client_secret', 'signing_certificate_pem', 'private_key_pem', 'webhook_secret'];

    /**
     * Fields whose change invalidates the last connection test.
     */
    private const array CONNECTION = ['provider', 'channel_protocol', 'api_base_url', 'client_id', 'biller_id'];

    public function __construct(private readonly EbillSettingRepositoryInterface $settings) {}

    public function handle(SaveEbillSettingsCommand $command): EbillSetting
    {
        $setting = $this->settings->currentOrCreate();
        $values = $command->values;
        $connectionChanged = false;

        if (array_key_exists('biller_id', $values)) {
            $biller = $values['biller_id'] === null || $values['biller_id'] === '' ? null : BillerIdValidator::normalize((string) $values['biller_id']);
            $connectionChanged = $biller !== $setting->biller_id;
            $setting->biller_id = $biller;
        }

        foreach (self::PLAIN as $field) {
            if (array_key_exists($field, $values)) {
                $connectionChanged = $connectionChanged || (in_array($field, self::CONNECTION, true) && $this->differs($setting, $field, $values[$field]));
                $setting->{$field} = $values[$field];
            }
        }

        foreach (self::SECRETS as $field) {
            if (isset($values[$field]) && $values[$field] !== '') {
                $setting->{$field} = $values[$field];
                $connectionChanged = $connectionChanged || $field !== 'webhook_secret';
            }
        }

        if (empty($setting->webhook_secret)) {
            $setting->webhook_secret = Str::random(48);
        }

        if ($connectionChanged) {
            $setting->connection_status = EbillConnectionStatus::Untested;
            $setting->last_connection_message = null;
            $setting->last_connection_latency_ms = null;
        }

        $setting->updated_by = $command->userId;

        return $this->settings->save($setting);
    }

    private function differs(EbillSetting $setting, string $field, mixed $value): bool
    {
        $current = $setting->{$field};
        $current = $current instanceof \BackedEnum ? $current->value : $current;

        return (string) $current !== (string) $value;
    }
}
