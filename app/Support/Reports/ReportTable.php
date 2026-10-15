<?php

namespace App\Support\Reports;

/** Uniform report payload rendered by the report view and every exporter. */
final class ReportTable
{
    /**
     * @param  array<string, string>  $columns  key => heading; headings starting with "₱" format as money, "%" as percent
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  array{type: string, labels: list<string>, series: list<array{label: string, data: list<float|int>}>, money?: bool}|null  $chart
     * @param  array<string, string>  $summary  headline figures label => formatted value
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly ?array $chart = null,
        public readonly array $summary = [],
    ) {}

    public function format(string $key, mixed $value): string
    {
        $heading = $this->columns[$key] ?? '';

        return match (true) {
            $value === null => '—',
            str_starts_with($heading, '₱') => '₱'.number_format((float) $value, 2),
            str_starts_with($heading, '%') => number_format((float) $value, 1).'%',
            is_float($value) => number_format($value, 1),
            is_int($value) => number_format($value),
            default => (string) $value,
        };
    }

    /** Heading without its format marker. */
    public function heading(string $key): string
    {
        return ltrim($this->columns[$key], '₱% ');
    }
}
