<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Exceptions;

/** A run, proposal or block that does not exist for the tenant. */
final class MrpNotFoundException extends MrpException {}
