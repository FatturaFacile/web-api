<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Domain\Services\SizeRecommender;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\SafetyWaiver;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Models\TicketParticipant;
use Modules\AdventureParks\Models\WaiverTemplate;

/**
 * The liability waiver of a ticket, signed by the adult who answers for the group (and the minors) from the link of the booking.
 * The public endpoints know only the unguessable token of the ticket: it selects the company, and everything runs in its context.
 */
final class WaiverService
{
    private const array Locales = ['it', 'en', 'de', 'fr'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ParkSettings $settings,
        private readonly TicketBookingService $tickets,
    ) {}

    /**
     * Runs `$callback` in the company that owns the ticket with this token.
     *
     * @template TReturn
     *
     * @param  callable(Ticket): TReturn  $callback
     * @return TReturn
     */
    public function withTicketByToken(string $token, callable $callback): mixed
    {
        $found = Ticket::withoutGlobalScopes()->where('public_token', $token)->whereNull('deleted_at')->first();

        if ($found === null || $found->tenant_id === null) {
            throw AdventureParksException::refuse('ticket_not_found', 'The booking link is not valid.', [], 404);
        }

        $tenant = Tenant::query()->find($found->tenant_id);

        if ($tenant === null) {
            throw AdventureParksException::refuse('ticket_not_found', 'The booking link is not valid.', [], 404);
        }

        return $this->tenants->runWithTenant($tenant, fn (): mixed => $callback($this->tickets->fresh((string) $found->id)));
    }

    /**
     * Content of the public waiver page: the booking, whether it is signed, the legal text in the language asked for and the size table.
     *
     * @return array<string, mixed>
     */
    public function page(Ticket $ticket, ?string $locale): array
    {
        $locale = $this->locale($locale);
        $version = $this->settings->waiverVersionId();
        $template = $this->template($version, $locale);

        return [
            'ticket' => [
                'booking_code' => $ticket->booking_code,
                'slot_date' => $ticket->slot_date->toDateString(),
                'start_time' => $ticket->start_time,
                'end_time' => $ticket->end_time,
                'headcount' => $ticket->headcount,
            ],
            'signed' => $ticket->waiver !== null,
            'waiver' => [
                'version_id' => $version,
                'locale' => $template?->locale ?? $locale,
                'template' => [
                    'title' => $template->title ?? '',
                    'body_html_paragraphs' => $template->paragraphs ?? [],
                    'rules' => $template->rules ?? [],
                ],
            ],
            'size_table' => SizeRecommender::fromConfig()->table(),
            'qr_payload' => $ticket->public_token,
        ];
    }

