<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\ResidencyScopeType;
use Modules\DataResidency\Domain\Enums\TransferDecision;
use Modules\DataResidency\Domain\Events\UnauthorizedCrossBorderTransferBlockedEvent;
use Modules\DataResidency\Domain\Exceptions\CrossBorderResidencyViolationException;
use Modules\DataResidency\Domain\Exceptions\CrossBorderTransferBlockedException;
use Modules\DataResidency\Domain\ValueObjects\AdequacyRegistry;

/**
 * Where the data of a tenant (legal_entity_id null) or of one legal entity of a group physically lives, and whether
 * egress outside that perimeter is blocked. Once activated (locked_at set) it changes only through the dual-authorised
 * sovereign migration workflow.
 *
 * @property string $id
 * @property string|null $legal_entity_id
 * @property ResidencyScopeType $scope_type
 * @property JurisdictionZone $jurisdiction_zone
 * @property string $primary_db_node_id
 * @property list<string>|null $replica_db_node_ids_json
 * @property string $s3_storage_node_id
 * @property string $vector_db_node_id
 * @property string $ai_inference_node_id
 * @property string $backup_node_id
 * @property string $queue_node_id
 * @property bool $enforce_strict_egress_block
 * @property bool $allow_aggregated_non_pii_consolidation
 * @property Carbon|null $locked_at
 */
final class TenantDataResidencyPolicy extends BaseEnterpriseModel
{
    protected $table = 'core_data_residency_policies';

    /** Node columns of the policy with the service type each one must reference. */
    public const array NODE_COLUMNS = [
        'primary_db_node_id', 's3_storage_node_id', 'vector_db_node_id', 'ai_inference_node_id', 'backup_node_id', 'queue_node_id',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ResidencyScopeType::class,
            'jurisdiction_zone' => JurisdictionZone::class,
            'replica_db_node_ids_json' => 'array',
            'enforce_strict_egress_block' => 'boolean',
            'allow_aggregated_non_pii_consolidation' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SovereignRegionNode, $this>
     */
    public function primaryDbNode(): BelongsTo
    {
        return $this->belongsTo(SovereignRegionNode::class, 'primary_db_node_id');
    }

    /**
     * @return BelongsTo<SovereignRegionNode, $this>
     */
    public function s3StorageNode(): BelongsTo
    {
        return $this->belongsTo(SovereignRegionNode::class, 's3_storage_node_id');
    }

    /**
     * @return BelongsTo<SovereignRegionNode, $this>
     */
    public function vectorDbNode(): BelongsTo
    {
        return $this->belongsTo(SovereignRegionNode::class, 'vector_db_node_id');
    }

    /**
     * @return BelongsTo<SovereignRegionNode, $this>
     */
    public function aiInferenceNode(): BelongsTo
    {
        return $this->belongsTo(SovereignRegionNode::class, 'ai_inference_node_id');
    }

    /**
     * @return BelongsTo<SovereignRegionNode, $this>
     */
    public function backupNode(): BelongsTo
    {
        return $this->belongsTo(SovereignRegionNode::class, 'backup_node_id');
    }

    /**
     * @return BelongsTo<SovereignRegionNode, $this>
     */
    public function queueNode(): BelongsTo
    {
        return $this->belongsTo(SovereignRegionNode::class, 'queue_node_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Strict-CH-Only binds Swiss nodes only, Strict-IT-Only Italian nodes only, the EEA corridor any EEA or Swiss node.
     *
     * @throws CrossBorderResidencyViolationException
     */
    public function assertNodeAllowedForJurisdiction(SovereignRegionNode $node): void
    {
        if (! $this->jurisdiction_zone->allowsCountry($node->country_iso2)) {
            throw new CrossBorderResidencyViolationException(sprintf(
                'Node %s (%s, %s) cannot be bound to a %s policy: allowed countries are %s.',
                $node->node_code,
                $node->city,
                $node->country_iso2,
                $this->jurisdiction_zone->value,
                $this->jurisdiction_zone === JurisdictionZone::EeaPlusAdequacyCh ? 'the EEA and Switzerland' : $this->jurisdiction_zone->allowedCountries()[0],
            ));
        }
    }

    /**
     * Checks a destination against the adequacy lists (Swiss Federal Council Annex 1, EU Art. 45 GDPR) or an explicit
     * rule decision; a non-adequate destination is blocked and announced.
     *
     * @throws CrossBorderTransferBlockedException
     */
    public function validateOutboundEndpoint(string $targetCountryIso2, string $purpose, ?TransferDecision $ruleDecision = null): void
    {
        $iso2 = strtoupper($targetCountryIso2);
        $decision = $ruleDecision ?? AdequacyRegistry::decisionFor($this->jurisdiction_zone, $iso2);
        $blocked = $decision === TransferDecision::Blocked
            || ($decision === TransferDecision::RequiresSccTia && $this->enforce_strict_egress_block);

        if (! $blocked) {
            return;
        }

        $reason = sprintf('Transfer to %s for "%s" is %s under %s.', $iso2, $purpose, $decision->value, $this->jurisdiction_zone->value);
        event(new UnauthorizedCrossBorderTransferBlockedEvent((string) $this->tenant_id, $this->legal_entity_id, $iso2, $purpose, $reason));

        throw new CrossBorderTransferBlockedException($reason);
    }
}
