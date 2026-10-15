<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class DateRange
{
    public const PRESETS = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'custom' => 'Custom range'];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $preset,
    ) {}

    public static function fromRequest(Request $request, string $default = 'month'): self
    {
        $preset = array_key_exists($request->query('range'), self::PRESETS) ? $request->query('range') : $default;
        $today = now()->startOfDay();

        if ($preset === 'custom') {
            try {
                $from = CarbonImmutable::parse($request->query('from', $today->startOfMonth()->toDateString()))->startOfDay();
                $to = CarbonImmutable::parse($request->query('to', $today->toDateString()))->startOfDay();
            } catch (\Throwable) {
                [$from, $to] = [$today->startOfMonth(), $today];
            }
            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            // Guard against runaway queries.
            if ($from->diffInDays($to) > 366) {
                $from = $to->subDays(366);
            }

            return new self($from, $to, 'custom');
        }

        return match ($preset) {
            'today' => new self($today, $today, 'today'),
            'week' => new self($today->startOfWeek(), $today->endOfWeek()->startOfDay(), 'week'),
            default => new self($today->startOfMonth(), $today->endOfMonth()->startOfDay(), 'month'),
        };
    }

    public function label(): string
    {
        return $this->from->equalTo($this->to)
            ? $this->from->format('M j, Y')
            : $this->from->format('M j, Y').' – '.$this->to->format('M j, Y');
    }

    /** @return list<string> Y-m-d for every day in range */
    public function days(): array
    {
        $days = [];
        for ($d = $this->from; $d->lte($this->to); $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }
}
