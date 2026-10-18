<?php

namespace App\Jobs;

use App\Domain\Reports\ReportFilters;
use App\Models\Location;
use App\Models\Service;
use App\Models\Specialist;

/** Human-readable filter lines printed at the top of every export (RAP-05). */
class ReportFilterDescriber
{
    /** @return list<string> */
    public function lines(ReportFilters $f, string $locale): array
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            $lines = [__('reports.period_line', ['from' => $f->from->format('d.m.Y'), 'to' => $f->to->format('d.m.Y'), 'basis' => __('reports.bases.'.$f->basis)])];
            if ($f->locationIds) {
                $lines[] = __('crm.fields.locations').': '.Location::whereIn('id', $f->locationIds)->get()->map->name()->join(', ');
            }
            if ($f->specialistIds) {
                $lines[] = __('crm.fields.specialist').': '.Specialist::whereIn('id', $f->specialistIds)->pluck('display_name')->join(', ');
            }
            if ($f->serviceIds) {
                $lines[] = __('crm.fields.service').': '.Service::whereIn('id', $f->serviceIds)->get()->map->name()->join(', ');
            }
            if ($f->statuses) {
                $lines[] = __('crm.fields.status').': '.collect($f->statuses)->map(fn ($s) => __('appointments.status.'.$s))->join(', ');
            }
            if ($f->sources) {
                $lines[] = __('reports.source').': '.collect($f->sources)->map(fn ($s) => __('appointments.source.'.$s))->join(', ');
            }
            if ($f->patientType !== 'all') {
                $lines[] = __('reports.patient_types.'.$f->patientType);
            }
            if ($f->locales) {
                $lines[] = __('reports.patient_locale').': '.collect($f->locales)->map(fn ($l) => strtoupper($l))->join(', ');
            }

            return $lines;
        } finally {
            app()->setLocale($previous);
        }
    }
}
