<?php

namespace App\Services;

use App\Support\Reports\DateRange;
use App\Support\Reports\ReportTable;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exports any ReportTable to CSV, Excel (.xlsx) or PDF. */
class ReportExporter
{
    public function export(ReportTable $table, DateRange $range, string $format, string $filename): Response
    {
        return match ($format) {
            'csv' => $this->csv($table, $filename),
            'xlsx' => $this->xlsx($table, $range, $filename),
            'pdf' => $this->pdf($table, $range, $filename),
        };
    }

    private function csv(ReportTable $table, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($table) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₱ correctly
            fputcsv($out, array_map(fn ($k) => $table->heading($k), array_keys($table->columns)));
            foreach ([...$table->rows, ...($table->totals ? [$table->totals] : [])] as $row) {
                fputcsv($out, array_map(fn ($k) => $this->safe($row[$k] ?? ''), array_keys($table->columns)));
            }
            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(ReportTable $table, DateRange $range, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($table, $range) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $bold = (new Style)->withFontBold(true);

            $writer->addRow(Row::fromValues([$table->title.' report', $range->label()], $bold));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(array_map(fn ($k) => $table->heading($k), array_keys($table->columns)), $bold));
            foreach ($table->rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($k) => $this->safe($row[$k] ?? null), array_keys($table->columns))));
            }
            if ($table->totals) {
                $writer->addRow(Row::fromValues(array_map(fn ($k) => $this->safe($table->totals[$k] ?? null), array_keys($table->columns)), $bold));
            }
            $writer->close();
        }, "{$filename}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function pdf(ReportTable $table, DateRange $range, string $filename): Response
    {
        return Pdf::loadView('admin.reports.pdf', [
            'table' => $table,
            'range' => $range,
            'salonName' => app(SettingsService::class)->get('salon_name'),
        ])->setPaper('a4', count($table->columns) > 6 ? 'landscape' : 'portrait')->download("{$filename}.pdf");
    }

    /** Neutralise spreadsheet formula injection from user-controlled text (e.g. service names). */
    private function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
