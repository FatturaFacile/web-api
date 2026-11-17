<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceManagement\FieldService\Commands\SignServiceReportRules;

/**
 * Signs and closes the service report of a work order; identical contract to the `service_report` operation of the
 * mobile sync, with `client_uuid` for idempotency.
 */
final class SignServiceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SignServiceReportRules::rules();
    }
}
