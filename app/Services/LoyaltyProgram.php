<?php

namespace App\Services;

use App\Models\Referral;
use App\Support\Fmt;

/**
 * What the salon's loyalty card offers (Settings → Loyalty card & referrals): the perks printed on the card and the
 * GCash reward per service for referring someone. Rewards are matched to a referral's services by name, so
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
