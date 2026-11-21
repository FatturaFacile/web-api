<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Crypto;

use Illuminate\Support\Facades\Cache;

/**
 * In-process cache of unwrapped DEKs with a strict TTL (0 = never cached, as for HYOK). Entries are zeroed when they
 * expire or are evicted. Invalidation is cluster-wide: it bumps a per-envelope epoch in the shared cache store (Redis in
 * production) and every node drops an entry whose epoch is older, so a kill-switch reaches all application nodes at once.
 */
final class ShortLivedDekMemoryCache
{
    /** @var array<string, array{dek: string, expires: int, epoch: int}> */
    private array $entries = [];

    public function get(string $envelopeId): ?string
    {
        $entry = $this->entries[$envelopeId] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] <= time() || $entry['epoch'] !== $this->epoch($envelopeId)) {
            $this->forgetLocal($envelopeId);

            return null;
        }

        // A fresh copy: the caller zeroes what it gets, which must never wipe the cached entry.
        return (string) base64_decode($entry['dek'], true);
    }

    public function put(string $envelopeId, string $dek, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        $this->forgetLocal($envelopeId);
        $this->entries[$envelopeId] = ['dek' => base64_encode($dek), 'expires' => time() + $ttlSeconds, 'epoch' => $this->epoch($envelopeId)];
    }

    /**
     * Drops the entry on this node and signals every other node to do the same.
     */
    public function invalidate(string $envelopeId): void
    {
        $this->forgetLocal($envelopeId);
        Cache::put($this->epochKey($envelopeId), $this->epoch($envelopeId) + 1, now()->addDays(30));
    }

    public function has(string $envelopeId): bool
    {
        return isset($this->entries[$envelopeId]);
    }

    public function flush(): void
    {
        foreach (array_keys($this->entries) as $envelopeId) {
            $this->forgetLocal($envelopeId);
        }
    }

    private function forgetLocal(string $envelopeId): void
    {
        if (isset($this->entries[$envelopeId])) {
            $dek = $this->entries[$envelopeId]['dek'];
            unset($this->entries[$envelopeId]);
            sodium_memzero($dek);
        }
    }

    private function epoch(string $envelopeId): int
    {
        return (int) Cache::get($this->epochKey($envelopeId), 0);
    }

    private function epochKey(string $envelopeId): string
    {
        return 'data-residency:dek-epoch:'.$envelopeId;
    }
}