    /**
     * Signs the waiver: the signer and the minors become the participants of the ticket (with their recommended harness size) and the
     * ticket moves to WAIVER_SIGNED. The evidence of the signing (IP address, user agent, time, language, text version) is stored.
     *
     * @param  array<string, mixed>  $data  validated payload of the public sign request
     * @return array{ticket: Ticket, waiver: SafetyWaiver}
     */
    public function sign(string $ticketId, array $data, ?string $ip, ?string $userAgent, ?string $userId = null): array
    {
        $waiver = DB::transaction(function () use ($ticketId, $data, $ip, $userAgent, $userId): SafetyWaiver {
            /** @var Ticket $ticket */
            $ticket = Ticket::query()->with('lines')->lockForUpdate()->findOrFail($ticketId);

            if (SafetyWaiver::query()->where('ticket_id', $ticket->id)->exists()) {
                throw AdventureParksException::refuse('waiver_already_signed', 'The waiver of this booking is already signed.', ['booking_code' => $ticket->booking_code]);
            }

            /** @var array<string, mixed> $signer */
            $signer = $data['signer'];
            /** @var list<array<string, mixed>> $minors */
            $minors = array_values($data['minors'] ?? []);

            $ticket->markWaiverSigned(1 + count($minors));

            $recommender = SizeRecommender::fromConfig();
            $people = [['is_signer' => true, 'is_minor' => false, 'person' => $signer], ...array_map(fn (array $minor): array => ['is_signer' => false, 'is_minor' => true, 'person' => $minor], $minors)];
            $sizes = $this->allocateSizes(
                array_map(fn (array $entry): PpeSize => $recommender->recommend((float) $entry['person']['height_cm'], (float) $entry['person']['weight_kg']), $people),
                $ticket,
            );

            foreach ($people as $index => $entry) {
                $person = $entry['person'];
                (new TicketParticipant([
                    'tenant_id' => $ticket->tenant_id,
                    'ticket_id' => $ticket->id,
                    'full_name' => $person['full_name'],
                    'is_minor' => $entry['is_minor'],
                    'is_signer' => $entry['is_signer'],
                    'birth_date' => $person['birth_date'] ?? null,
                    'height_cm' => $person['height_cm'],
                    'weight_kg' => $person['weight_kg'],
                    'size' => $sizes[$index]->value,
                    'fiscal_code' => $entry['is_signer'] ? ($person['fiscal_code'] ?? null) : null,
                    'phone' => $entry['is_signer'] ? ($person['phone'] ?? null) : null,
                    'email' => $entry['is_signer'] ? ($person['email'] ?? null) : null,
                    'created_by' => $userId,
                ]))->save();
            }

            $waiver = new SafetyWaiver([
                'tenant_id' => $ticket->tenant_id,
                'ticket_id' => $ticket->id,
                'signer_full_name' => $signer['full_name'],
                'signer_fiscal_code' => $signer['fiscal_code'] ?? null,
                'signer_phone' => $signer['phone'] ?? null,
                'signer_email' => $signer['email'] ?? null,
                'signer_birth_date' => $signer['birth_date'] ?? null,
                'signer_height_cm' => $signer['height_cm'],
                'signer_weight_kg' => $signer['weight_kg'],
                'signature_data' => (string) $data['signature_data'],
                'ip_address' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
                'locale' => $this->locale($data['locale'] ?? null),
                'waiver_version_id' => $this->settings->waiverVersionId(),
                'accepted_rules' => true,
                'signed_at' => CarbonImmutable::now(),
                'created_by' => $userId,
            ]);
            $waiver->save();
            $ticket->save();

            return $waiver;
        });

        return ['ticket' => $this->tickets->fresh($ticketId), 'waiver' => $waiver];
    }

    /**
     * Fills the sizes booked on the ticket with the visitors: everybody gets the size recommended for them when the ticket holds one,
     * the others take the closest size that is left on the booking.
     *
     * @param  list<PpeSize>  $recommended
     * @return list<PpeSize>
     */
    private function allocateSizes(array $recommended, Ticket $ticket): array
    {
        $left = [];

        foreach ($ticket->lines as $line) {
            $left[$line->size] = ($left[$line->size] ?? 0) + $line->quantity;
        }

        $order = SlotAvailabilityService::BOOKABLE_SIZES;
        $result = [];

        foreach ($recommended as $index => $size) {
            if (($left[$size->value] ?? 0) > 0) {
                $left[$size->value]--;
                $result[$index] = $size;
            }
        }

        foreach ($recommended as $index => $size) {
            if (isset($result[$index])) {
                continue;
            }

            $wanted = array_search($size->value, $order, true);
            $best = null;

            foreach ($left as $candidate => $quantity) {
                $distance = abs((int) array_search($candidate, $order, true) - (int) $wanted);

                if ($quantity > 0 && ($best === null || $distance < $best[1])) {
                    $best = [$candidate, $distance];
                }
            }

            if ($best === null) {
                $result[$index] = $size;

                continue;
            }

            $left[$best[0]]--;
            $result[$index] = PpeSize::from($best[0]);
        }

        ksort($result);

        return array_values($result);
    }

    private function template(string $versionId, string $locale): ?WaiverTemplate
    {
        $rows = WaiverTemplate::query()->where('version_id', $versionId)->get();

        foreach ([$locale, 'en', 'it'] as $wanted) {
            $own = $rows->first(fn (WaiverTemplate $row): bool => $row->locale === $wanted && $row->tenant_id !== null);
            $global = $rows->first(fn (WaiverTemplate $row): bool => $row->locale === $wanted);

            if ($own !== null || $global !== null) {
                return $own ?? $global;
            }
        }

        return $rows->first();
    }

    private function locale(?string $locale): string
    {
        $locale = strtolower((string) ($locale ?? app()->getLocale()));

        return in_array($locale, self::Locales, true) ? $locale : 'it';
    }
}
