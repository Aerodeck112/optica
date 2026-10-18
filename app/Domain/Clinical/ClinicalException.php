<?php

namespace App\Domain\Clinical;

use RuntimeException;

/** A clinical operation refused for a business reason; the message is translated. */
class ClinicalException extends RuntimeException {}
