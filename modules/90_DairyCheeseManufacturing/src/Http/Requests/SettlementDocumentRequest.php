<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Requests;

final class SettlementDocumentRequest extends DairyFormRequest
{
    protected function ability(): string
    {
        return 'settle';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['document_type' => ['required', 'in:self_billing,credit_note,invoice']];
    }
}
