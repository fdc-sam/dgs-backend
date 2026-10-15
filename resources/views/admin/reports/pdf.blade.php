<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $table->title }} report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1F1B13; }
        h1 { font-size: 18px; margin: 0; color: #7A5900; }
        .meta { color: #4E4639; margin: 4px 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #FAF3E8; text-align: left; padding: 6px; border-bottom: 1px solid #D2C5B4; font-size: 9px; text-transform: uppercase; }
        td { padding: 5px 6px; border-bottom: 1px solid #EFE6D8; }
        .num { text-align: right; }
        tfoot td { font-weight: bold; background: #FAF3E8; }
        .summary td { border: none; padding: 2px 12px 2px 0; }
    </style>
</head>
<body>
    <h1>{{ $salonName }} — {{ $table->title }} report</h1>
    <p class="meta">{{ $range->label() }} · generated {{ now()->format('M j, Y g:i A') }}</p>
    @if ($table->summary)
        <table class="summary"><tr>@foreach ($table->summary as $label => $value)<td><strong>{{ $label }}:</strong> {{ $value }}</td>@endforeach</tr></table><br>
    @endif
    <table>
        <thead><tr>@foreach ($table->columns as $key => $h)<th @class(['num' => ! $loop->first])>{{ $table->heading($key) }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($table->rows as $row)
                <tr>@foreach (array_keys($table->columns) as $key)<td @class(['num' => ! $loop->first])>{{ $table->format($key, $row[$key] ?? null) }}</td>@endforeach</tr>
            @endforeach
        </tbody>
        @if ($table->totals)
            <tfoot><tr>@foreach (array_keys($table->columns) as $key)<td @class(['num' => ! $loop->first])>{{ $loop->first ? $table->totals[$key] : ($table->totals[$key] === null ? '' : $table->format($key, $table->totals[$key])) }}</td>@endforeach</tr></tfoot>
        @endif
    </table>
</body>
</html>
