<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Exceptions;

/** Invalid, expired or superseded public link, wrong PIN or OTP: the portal answers 403/404 without detail. */
final class QuoteAccessDeniedException extends QuoteException {}
