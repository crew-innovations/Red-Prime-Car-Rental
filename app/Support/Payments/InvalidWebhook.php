<?php

namespace App\Support\Payments;

/** A webhook that failed its signature / token check, or could not be read. Nothing is recorded from it. */
class InvalidWebhook extends \RuntimeException
{
}
