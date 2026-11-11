<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Concerns;

/**
 * Marker for the models whose rows may be global: a null tenant_id means "visible to every tenant" (lookups,
 * translations, catalogues, legal reference data, platform settings). BelongsToTenant reads it through
 * allowsGlobalRecords(): without it a null tenant_id is never readable and never writable.
 *
 * A global row is created only by createGlobal(), makeGlobal() or inside TenantContext::runAsGlobal(), and changed from
 * a tenant context only by a platform administrator.
 */
trait AllowsGlobalRecords {}
