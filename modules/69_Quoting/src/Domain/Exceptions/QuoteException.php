<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Exceptions;

use DomainException;

/** A rule of the quoting module refused the operation (rendered as 422 by the controllers). */
class QuoteException extends DomainException {}
