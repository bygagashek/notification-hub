<?php

namespace App\Messaging;

use RuntimeException;

class IdempotencyConflict extends RuntimeException
{
}
