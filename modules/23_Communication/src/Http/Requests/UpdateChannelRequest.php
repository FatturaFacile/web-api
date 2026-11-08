<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use App\Rules\PublicHost;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Services\ChannelConfigurator;
use Modules\Communication\Services\DocumentSender;

/**
 * Validates the configuration of a channel. The SMTP server of a company must be a public host (never the local machine,
 * a private network or cloud metadata), and a driver accepts only its own credential keys (access is decided by
 * module.access).
 */
final class UpdateChannelRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An enabled SMTP channel needs a server and a sender, counting what is already stored.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $driver = (string) $this->input('driver');
            $given = (array) $this->input('credentials', []);

            foreach (array_keys($given) as $key) {
                if (! in_array($key, ChannelConfigurator::Keys[$driver] ?? [], true)) {
                    $validator->errors()->add("credentials.{$key}", "The {$driver} driver has no credential called {$key}.");
                }
            }

            if ($driver !== 'smtp' || ! $this->boolean('is_enabled', true)) {
                return;
            }

            /** @var array<string, string> $stored */
            $stored = CommunicationChannel::query()->where('channel', (string) $this->route('channel'))->first()->credentials ?? [];

            foreach (['host', 'from_address'] as $required) {
                $value = array_key_exists($required, $given) ? $given[$required] : ($stored[$required] ?? null);

                if ($value === null || $value === '') {
                    $validator->errors()->add("credentials.{$required}", "The {$required} of the SMTP server is required.");
                }
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $smtp = $this->input('driver') === 'smtp';

        return [
            'driver' => ['required', Rule::in(array_keys(DocumentSender::driversOf((string) $this->route('channel'))))],
            'is_enabled' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', new ScalarText(500)],
            ...($smtp ? [
                'credentials.host' => ['nullable', 'string', 'max:253', new PublicHost],
                'credentials.port' => ['nullable', 'integer', 'between:1,65535'],
                'credentials.encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
                'credentials.from_address' => ['nullable', 'email:rfc', 'max:255'],
                'credentials.from_name' => ['nullable', 'string', 'max:100', 'regex:/^[^\r\n]*$/'],
            ] : []),
        ];
    }
}
