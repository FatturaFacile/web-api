<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AgentCommissions\Models\Zone;

/**
 * Maps a customer to the commission zones it belongs to, from the country of the contact and the province / canton of
 * its default address. A zone without regions covers its whole country; a zone without country covers everything.
 */
final class ZoneResolver
{
    /** @var Collection<int, Zone>|null */
    private ?Collection $zones = null;

    /** @var array<string, list<string>> */
    private array $memo = [];

    public function flush(): void
    {
        $this->zones = null;
        $this->memo = [];
    }

    /**
     * @return list<string> ids of the matching zones
     */
    public function zoneIdsFor(?string $contactId): array
    {
        if ($contactId === null) {
            return [];
        }

        if (isset($this->memo[$contactId])) {
            return $this->memo[$contactId];
        }

        $country = DB::table('contacts')->where('id', $contactId)->value('country');
        $address = static fn (): Builder => DB::table('contact_addresses')
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderByRaw("CASE WHEN type = 'billing' THEN 0 ELSE 1 END");
        $stateCanton = $address()->value('state_canton');
        $addressCountry = $address()->value('country') ?? $country;
        $region = $stateCanton === null ? null : mb_strtoupper(trim((string) $stateCanton));
        $addressCountry = $addressCountry === null ? null : mb_strtoupper((string) $addressCountry);

        return $this->memo[$contactId] = $this->zones()
            ->filter(static function (Zone $zone) use ($addressCountry, $region): bool {
                if ($zone->country !== null && mb_strtoupper((string) $zone->country) !== $addressCountry) {
                    return false;
                }

                $regions = array_map(static fn (mixed $code): string => mb_strtoupper(trim((string) $code)), $zone->regions ?? []);

                return $regions === [] || ($region !== null && in_array($region, $regions, true));
            })
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Zone>
     */
    private function zones(): Collection
    {
        return $this->zones ??= Zone::query()->where('is_active', true)->get();
    }
}
