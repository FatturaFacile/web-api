<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs\Exceptions;

use RuntimeException;

/**
 * The dependency network contains a cycle and is therefore not a DAG: no schedule exists.
 */
final class CyclicDependencyException extends RuntimeException
{
    /**
     * @param  list<string>  $nodeIds  the nodes that take part in (or hang from) a cycle
     */
    public function __construct(public readonly array $nodeIds)
    {
        parent::__construct('The dependency network contains a cycle.');
    }
}
