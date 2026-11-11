<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Modules\Analytics\Support\ConvertedAmountSql;

/**
 * The catalogue of the datasets a report can be built on. Datasets are declared in code ({@see StandardDatasets}, or
 * registered by another module with {@see extend()}); a user sees and runs only the datasets of the modules they can
 * read, with the same rule as the rest of the API (config/permissions.php).
 */
final class SemanticCatalog
{
    /**
     * @var array<string, Dataset>
     */
    private array $extra = [];

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly ModuleAccessGate $gate,
        private readonly TenantContext $tenantContext,
        private readonly ConvertedAmountSql $sql,
    ) {}

    /**
     * Registers a dataset declared by a module. Its key must be new.
     */
    public function extend(Dataset $dataset): void
    {
        $this->extra[$dataset->key] = $dataset;
    }

    /**
     * Every dataset, readable or not. Rebuilt on each call because the amounts depend on the accounting currency of the
     * active company.
     *
     * @return array<string, Dataset>
     */
    public function all(): array
    {
        $datasets = [];

        foreach ([...(new StandardDatasets($this->sql))->all(), ...array_values($this->extra)] as $dataset) {
            $datasets[$dataset->key] = $dataset;
        }

        return $datasets;
    }

    public function find(string $key): ?Dataset
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether the user can read the module the dataset is drawn from, in the active company.
     */
    public function canRead(Dataset $dataset, User $user): bool
    {
        return $this->gate->allows($user, $this->tenantContext->getTenantId(), $dataset->module, 'read');
    }

    /**
     * The datasets the user may use.
     *
     * @return array<string, Dataset>
     */
    public function readableBy(User $user): array
    {
        return array_filter($this->all(), fn (Dataset $dataset): bool => $this->canRead($dataset, $user));
    }
}
