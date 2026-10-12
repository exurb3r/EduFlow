<?php

declare(strict_types=1);

namespace App\Services\Payments;

/** A sender-fee ceiling is enforced by the signer, not a post-payment estimate. */
interface FeeBoundedPaymentTransport extends AsyncPaymentTransport
{
    public function supportsFeeCeiling(): bool;
}
