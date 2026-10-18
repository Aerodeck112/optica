<?php

namespace App\Domain\Finance;

use RuntimeException;

/** A financial operation refused for a business reason; the message is translated. */
class FinanceException extends RuntimeException {}
