<?php

namespace App\Support\Payments;

/** The provider refused or failed a call. The message is safe to show to staff (no keys, no card data). */
class GatewayError extends \RuntimeException
{
}
