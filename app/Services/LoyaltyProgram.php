<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\Service;
use App\Support\Fmt;

/**
 * What the salon's loyalty card offers: the loyalty prices set on services, the other perks printed on the card
 * (Settings → Loyalty card & referrals) and the GCash reward per service for referring someone. Rewards are matched to a referral's services by name, so
 * "Haircut" covers "Men's Haircut" and "Rebond" covers "Hair Rebonding".
 */
class LoyaltyProgram
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<string> */
    public function perks(): array
    {
        return collect(preg_split('/\R/', (string) $this->settings->get('loyalty_card_perks', '')))
            ->map(fn ($line) => trim(ltrim(trim($line), '-•*')))
            ->filter()->values()->all();
    }

    /**
     * The loyalty prices set on services (Services → edit → Loyalty card prices), listed in the app above the typed
     * perks: every active service where a card holder pays less than the regular price. A price that is not below the
     * regular price, or a return price that is turned off, is left out.
     *
     * @return list<array{service_id: string, service: string, regular_price: float, card_holder_price: ?float, return_price: ?float, return_period: ?int, return_period_unit: ?string}>
     */
    public function servicePrices(): array
    {
        return Service::active()->ordered()
            ->where(fn ($q) => $q->where('loyalty_price', '>', 0)->orWhere('return_price', '>', 0))
            ->get()
            ->map(function (Service $service) {
                $regular = (float) $service->regular_price;
                $below = fn ($price) => (float) $price > 0 && (float) $price < $regular ? round((float) $price, 2) : null;
                $return = $service->first_visit_discount_enabled && (int) $service->first_visit_discount_period > 0
                    ? $below($service->return_price) : null;

                return [
                    'service_id' => $service->id,
                    'service' => $service->name,
                    'regular_price' => round($regular, 2),
                    'card_holder_price' => $below($service->loyalty_price),
                    'return_price' => $return,
                    'return_period' => $return === null ? null : (int) $service->first_visit_discount_period,
                    'return_period_unit' => $return === null ? null : $service->first_visit_discount_unit,
                ];
            })
            ->filter(fn ($row) => $row['card_holder_price'] !== null || $row['return_price'] !== null)
            ->values()->all();
    }

    /** @return list<array{service: string, amount: float}> */
    public function referralRewards(): array
    {
        $rows = $this->settings->get('referral_rewards', []);

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($row) => is_array($row) && filled($row['service'] ?? null) && (float) ($row['amount'] ?? 0) > 0)
            ->map(fn ($row) => ['service' => trim((string) $row['service']), 'amount' => round((float) $row['amount'], 2)])
            ->values()->all();
    }

    /**
     * The reward to suggest when completing a referral: each reward whose name appears in one of the referral's
     * services (or their category), counted once.
     *
     * @return array{amount: float, summary: string}|null null when no reward matches
     */
    public function suggestedReward(Referral $referral): ?array
    {
        $services = $referral->services->map(fn ($s) => self::key($s->name.' '.$s->category?->name))->all();
        $matched = collect($this->referralRewards())
            ->filter(fn ($reward) => self::key($reward['service']) !== ''
                && collect($services)->contains(fn ($name) => str_contains($name, self::key($reward['service']))))
            ->values();

        return $matched->isEmpty() ? null : [
            'amount' => $matched->sum('amount'),
            'summary' => $matched->map(fn ($r) => $r['service'].' '.Fmt::peso($r['amount']))->join(' + '),
        ];
    }

    /** "Men's Hair Cut" → "menshaircut": letters and digits only, so spacing and punctuation don't matter. */
    private static function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    }
}
