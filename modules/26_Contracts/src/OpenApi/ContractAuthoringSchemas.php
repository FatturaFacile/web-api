<?php

declare(strict_types=1);

namespace Modules\Contracts\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Tag of the contract authoring endpoints.
 */
#[OA\Tag(name: 'Contract Authoring', description: 'Clause library with versions and fallbacks, contract templates, redlining (text versions, diff, comments, negotiation rounds) and e-signature requests with an audit trail')]
final class ContractAuthoringSchemas {}
