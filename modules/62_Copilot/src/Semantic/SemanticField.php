<?php

declare(strict_types=1);

namespace Modules\Copilot\Semantic;

/**
 * A column the Semantic Query DSL may reference. `sensitiveModule` names a module of config/permissions.php whose read access
 * is needed to see the value (salaries need payroll, whatever the access to the entity itself).
 */
final readonly class SemanticField
{
    public const string String = 'string';

    public const string Number = 'number';

    public const string Date = 'date';

    public const string Enum = 'enum';

    public const string Uuid = 'uuid';

    public function __construct(
        public string $type,
        public ?string $sensitiveModule = null,
    ) {}
}
