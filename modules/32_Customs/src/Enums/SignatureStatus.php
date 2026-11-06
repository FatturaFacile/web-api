<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum SignatureStatus: string
{
    /** The signature and the digests match and the certificate chains up to a trusted BAZG root. */
    case Verified = 'verified';

    /** The signature, a digest or the certificate chain does not match. */
    case Invalid = 'invalid';

    /** The document came without a signature to check, such as from the local channel. */
    case NotSigned = 'not_signed';

    /** A signature is there but its algorithm or structure is not supported. */
    case Unsupported = 'unsupported';
}
