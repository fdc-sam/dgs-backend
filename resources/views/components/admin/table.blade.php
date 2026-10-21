@props(['headers' => [], 'stack' => true, 'wide' => []])
{{-- stack: on phones each row becomes a card (see .table-stack in app.css); wide: headers whose cells take a whole line there. --}}
<div {{ $attributes->class(['overflow-x-auto']) }}>
    <table @class(['table-base', 'table-stack' => $stack])>
        @if ($headers)
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        @php $label = ltrim($header, '>'); @endphp
                        <th scope="col" @class(['text-right' => str_starts_with($header, '>')])
                            @if ($label === 'Actions') data-cell="actions" @elseif (in_array($label, $wide, true)) data-cell="wide" @endif>{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
