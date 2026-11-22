<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MrpPlanning\Domain\Enums\ReleaseAction;

/**
 * Firm, release or dismiss a selection of proposals.
 */
final class FirmReleaseProposalsRequest extends FormRequest
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
        return [
            'proposal_ids' => ['required', 'array', 'min:1', 'max:500'],
            'proposal_ids.*' => ['required', 'uuid', 'distinct'],
            'action' => ['required', Rule::enum(ReleaseAction::class)],
        ];
    }
}
