<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /** Rows per page on every paginated admin list. */
    protected const PER_PAGE = 10;
}
