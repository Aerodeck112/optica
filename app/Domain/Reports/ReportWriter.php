<?php

namespace App\Domain\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes a report table to XLSX, CSV or PDF with the filters and the generation date (RAP-05).
 */
class ReportWriter
{
    /** @param array{title: string, headers: list<string>, rows: list<list<mixed>>} $table */
    public function write(array $table, string $format, string $path, array $filterLines, string $locale): void
    {
        $generated = __('reports.generated', ['date' => now(config('optica.clinic_timezone'))->format('d.m.Y H:i')], $locale);
        match ($format) {
            'csv' => $this->csv($table, $path, $filterLines, $generated),
            'xlsx' => $this->xlsx($table, $path, $filterLines, $generated),
            'pdf' => $this->pdf($table, $path, $filterLines, $generated, $locale),
        };
    }

    private function csv(array $table, string $path, array $filters, string $generated): void
    {
        $out = fopen($path, 'w');
        // BOM + semicolons: opens correctly in Excel with Romanian and Hungarian regional settings.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$table['title']], ';');
        foreach ([...$filters, $generated] as $line) {
            fputcsv($out, [$line], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, $table['headers'], ';');
        foreach ($table['rows'] as $row) {
            fputcsv($out, array_map(fn ($v) => is_float($v) ? str_replace('.', ',', (string) $v) : $this->safe($v), $row), ';');
        }
        fclose($out);
    }

    /** Text that starts like a formula is prefixed, so a spreadsheet never executes it. */
    private function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function xlsx(array $table, string $path, array $filters, string $generated): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([$table['title']]));
        foreach ([...$filters, $generated] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues($table['headers']));
        foreach ($table['rows'] as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => $this->safe($v), $row)));
        }
        $writer->close();
    }

    private function pdf(array $table, string $path, array $filters, string $generated, string $locale): void
    {
        $html = view('pdf.report', ['table' => $table, 'filters' => $filters, 'generated' => $generated, 'locale' => $locale])->render();
        Pdf::loadHTML($html)->setPaper('a4', count($table['headers']) > 6 ? 'landscape' : 'portrait')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])->save($path);
    }
}
