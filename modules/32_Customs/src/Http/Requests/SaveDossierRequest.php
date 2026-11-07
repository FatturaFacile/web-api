<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Customs\Enums\DossierStatus;
use Modules\Customs\Models\CustomsDossier;

final class SaveDossierRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'customer_contact_id' => [$required, 'uuid', CustomsRules::exists('contacts')],
            'service_type' => [$required, 'string', 'max:40'],
            'direction' => [$required, ...CustomsRules::direction()],
            'currency' => [$required, 'string', 'size:3'],
            'status' => [$this->isMethod('post') ? 'prohibited' : 'sometimes', Rule::enum(DossierStatus::class)],
            'origin_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'destination_country' => ['sometimes', 'nullable', ...CustomsRules::country()],
            'incoterms' => ['sometimes', 'nullable', 'string', 'size:3'],
            'gross_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'packages' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Rejects a manual status change that skips the dossier flow documented on DossierStatus.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $requested = DossierStatus::tryFrom((string) $this->input('status'));
                $dossierId = $this->route('id');

                if ($requested === null || ! $this->isMethod('put') || ! is_string($dossierId) || $validator->errors()->has('status')) {
                    return;
                }

                $current = CustomsDossier::query()->find($dossierId)?->status;

                if ($current instanceof DossierStatus && ! $current->canTransitionManuallyTo($requested)) {
                    $validator->errors()->add('status', __('The dossier cannot move from :from to :to.', ['from' => $current->value, 'to' => $requested->value]));
                }
            },
        ];
    }
}
