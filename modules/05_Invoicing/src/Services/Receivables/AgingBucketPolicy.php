<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Models\Tenant;
use InvalidArgumentException;

/**
 * The aging buckets of the receivables. A bucket is a range of days past the due date; `current` holds what is not yet
 * due. The upper bounds are configurable per company (`preferences.receivables.aging_bounds`, ascending whole days):
 * the default 30, 60, 90 and 120 gives current, 1-30, 31-60, 61-90, 91-120 and 121+. The keys are shared by the API,
 * the provision rates and every client.
 */
final readonly class AgingBucketPolicy
{
    public const string Current = 'current';

    /**
     * @var list<int>
     */
    public const array DefaultBounds = [30, 60, 90, 120];

    /**
     * @param  list<int>  $bounds  ascending upper bounds in days
     */
    public function __construct(private array $bounds = self::DefaultBounds)
    {
        if ($bounds === [] || $bounds[0] < 1) {
            throw new InvalidArgumentException('The aging bounds must start from at least one day.');
        }

        for ($index = 1; $index < count($bounds); $index++) {
            if ($bounds[$index] <= $bounds[$index - 1]) {
                throw new InvalidArgumentException('The aging bounds must be strictly ascending.');
            }
        }
    }

    public static function forTenant(string $tenantId): self
    {
        $settings = Tenant::query()->whereKey($tenantId)->value('settings');
        $settings = is_string($settings) ? (array) json_decode($settings, true) : (array) $settings;
        $bounds = $settings['preferences']['receivables']['aging_bounds'] ?? null;

        if (! is_array($bounds) || $bounds === []) {
            return new self;
        }

        $bounds = array_values(array_map('intval', $bounds));

        try {
            return new self($bounds);
        } catch (InvalidArgumentException) {
            return new self;
        }
    }

    /**
     * @return list<array{key: string, from: int, to: int|null}> `from`/`to` are days past due; `current` is 0..0
     */
    public function buckets(): array
    {
        $buckets = [['key' => self::Current, 'from' => 0, 'to' => 0]];
        $from = 1;

        foreach ($this->bounds as $bound) {
            $buckets[] = ['key' => $from.'_'.$bound, 'from' => $from, 'to' => $bound];
            $from = $bound + 1;
        }

        $buckets[] = ['key' => $from.'_plus', 'from' => $from, 'to' => null];

        return $buckets;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_column($this->buckets(), 'key');
    }

    public function keyFor(int $daysPastDue): string
    {
        if ($daysPastDue <= 0) {
            return self::Current;
        }

        foreach ($this->buckets() as $bucket) {
            if ($bucket['to'] === null || $daysPastDue <= $bucket['to']) {
                return $bucket['key'];
            }
        }

        return $this->buckets()[count($this->buckets()) - 1]['key'];
    }

    /**
     * @return array<string, string>
     */
    public function emptyTotals(): array
    {
        return array_fill_keys($this->keys(), '0.00');
    }

    /**
     * Keys of the buckets that start after `$days` days past due (the "over 90" aggregate of the reports).
     *
     * @return list<string>
     */
    public function keysOver(int $days): array
    {
        return array_column(array_filter($this->buckets(), static fn (array $bucket): bool => $bucket['from'] > $days), 'key');
    }
}
