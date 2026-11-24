<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;

final class EnqueueExtractionRequest extends FormRequest
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
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        return [
            'dms_document_id' => ['required', 'uuid', Rule::exists('dms_documents', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'document_category' => ['nullable', Rule::enum(DocumentCategory::class)],
        ];
    }
}
