@props(['headers' => [], 'stack' => true, 'wide' => [], 'mobileOrder' => [], 'mobileLabels' => [], 'mobileSummary' => []])
{{-- stack: on phones each row becomes a card; wide: headers whose cells take a whole line there. --}}
<div {{ $attributes->class(['table-scroll overflow-x-auto']) }}>
    <table @class(['table-base', 'table-stack' => $stack])>
        @if ($headers)
            <thead>
                <tr>
                    @foreach ($headers as $index => $header)
                        @php
                            $isNumeric = str_starts_with($header, '>');
                            $label = ltrim($header, '>');
                            $rank = $mobileOrder[$label] ?? $index;
                            $isAmount = $isNumeric && preg_match('/price|total|deposit|amount|cost|value/i', $label);
                            $cell = $label === 'Actions' ? 'actions' : (in_array($label, $wide, true) ? 'wide' : ($isAmount ? 'amount' : null));
                        @endphp
                        <th scope="col" @class(['text-right' => str_starts_with($header, '>')])
                            @if ($cell) data-cell="{{ $cell }}" @endif
                            @if ($rank === 0) data-mobile-primary="true" @endif
                            @if (in_array($label, $mobileSummary, true)) data-mobile-summary="true" @endif
                            data-mobile-order="{{ $rank }}"
                            @if (isset($mobileLabels[$label])) data-mobile-label="{{ $mobileLabels[$label] }}" @endif>{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
