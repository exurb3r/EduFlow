<?php

declare(strict_types=1);

namespace App\DTOs;

enum WalletReadState: string
{
    /** The chain was reached and the balance is a live reading. */
    case OK = 'ok';

    /** No address is stored on the student record. */
    case MISSING = 'missing';

    /** An address is stored but does not match Student::PAYOUT_ADDRESS_PATTERN. */
    case INVALID = 'invalid';

    /** The address is usable but the chain could not be read. */
    case UNAVAILABLE = 'unavailable';
}
