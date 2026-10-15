<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Branch;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Services\PromotionService;
use App\Services\SettingsService;
use App\Support\Fmt;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public website (what Google indexes): salon info, services & prices, promotions, branches & hours, reviews.
 * Everything comes from the admin, so the page stays current. Indexing is controlled by the
 * `website_indexing` setting (off until real business data replaces the samples).
 */
class PublicSiteController extends Controller
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct(private readonly SettingsService $settings) {}

    public function home(PromotionService $promotions): View
    {
        $s = $this->settings->all();

        $branches = Branch::query()->active()->with('businessHours')->orderBy('name')->get();
        $categories = ServiceCategory::query()->active()->ordered()
            ->with(['services' => fn ($q) => $q->active()->ordered()->with(['variants' => fn ($v) => $v->active()])])
            ->get()
            ->filter(fn ($c) => $c->services->isNotEmpty())
            ->values();

        $reviews = Review::query()->approved()->with(['customer', 'service'])->latest()->limit(6)->get();
        $ratingCount = Review::query()->approved()->count();

        $data = [
            's' => $s,
            'branches' => $branches->map(fn (Branch $b) => [
                'model' => $b,
                'hours' => $this->hoursFor($b),
                'map_url' => $this->mapUrl($b),
            ]),
            'categories' => $categories,
            'promotions' => $promotions->activeQuery()->limit(6)->get(),
            'achievements' => Achievement::query()->latest()->limit(6)->get(),
            'reviews' => $reviews,
            'ratingAverage' => $ratingCount ? round((float) Review::query()->approved()->avg('rating'), 1) : null,
            'ratingCount' => $ratingCount,
            'logoUrl' => $this->settings->logoUrl(),
            'seo' => $this->seo($s, $categories),
        ];
        $data['jsonLd'] = $this->jsonLd($data);

        return view('site.home', $data);
    }

    public function robots(): Response
    {
        $lines = $this->settings->bool('website_indexing')
            ? ['User-agent: *', 'Allow: /$', 'Disallow: /api/', 'Disallow: /login', 'Disallow: /dashboard', 'Disallow: /storage/', '', 'Sitemap: '.url('/sitemap.xml')]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>'.e(url('/')).'</loc><lastmod>'.now()->toDateString().'</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>'
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** @return array{title: string, description: string, place: string, indexing: bool} */
    private function seo(array $s, $categories): array
    {
        $place = collect([$s['business_city'] ?? '', $s['business_province'] ?? ''])->filter()->join(', ');
        $name = $s['salon_name'];

        // e.g. "DGS Hair Salon & Spa | Hair Salon in Carcar City, Cebu" — matches "salon in <city>" searches.
        $title = $place ? "{$name} | Hair Salon in {$place}" : $name;

        $description = trim((string) ($s['website_description'] ?? ''));
        if ($description === '') {
            $offer = $categories->pluck('name')->take(5)->map(fn ($n) => Str::lower($n))->join(', ', ' and ');
            $description = trim($name.($place ? " in {$place}" : '').($offer ? ": {$offer}." : '.')
                .' Book online'.(filled($s['salon_phone']) ? ' or call '.$s['salon_phone'] : '').'.');
        }

        return [
            'title' => $title,
            'description' => Str::limit($description, 160, '…'),
            'place' => $place,
            'indexing' => (bool) ($s['website_indexing'] ?? false),
        ];
    }

    /** @return list<array{day: string, open: bool, from: ?string, to: ?string, today: bool}> */
    private function hoursFor(Branch $branch): array
    {
        $rows = $branch->businessHours->keyBy('day_of_week');
        $today = now()->dayOfWeek;

        return collect(range(1, 7))->map(fn ($i) => $i % 7)->map(function (int $day) use ($rows, $branch, $today) {
            $row = $rows->get($day);
            $open = $row ? $row->is_open : ($branch->opening_time && $branch->closing_time);

            return [
                'day' => self::DAYS[$day],
                'day_index' => $day,
                'open' => (bool) $open,
                'from' => $open ? Fmt::time($row?->opening_time ?? $branch->opening_time) : null,
                'to' => $open ? Fmt::time($row?->closing_time ?? $branch->closing_time) : null,
                'today' => $day === $today,
            ];
        })->values()->all();
    }

    private function mapUrl(Branch $branch): string
    {
        $query = $branch->latitude && $branch->longitude
            ? $branch->latitude.','.$branch->longitude
            : trim($branch->name.' '.$branch->address);

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }

    /** schema.org HairSalon data for Google (built from the same values the page shows). */
    private function jsonLd(array $data): array
    {
        $s = $data['s'];
        $branches = $data['branches'];
        $prices = $data['categories']->flatMap(fn ($c) => $c->services->map(fn ($svc) => min(
            (float) $svc->regular_price,
            (float) ($svc->variants->min('price') ?? $svc->regular_price),
        )))->filter();

        $place = function (array $branch) use ($s) {
            /** @var Branch $b */
            $b = $branch['model'];

            return array_filter([
                '@type' => 'HairSalon',
                'name' => $b->name,
                'telephone' => $b->phone ?: ($s['salon_phone'] ?: null),
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $b->address ?: null,
                    'addressLocality' => $s['business_city'] ?: null,
                    'addressRegion' => $s['business_province'] ?: null,
                    'addressCountry' => 'PH',
                ]),
                'geo' => $b->latitude && $b->longitude
                    ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $b->latitude, 'longitude' => (float) $b->longitude]
                    : null,
                'hasMap' => $branch['map_url'],
                'openingHoursSpecification' => collect($branch['hours'])->where('open', true)->map(fn ($h) => [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'https://schema.org/'.$h['day'],
                    'opens' => $h['from'],
                    'closes' => $h['to'],
                ])->values()->all() ?: null,
            ]);
        };

        $main = $branches->first();
        $salon = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'HairSalon',
            '@id' => url('/').'#salon',
            'name' => $s['salon_name'],
            'url' => url('/'),
            'logo' => $data['logoUrl'],
            'image' => asset('images/og-share.png'),
            'description' => $data['seo']['description'],
            'telephone' => $s['salon_phone'] ?: null,
            'email' => $s['salon_email'] ?: null,
            'priceRange' => $prices->isNotEmpty()
                ? ($s['currency_symbol'] ?? '₱').number_format($prices->min()).'–'.($s['currency_symbol'] ?? '₱').number_format($prices->max())
                : null,
            'currenciesAccepted' => $s['currency'] ?? 'PHP',
            'sameAs' => filled($s['facebook_url'] ?? null) ? [$s['facebook_url']] : null,
            'hasOfferCatalog' => $data['categories']->isNotEmpty() ? [
                '@type' => 'OfferCatalog',
                'name' => 'Services',
                'itemListElement' => $data['categories']->map(fn ($c) => [
                    '@type' => 'OfferCatalog',
                    'name' => $c->name,
                    'itemListElement' => $c->services->map(fn ($svc) => [
                        '@type' => 'Offer',
                        'itemOffered' => ['@type' => 'Service', 'name' => $svc->name],
                        'price' => number_format((float) $svc->regular_price, 2, '.', ''),
                        'priceCurrency' => $s['currency'] ?? 'PHP',
                    ])->values()->all(),
                ])->values()->all(),
            ] : null,
        ]);

        if ($main) {
            $first = $place($main);
            unset($first['@type'], $first['name']);
            $salon = array_merge($salon, array_filter($first), ['telephone' => $salon['telephone'] ?? ($first['telephone'] ?? null)]);
            if ($branches->count() > 1) {
                $salon['department'] = $branches->slice(1)->map(fn ($b) => $place($b))->values()->all();
            }
        }

        return array_filter($salon, fn ($v) => $v !== null);
    }
}
