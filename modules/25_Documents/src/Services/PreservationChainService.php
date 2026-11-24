<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Models\DmsPreservationChainLink;
use Modules\DocumentTrust\Models\PreservationPackage;

/**
 * Hash chain over the closed preservation packages of a company (module 32). Every closed package is anchored once:
 * its link hash covers the sequence number, the package and its hash and the hash of the previous link, so removing,
 * reordering or altering a package, or a link, breaks every later link. This adds evidence of order and completeness to
 * the package hashes; it does not make the installation an accredited conservatore.
 */
final readonly class PreservationChainService
{
    public const string GenesisHash = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Appends a link for every closed package that has none, oldest closing first.
     *
     * @return list<DmsPreservationChainLink> the links appended by this call
     */
    public function anchorClosedPackages(?User $user = null): array
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        return DB::transaction(function () use ($tenantId, $user): array {
            TransactionLock::acquire('dms-preservation-chain:'.$tenantId);

            $last = DmsPreservationChainLink::query()->orderByDesc('sequence')->first();
            $sequence = $last->sequence ?? 0;
            $previousHash = $last->link_hash ?? self::GenesisHash;
            $chained = DmsPreservationChainLink::query()->pluck('package_id')->map(static fn (mixed $id): string => (string) $id)->all();
            $appended = [];

            $packages = PreservationPackage::query()
                ->where('status', PreservationPackage::Closed)
                ->whereNotNull('package_hash')
                ->whereNotIn('id', $chained)
                ->orderBy('closed_at')
                ->orderBy('id')
                ->get();

            foreach ($packages as $package) {
                $sequence++;
                $linkHash = self::linkHash($sequence, (string) $package->id, (string) $package->package_hash, $previousHash);
                $appended[] = DmsPreservationChainLink::create([
                    'tenant_id' => $tenantId,
                    'sequence' => $sequence,
                    'package_id' => $package->id,
                    'package_hash' => $package->package_hash,
                    'previous_link_hash' => $previousHash,
                    'link_hash' => $linkHash,
                    'anchored_by' => $user?->id,
                ]);
                $previousHash = $linkHash;
            }

            return $appended;
        });
    }

    /**
     * Recomputes the chain and compares every link with its package.
     *
     * @return array{valid: bool, links_checked: int, broken_at_sequence: int|null, reason: string|null}
     */
    public function verify(): array
    {
        $previousHash = self::GenesisHash;
        $expectedSequence = 0;
        $checked = 0;

        $links = DmsPreservationChainLink::query()->orderBy('sequence')->get();

        foreach ($links as $link) {
            $expectedSequence++;
            $checked++;

            $reason = match (true) {
                $link->sequence !== $expectedSequence => 'sequence_gap',
                ! hash_equals($previousHash, (string) $link->previous_link_hash) => 'previous_hash_mismatch',
                ! hash_equals(self::linkHash($link->sequence, (string) $link->package_id, (string) $link->package_hash, $previousHash), (string) $link->link_hash) => 'link_hash_mismatch',
                default => $this->packageProblem($link),
            };

            if ($reason !== null) {
                return ['valid' => false, 'links_checked' => $checked, 'broken_at_sequence' => $link->sequence, 'reason' => $reason];
            }

            $previousHash = (string) $link->link_hash;
        }

        return ['valid' => true, 'links_checked' => $checked, 'broken_at_sequence' => null, 'reason' => null];
    }

    /**
     * Retention and sealing status of the closed packages: chained or not, time-stamped or not, retention end.
     *
     * @return array{closed_packages: int, chained_packages: int, unchained_packages: int, packages: list<array<string, mixed>>}
     */
    public function status(): array
    {
        $links = DmsPreservationChainLink::query()->get()->keyBy(static fn (DmsPreservationChainLink $link): string => (string) $link->package_id);
        $packages = PreservationPackage::query()->where('status', PreservationPackage::Closed)->orderBy('closed_at')->orderBy('id')->get();
        $rows = [];

        foreach ($packages as $package) {
            $link = $links->get((string) $package->id);
            $rows[] = [
                'package_id' => (string) $package->id,
                'name' => $package->name,
                'document_class' => $package->document_class,
                'closed_at' => $package->closed_at?->toIso8601String(),
                'retention_until' => $package->retention_until?->toDateString(),
                'retention_expired' => $package->retention_until !== null && $package->retention_until->isPast(),
                'timestamped' => $package->timestamped_at !== null,
                'chained' => $link !== null,
                'chain_sequence' => $link?->sequence,
                'sealing_status' => $link !== null ? 'sealed' : 'awaiting_anchor',
            ];
        }

        return [
            'closed_packages' => count($rows),
            'chained_packages' => $links->count(),
            'unchained_packages' => count(array_filter($rows, static fn (array $row): bool => ! $row['chained'])),
            'packages' => $rows,
        ];
    }

    public static function linkHash(int $sequence, string $packageId, string $packageHash, string $previousHash): string
    {
        return hash('sha256', implode('|', [$sequence, $packageId, $packageHash, $previousHash]));
    }

    private function packageProblem(DmsPreservationChainLink $link): ?string
    {
        $package = PreservationPackage::query()->find($link->package_id);

        return match (true) {
            $package === null => 'package_missing',
            $package->package_hash === null || ! hash_equals((string) $link->package_hash, (string) $package->package_hash) => 'package_hash_mismatch',
            default => null,
        };
    }
}
