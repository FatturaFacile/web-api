<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\ImportBatch;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Support\CsvFile;

/**
 * Importing the registry in two steps: the preview analyses the file and reports errors and duplicates without touching
 * the registry; the confirmation applies the rows the user accepted.
 */
final class RegistryImportService
{
    public const array Columns = ['first_name', 'last_name', 'email', 'phone', 'tax_code', 'birth_date', 'birth_place', 'gender', 'registry_type'];

    public const string OnDuplicateSkip = 'skip';

    public const string OnDuplicateUpdate = 'update';

    public function __construct(
        private readonly DuplicateDetector $duplicates,
        private readonly RegistryService $registry,
    ) {}

    public function preview(UploadedFile $file, ?string $userId): ImportBatch
    {
        $maximum = (int) config('associations.import_max_rows');
        $rows = CsvFile::read($file->getRealPath(), $maximum);

        if ($rows === [] || count($rows) > $maximum) {
            throw new AssociationException(__('associations.import_file_invalid', ['maximum' => $maximum]));
        }

        $seen = [];
        $analysed = [];

        foreach ($rows as $index => $row) {
            $analysed[] = $this->analyse($index + 1, array_intersect_key($row, array_flip(self::Columns)), $seen);
        }

        return ImportBatch::create([
            'created_by' => $userId,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => ImportBatch::Preview,
            'rows' => $analysed,
            'summary' => [
                'total' => count($analysed),
                'valid' => count(array_filter($analysed, static fn (array $row): bool => $row['errors'] === [] && $row['duplicate'] === null)),
                'invalid' => count(array_filter($analysed, static fn (array $row): bool => $row['errors'] !== [])),
                'duplicates' => count(array_filter($analysed, static fn (array $row): bool => $row['duplicate'] !== null)),
            ],
        ]);
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function commit(ImportBatch $batch, string $onDuplicate): array
    {
        return DB::transaction(function () use ($batch, $onDuplicate): array {
            /** @var ImportBatch $locked */
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ImportBatch::Preview) {
                throw AssociationException::conflict(__('associations.import_already_committed'));
            }

            $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

            foreach ($locked->rows as $row) {
                $result[$this->apply($row, $onDuplicate)]++;
            }

            // The personal data of the file has served its purpose: only the counts stay.
            $locked->update([
                'status' => ImportBatch::Committed,
                'rows' => [],
                'summary' => [...$locked->summary, 'result' => $result],
                'committed_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, true>  $seen  tax codes and emails met earlier in the file
     * @return array{row: int, data: array<string, string>, errors: list<string>, duplicate: ?array{member_id: ?string, reason: string}}
     */
    private function analyse(int $number, array $data, array &$seen): array
    {
        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_code' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:20'],
        ]);

        $errors = $validator->errors()->all();

        if (($data['registry_type'] ?? '') !== '' && ! RegistryType::query()->where('code', $data['registry_type'])->exists()) {
            $errors[] = __('associations.import_registry_type_unknown', ['code' => $data['registry_type']]);
        }

        $duplicate = $this->duplicates->find($data);

        foreach (['tax_code', 'email'] as $key) {
            $value = mb_strtolower(trim($data[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($duplicate === null && isset($seen["{$key}:{$value}"])) {
                $duplicate = ['member_id' => null, 'reason' => "{$key}_in_file"];
            }

            $seen["{$key}:{$value}"] = true;
        }

        return ['row' => $number, 'data' => $data, 'errors' => $errors, 'duplicate' => $duplicate];
    }

    /**
     * @param  array{row: int, data: array<string, string>, errors: list<string>, duplicate: ?array{member_id: ?string, reason: string}}  $row
     * @return 'created'|'updated'|'skipped'
     */
    private function apply(array $row, string $onDuplicate): string
    {
        if ($row['errors'] !== []) {
            return 'skipped';
        }

        $data = array_filter($row['data'], static fn (string $value): bool => $value !== '');
        $type = isset($data['registry_type']) ? RegistryType::query()->where('code', $data['registry_type'])->first() : null;
        unset($data['registry_type']);

        // The preview may be old, and earlier rows of this file are in the registry now.
        $current = $this->duplicates->find($data);

        if ($current === null) {
            if (($row['duplicate']['reason'] ?? null) !== null && $onDuplicate === self::OnDuplicateSkip) {
                return 'skipped';
            }

            $member = $this->registry->create($data);

            if ($type !== null) {
                $this->registry->assignType($member, $type);
            }

            return 'created';
        }

        if ($onDuplicate !== self::OnDuplicateUpdate) {
            return 'skipped';
        }

        /** @var Member $member */
        $member = Member::query()->with('contact')->findOrFail($current['member_id']);
        $this->registry->update($member, $data);

        if ($type !== null && ! $member->registryAssignments()->where('registry_type_id', $type->id)->validOn(CarbonImmutable::today()->toDateString())->exists()) {
            $this->registry->assignType($member, $type);
        }

        return 'updated';
    }
}
