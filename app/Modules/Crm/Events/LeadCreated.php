<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

class LeadCreated
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead) {}
}
