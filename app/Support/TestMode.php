<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Test and live data never mix. Which side a piece of code works on:
 *  - the signed-in user's (users.is_test), in the admin web, the admin app and the customer app;
 *  - live for a guest web or API request (public website, catalog, availability);
 *  - both (null) in console commands and queued jobs, e.g. reminders and payment deadlines.
 * Appointments, walk-ins and reviews are filtered by a global scope (Models\Concerns\SeparatesTestData), customers by
 * User::customers(); raw report queries call constrain().
 */
final class TestMode
{
    private bool $inRequest = false;

    private ?bool $forced = null;

    /** true = test side, false = live side, null = no filtering. */
    public static function side(): ?bool
    {
        $mode = app(self::class);
        if ($mode->forced !== null) {
            return $mode->forced;
        }

        $user = Auth::user() ?? (request()->bearerToken() ? Auth::guard('sanctum')->user() : null);
        if ($user) {
            return (bool) $user->is_test;
        }

        return $mode->inRequest ? false : null;
    }

    /** Run $callback on one side regardless of who is signed in, e.g. acting on a test customer from the website. */
    public static function on(bool $test, Closure $callback): mixed
    {
        $mode = app(self::class);
        $previous = $mode->forced;
        $mode->forced = $test;

        try {
            return $callback();
        } finally {
            $mode->forced = $previous;
        }
    }

    /** Limit a raw query (DB::table) to the current side by one of its is_test columns, e.g. "appointments.is_test". */
    public static function constrain(Builder $query, string $column): Builder
    {
        $side = self::side();

        return $side === null ? $query : $query->where($column, $side);
    }

    /** Called for every web/API request (middleware), so guests are on the live side. */
    public function startRequest(): void
    {
        $this->inRequest = true;
    }
}
