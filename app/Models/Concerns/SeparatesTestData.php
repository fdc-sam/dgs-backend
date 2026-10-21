<?php

namespace App\Models\Concerns;

use App\Support\TestMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A record on the test or live side (is_test). Queries only return the current side's records (see TestMode), and a
 * new record lands on testFlagForNewRecord()'s side unless is_test was set explicitly.
 */
trait SeparatesTestData
{
    public static function bootSeparatesTestData(): void
    {
        static::addGlobalScope('test_data', function (Builder $query) {
            $side = TestMode::side();
            if ($side !== null) {
                $query->where($query->qualifyColumn('is_test'), $side);
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('is_test') === null) {
                $model->is_test = $model->testFlagForNewRecord();
            }
        });
    }

    /** The side of whoever is creating it (an admin recording a walk-in); live when run outside a request. */
    protected function testFlagForNewRecord(): bool
    {
        return TestMode::side() ?? false;
    }
}
