<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Modules\Associations\Enums\DocumentMode;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\Member;

/**
 * The public enrolment form. The rules follow the configuration of the form, found by the token of the link. The field
 * "website" is a trap for bots: people never see it.
 */
final class SubmitEnrolmentRequest extends FormRequest
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
        $needsGuardian = Rule::requiredIf(fn (): bool => $this->isMinor());

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:20'],
            'tax_code' => ['required', 'string', 'max:30'],
            'privacy_accepted' => ['accepted'],
            'website' => ['prohibited'],
            'guardian_first_name' => [$needsGuardian, 'nullable', 'string', 'max:255'],
            'guardian_last_name' => [$needsGuardian, 'nullable', 'string', 'max:255'],
            'guardian_email' => [$needsGuardian, 'nullable', 'email:rfc', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'guardian_relationship' => ['nullable', 'string', 'max:50'],
            'extra' => ['sometimes', 'array'],
            'documents' => ['sometimes', 'array'],
            ...$this->fieldRules(),
            ...$this->documentRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function person(): array
    {
        $data = $this->safe()->except(['documents', 'website']);
        $data['privacy_accepted_at'] = now()->toIso8601String();

        return $data;
    }

    /**
     * @return array<string, UploadedFile>
     */
    public function uploads(): array
    {
        return array_filter((array) $this->file('documents', []), static fn (mixed $file): bool => $file instanceof UploadedFile);
    }

    private function form(): EnrolmentForm
    {
        /** @var EnrolmentForm */
        return $this->attributes->get('enrolmentForm');
    }

    private function isMinor(): bool
    {
        $birthDate = $this->input('birth_date');

        if (! is_string($birthDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) !== 1 || strtotime($birthDate) === false) {
            return false;
        }

        return CarbonImmutable::parse($birthDate)->addYears(Member::AgeOfMajority)->isFuture();
    }

    /**
     * @return array<string, list<string>>
     */
    private function fieldRules(): array
    {
        $rules = [];

        foreach ($this->form()->fields ?? [] as $field) {
            $type = match ($field['type'] ?? 'text') {
                'number' => 'numeric',
                'date' => 'date',
                'boolean' => 'boolean',
                default => 'string',
            };

            $rules["extra.{$field['code']}"] = [($field['required'] ?? false) ? 'required' : 'nullable', $type];

            if (($field['type'] ?? 'text') === 'select') {
                $rules["extra.{$field['code']}"][] = 'in:'.implode(',', $field['options'] ?? []);
            }
        }

        return $rules;
    }

    /**
     * @return array<string, list<string>>
     */
    private function documentRules(): array
    {
        $rules = [];

        foreach ($this->form()->documents as $document) {
            if ($document->mode !== DocumentMode::DownloadOnly) {
                $rules["documents.{$document->id}"] = [
                    $document->mode === DocumentMode::Required ? 'required' : 'nullable',
                    'file',
                    'mimes:pdf,jpg,jpeg,png',
                    'max:'.config('associations.upload_max_kilobytes'),
                ];
            }
        }

        return $rules;
    }
}
