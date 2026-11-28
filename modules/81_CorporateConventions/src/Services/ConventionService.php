<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Domain\Enums\EnrollmentStatus;
use Modules\CorporateConventions\Domain\Support\DiscountMatrixValidator;
use Modules\CorporateConventions\Domain\Support\IdentifierHasher;
use Modules\CorporateConventions\Domain\Support\SlugGenerator;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionAllowedHash;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\ConventionDiscountRule;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;

/**
 * Lifecycle of the conventions: creation with slug and partner customer, the discount matrix, the SHA-256 employee list and the
 * files (contract PDF, logo).
 */
final class ConventionService
{
    private const array RuleFields = ['priority', 'rule_type', 'target_price_list_id', 'target_category_id', 'discount_percentage', 'exclude_promotional_items', 'cumulative_with_other_discounts', 'min_cart_amount'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $tenantId, ?string $userId): CorporatePartnerConvention
    {
        return DB::transaction(function () use ($data, $tenantId, $userId): CorporatePartnerConvention {
            $rules = $data['discount_rules'] ?? [];
            unset($data['discount_rules']);
            DiscountMatrixValidator::assertValid($rules);

            $data['slug'] = $this->slugFor($data['slug'] ?? null, (string) $data['name']);
            $data['status'] = ConventionStatus::Draft->value;
            $convention = new CorporatePartnerConvention([...$data, 'tenant_id' => $tenantId, 'created_by' => $userId]);
            $convention->assertConsistent();
            $convention->partner_contact_id = $this->partnerContact($data)->id;
            $convention->save();
            $this->syncRules($convention, $rules, $tenantId, $userId);

            return $convention->refresh()->load('discountRules');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CorporatePartnerConvention $convention, array $data, ?string $userId): CorporatePartnerConvention
    {
        if ($convention->status === ConventionStatus::Expired) {
            throw new ConventionException('convention_expired_locked', 409);
        }

        return DB::transaction(function () use ($convention, $data, $userId): CorporatePartnerConvention {
            $rules = $data['discount_rules'] ?? null;
            unset($data['discount_rules'], $data['status']);

            if ($rules !== null) {
                DiscountMatrixValidator::assertValid($rules);
            }

            if (isset($data['slug']) && $data['slug'] !== $convention->slug) {
                $data['slug'] = $this->slugFor((string) $data['slug'], (string) $convention->name, $convention->id);
            }

            $convention->fill([...$data, 'updated_by' => $userId]);
            $convention->assertConsistent();
            $convention->save();

            if ($rules !== null) {
                $this->syncRules($convention, $rules, (string) $convention->tenant_id, $userId);
            }

            if ($convention->status === ConventionStatus::Active) {
                $convention->load('discountRules');
                $convention->assertCanActivate();
            }

            return $convention->load('discountRules');
        });
    }

    public function transition(CorporatePartnerConvention $convention, ConventionStatus $target, ?string $userId): CorporatePartnerConvention
    {
        $convention->load('discountRules');
        $convention->transitionTo($target);
        $convention->updated_by = $userId;
        $convention->save();

        return $convention;
    }

    public function delete(CorporatePartnerConvention $convention, ?string $userId): void
    {
        $used = ConventionBeneficiaryEnrollment::query()->where('convention_id', $convention->id)->exists()
            || ConventionSplitBilling::query()->where('convention_id', $convention->id)->exists();

        if ($used) {
            throw new ConventionException('convention_in_use', 409);
        }

        DB::transaction(function () use ($convention, $userId): void {
            $convention->discountRules()->delete();
            $convention->allowedHashes()->delete();
            $convention->update(['deleted_by' => $userId]);
            $convention->delete();
        });
    }

    /**
     * Hashes the identifiers of the employer list (matricola, badge, card) with SHA-256 and stores only the hash and a masked hint.
     * Repeated identifiers and the ones already on the list are counted, never stored twice.
     *
     * @param  list<string>  $identifiers
     * @return array{received: int, imported: int, duplicates: int, invalid: int, preview: list<array{hint: string, sha256: string}>}
     */
    public function importIdentifiers(CorporatePartnerConvention $convention, array $identifiers, bool $dryRun, ?string $userId): array
    {
        $existing = ConventionAllowedHash::query()->where('convention_id', $convention->id)->pluck('identifier_sha256')->flip()->all();
        $rows = [];
        $invalid = 0;
        $duplicates = 0;
        $preview = [];

        foreach ($identifiers as $identifier) {
            $normalized = IdentifierHasher::normalize((string) $identifier);

            if ($normalized === '' || mb_strlen($normalized) > 120) {
                $invalid++;

                continue;
            }

            $hash = IdentifierHasher::hash($normalized);

            if (isset($existing[$hash])) {
                $duplicates++;

                continue;
            }

            $existing[$hash] = true;
            $rows[] = ['hash' => $hash, 'hint' => IdentifierHasher::hint($normalized)];

            if (count($preview) < 5) {
                $preview[] = ['hint' => IdentifierHasher::hint($normalized), 'sha256' => $hash];
            }
        }

        if (! $dryRun) {
            DB::transaction(function () use ($rows, $convention, $userId): void {
                foreach (array_chunk($rows, 500) as $chunk) {
                    $now = now();
                    ConventionAllowedHash::query()->insert(array_map(static fn (array $row): array => [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $convention->tenant_id,
                        'convention_id' => $convention->id,
                        'identifier_sha256' => $row['hash'],
                        'label_hint' => $row['hint'],
                        'is_used' => false,
                        'created_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk));
                }
            });
        }

        return ['received' => count($identifiers), 'imported' => count($rows), 'duplicates' => $duplicates, 'invalid' => $invalid, 'preview' => $preview];
    }

    public function storeContract(CorporatePartnerConvention $convention, UploadedFile $file): CorporatePartnerConvention
    {
        return $this->storeFile($convention, $file, 'contract_document_path', 'contracts');
    }

    public function storeLogo(CorporatePartnerConvention $convention, UploadedFile $file): CorporatePartnerConvention
    {
        return $this->storeFile($convention, $file, 'logo_path', 'logos');
    }

    /**
     * Conventions past their end date expire, and so do the passes of their beneficiaries.
     *
     * @return array{conventions: int, enrollments: int}
     */
    public function expireDue(): array
    {
        $conventions = CorporatePartnerConvention::query()
            ->whereIn('status', [ConventionStatus::Active->value, ConventionStatus::Suspended->value])
            ->whereNotNull('valid_to')
            ->whereDate('valid_to', '<', now()->toDateString())
            ->get();

        foreach ($conventions as $convention) {
            $convention->status = ConventionStatus::Expired;
            $convention->save();
        }

        $enrollments = ConventionBeneficiaryEnrollment::query()
            ->where('status', EnrollmentStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => EnrollmentStatus::Expired->value]);

        return ['conventions' => $conventions->count(), 'enrollments' => $enrollments];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CorporatePartnerConvention>
     */
    public function query(array $filters): Builder
    {
        return CorporatePartnerConvention::query()
            ->withCount([
                'enrollments as enrolled_employees_count' => fn (Builder $query) => $query->where('status', EnrollmentStatus::Active->value)->where('beneficiary_type', 'PRIMARY_EMPLOYEE'),
                'enrollments as enrolled_family_count' => fn (Builder $query) => $query->where('status', EnrollmentStatus::Active->value)->where('beneficiary_type', 'FAMILY_MEMBER'),
            ])
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $value) => $query->where('country_code', $value))
            ->when($filters['partner_type'] ?? null, fn (Builder $query, string $value) => $query->where('partner_type', $value))
            ->when($filters['status'] ?? null, fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.mb_strtolower($term).'%';
                $query->where(fn (Builder $inner) => $inner
                    ->whereRaw('LOWER(code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(partner_company_name) LIKE ?', [$like]));
            })
            ->orderBy('name');
    }

    private function slugFor(?string $requested, string $name, ?string $ignoreId = null): string
    {
        $exists = static fn (string $slug): bool => CorporatePartnerConvention::withoutGlobalScopes()->withTrashed()
            ->where('slug', $slug)->when($ignoreId, fn (Builder $query, string $id) => $query->where('id', '!=', $id))->exists();
        $reserved = (array) config('corporate_conventions.reserved_slugs');

        if ($requested !== null && $requested !== '') {
            if (! SlugGenerator::isValid($requested)) {
                throw new ConventionException('convention_slug_invalid', 422);
            }

            if (in_array($requested, $reserved, true) || $exists($requested)) {
                throw new ConventionException('convention_slug_taken', 409, ['slug' => $requested]);
            }

            return $requested;
        }

        return SlugGenerator::generate($name, $exists, $reserved);
    }

    /**
     * The partner as a customer of the company: the B2B invoice of the employer quota is addressed to it.
     *
     * @param  array<string, mixed>  $data
     */
    private function partnerContact(array $data): Contact
    {
        $vat = $data['partner_vat_number'] ?? null;
        $uid = $data['partner_uid_number'] ?? null;

        $existing = Contact::query()
            ->when($vat !== null, fn (Builder $query) => $query->where('vat_number', $vat), fn (Builder $query) => $query->when($uid !== null, fn (Builder $inner) => $inner->where('uid_number', $uid), fn (Builder $inner) => $inner->whereRaw('1 = 0')))
            ->first();

        return $existing ?? Contact::query()->create([
            'type' => 'customer',
            'company_name' => (string) $data['partner_company_name'],
            'country' => (string) $data['country_code'],
            'vat_number' => $vat,
            'uid_number' => $uid,
            'email' => $data['hr_contact_email'] ?? null,
            'phone' => $data['hr_contact_phone'] ?? null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function syncRules(CorporatePartnerConvention $convention, array $rules, string $tenantId, ?string $userId): void
    {
        $convention->discountRules()->delete();

        foreach ($rules as $index => $rule) {
            ConventionDiscountRule::query()->create([
                ...array_intersect_key($rule, array_flip(self::RuleFields)),
                'priority' => $rule['priority'] ?? (($index + 1) * 10),
                'convention_id' => $convention->id,
                'tenant_id' => $tenantId,
                'created_by' => $userId,
            ]);
        }

        $convention->unsetRelation('discountRules');
    }

    private function storeFile(CorporatePartnerConvention $convention, UploadedFile $file, string $column, string $folder): CorporatePartnerConvention
    {
        $disk = Storage::disk((string) config('corporate_conventions.storage.disk'));
        $path = $disk->putFileAs(
            config('corporate_conventions.storage.folder').'/'.$convention->tenant_id.'/'.$folder,
            $file,
            $convention->id.'-'.Str::random(8).'.'.($file->guessExtension() ?? 'bin'),
        );

        if ($convention->{$column} !== null) {
            $disk->delete((string) $convention->{$column});
        }

        $convention->forceFill([$column => $path])->save();

        return $convention;
    }
}
