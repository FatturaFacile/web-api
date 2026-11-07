<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\MeetingBody;

final class SaveMeetingRequest extends FormRequest
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
        $percent = ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100'];

        return [
            'body' => [$required, Rule::enum(MeetingBody::class)],
            'body_name' => ['sometimes', 'nullable', 'string', 'max:255', 'required_if:body,other'],
            'title' => [$required, 'string', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'second_call_at' => ['sometimes', 'nullable', 'date', 'after:scheduled_at'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'online_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'first_call_quorum_percent' => $percent,
            'second_call_quorum_percent' => $percent,
            'proxies_allowed' => ['sometimes', 'boolean'],
        ];
    }
}
