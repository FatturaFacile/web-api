<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Modules\CRM\Models\Segment;
use Modules\CRM\Services\SegmentService;

/**
 * Creation or update of a marketing segment. The filter definition is checked against the whitelist of
 * SegmentService when saved, so a broken segment is refused here rather than failing when a campaign is built.
 */
final class SaveSegmentRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'entity' => [$this->isMethod('post') ? 'required' : 'prohibited', Rule::in(Segment::Entities)],
            'filters' => [$required, 'array'],
            'filters.*' => ['array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->has('filters')) {
                    return;
                }

                $entity = (string) ($this->input('entity') ?? Segment::find($this->route('id'))->entity ?? 'contacts');

                try {
                    app(SegmentService::class)->assertValid($entity, (array) $this->input('filters'));
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('filters', $exception->getMessage());
                }
            },
        ];
    }
}
