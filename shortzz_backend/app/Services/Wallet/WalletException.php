<?php

namespace App\Services\Wallet;

// Thrown inside a DB transaction to roll it back with a message that is safe
// to show the caller ("not enough earnings"), as opposed to a database error.
class WalletException extends \RuntimeException
{
}
