<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Exceptions;

use DomainException;

/** A violation of an MRP business rule; the controller turns it into a 422. */
class MrpException extends DomainException {}
