@props(['headers' => []])
<div {{ $attributes->class(['overflow-x-auto']) }}>
    <table class="table-base">
        @if ($headers)
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" @class(['text-right' => str_starts_with($header, '>')])>{{ ltrim($header, '>') }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
