<?php

namespace App\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case SpecialPrice = 'special_price';
    case Package = 'package';
    case BuyXGetY = 'buy_x_get_y';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed Price',
            self::SpecialPrice => 'Special Price',
            self::Package => 'Package',
            self::BuyXGetY => 'Buy X Get Y',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Types the booking engine can price automatically; the rest are informational. */
    public function isAutoApplicable(): bool
    {
        return in_array($this, [self::Percentage, self::Fixed, self::SpecialPrice], true);
    }
}
