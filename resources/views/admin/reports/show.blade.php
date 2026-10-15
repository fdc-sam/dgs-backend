@php $exportQuery = fn ($f) => route('reports.show', ['report' => $report, ...request()->only('range', 'from', 'to', 'branch_id'), 'export' => $f]); @endphp
<x-admin.layout :title="$table->title.' report'">
    <x-admin.page-header :title="$table->title.' report'" :back="route('reports.index', request()->only('range', 'from', 'to'))" :subtitle="$range->label()">
        <x-slot:actions>
            <x-admin.button :href="$exportQuery('csv')" variant="outlined" icon="download" size="sm">CSV</x-admin.button>
            <x-admin.button :href="$exportQuery('xlsx')" variant="outlined" icon="table" size="sm">Excel</x-admin.button>
            <x-admin.button :href="$exportQuery('pdf')" variant="outlined" icon="picture_as_pdf" size="sm">PDF</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    @include('admin.reports._range', ['action' => route('reports.show', $report)])

    @if ($table->summary)
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($table->summary as $label => $value)<x-admin.stat :label="$label" :value="$value" icon="insights" tone="gold" />@endforeach
        </div>
    @endif

    @if ($table->chart && count($table->chart['labels']))
        <x-admin.card class="mb-6"><div class="h-72"><canvas data-chart='@json($table->chart)' role="img" aria-label="{{ $table->title }} chart"></canvas></div></x-admin.card>
    @endif

    <x-admin.card :padding="false">
        @if (empty($table->rows))
            <x-admin.empty-state icon="monitoring" title="No data for this period" />
        @else
            @php
                // Days/rows with nothing to report are greyed out so the ones with activity stand out.
                $isZero = fn ($v) => is_int($v) || is_float($v) ? (float) $v == 0.0 : false;
            @endphp
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            @foreach ($table->columns as $key => $heading)
                                {{-- Long headings wrap onto two lines instead of squeezing the first column. --}}
                                <th scope="col" @class(['align-bottom', 'whitespace-nowrap' => $loop->first, '!whitespace-normal min-w-24 max-w-36 text-right leading-snug' => ! $loop->first])>{{ $table->heading($key) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table->rows as $row)
                            @php $empty = collect(array_slice(array_keys($table->columns), 1))->every(fn ($k) => ($row[$k] ?? null) === null || $isZero($row[$k])); @endphp
                            <tr>
                                @foreach (array_keys($table->columns) as $key)
                                    <td @class([
                                        '!py-2.5 whitespace-nowrap',
                                        'font-medium' => $loop->first && ! $empty,
                                        'text-on-surface-variant' => $loop->first && $empty,
                                        'text-right tabular-nums' => ! $loop->first,
                                        'text-outline' => ! $loop->first && $isZero($row[$key] ?? null),
                                    ])>{{ $table->format($key, $row[$key] ?? null) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($table->totals)
                        <tfoot>
                            <tr class="border-t-2 border-outline-variant bg-surface-container font-semibold">
                                @foreach (array_keys($table->columns) as $key)
                                    <td @class(['px-4 py-3 whitespace-nowrap', 'text-right tabular-nums' => ! $loop->first])>{{ $loop->first ? $table->totals[$key] : ($table->totals[$key] === null ? '' : $table->format($key, $table->totals[$key])) }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif
    </x-admin.card>
</x-admin.layout>
