<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Quality\Models\QmsComplaint;

/**
 * A customer complaint at intake.
 */
final class ComplaintRequest extends QualityFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'customer_name' => ['required', 'string', 'max:190'],
            'contact_id' => ['nullable', 'uuid', $this->ownRow('contacts')],
            'received_at' => ['nullable', 'date_format:Y-m-d'],
            'channel' => ['nullable', Rule::in(QmsComplaint::Channels)],
            'reference' => ['nullable', 'string', 'max:120'],
            'product_description' => ['nullable', 'string', 'max:190'],
            'description' => ['required', 'string', 'max:4000'],
            'severity' => ['nullable', Rule::in(QmsComplaint::Severities)],
            'owner_id' => ['nullable', 'uuid', $this->member()],
        ]);
    }
}
