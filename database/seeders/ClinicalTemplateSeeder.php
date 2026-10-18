<?php

namespace Database\Seeders;

use App\Models\ClinicalTemplate;
use Illuminate\Database\Seeder;

/**
 * Proposed ophthalmology record built from the fields listed in MED-01. It is created as a DRAFT:
 * the units, ranges and steps are a technical proposal and the template cannot be used until a
 * person with the "medical_records.templates" right validates it (MED-01).
 */
class ClinicalTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (ClinicalTemplate::where('key', 'ophthalmology_general')->exists()) {
            return;
        }

        $f = fn (string $key, string $section, string $type, string $ro, string $hu, array $extra = []) => ['key' => $key, 'section' => $section, 'type' => $type, 'label' => ['ro' => $ro, 'hu' => $hu]] + $extra;
        $refraction = ['eyes' => true, 'signed' => true];

        ClinicalTemplate::create([
            'key' => 'ophthalmology_general',
            'version' => 1,
            'name' => ['ro' => 'Consultație oftalmologică', 'hu' => 'Szemészeti vizsgálat'],
            'status' => 'draft',
            'fields' => [
                $f('reason', 'anamnesis', 'text', 'Motivul prezentării', 'A megjelenés oka', ['required' => true]),
                $f('history', 'anamnesis', 'text', 'Antecedente', 'Kórelőzmény'),
                $f('va_sc', 'acuity', 'number', 'Acuitate vizuală fără corecție', 'Látásélesség korrekció nélkül', ['eyes' => true, 'min' => 0, 'max' => 2, 'step' => 0.01]),
                $f('va_cc', 'acuity', 'number', 'Acuitate vizuală cu corecție', 'Látásélesség korrekcióval', ['eyes' => true, 'min' => 0, 'max' => 2, 'step' => 0.01]),
                $f('sph', 'refraction', 'number', 'Sferă', 'Szféra', $refraction + ['unit' => 'D', 'min' => -30, 'max' => 30, 'step' => 0.25]),
                $f('cyl', 'refraction', 'number', 'Cilindru', 'Cilinder', $refraction + ['unit' => 'D', 'min' => -10, 'max' => 10, 'step' => 0.25]),
                $f('axis', 'refraction', 'number', 'Ax', 'Tengely', ['eyes' => true, 'unit' => '°', 'min' => 0, 'max' => 180, 'step' => 1]),
                $f('add', 'refraction', 'number', 'Adiție', 'Addíció', $refraction + ['unit' => 'D', 'min' => 0, 'max' => 4, 'step' => 0.25]),
                $f('pd', 'refraction', 'number', 'Distanța pupilară', 'Pupillatávolság', ['unit' => 'mm', 'min' => 40, 'max' => 80, 'step' => 0.5]),
                $f('iop', 'pressure', 'number', 'Tensiune intraoculară', 'Szemnyomás', ['eyes' => true, 'unit' => 'mmHg', 'min' => 5, 'max' => 60, 'step' => 1]),
                $f('investigations', 'investigations', 'text', 'Investigații', 'Vizsgálatok'),
                $f('diagnosis', 'conclusion', 'text', 'Diagnostic', 'Diagnózis', ['required' => true]),
                $f('recommendations', 'conclusion', 'text', 'Recomandări', 'Javaslatok'),
                $f('follow_up', 'conclusion', 'date', 'Data controlului', 'Kontroll időpontja'),
            ],
        ]);
    }
}
