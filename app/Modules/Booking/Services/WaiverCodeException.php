<?php

namespace App\Modules\Booking\Services;

use RuntimeException;

/** Codul de pe tabletă nu mai poate fi folosit (folosit deja, expirat sau anulat). */
class WaiverCodeException extends RuntimeException {}
