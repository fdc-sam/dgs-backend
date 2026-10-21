<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');

        return view('admin.reviews.index', [
            'reviews' => Review::query()->with(['customer', 'service', 'images', 'appointment.staff.user'])
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->when($request->query('rating'), fn ($q, $r) => $q->where('rating', $r))
                ->latest()->paginate(self::PER_PAGE)->withQueryString(),
            'status' => $status,
            'counts' => Review::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function approve(Review $review): RedirectResponse
    {
        $this->authorize('moderate', $review);
        $this->reviews->moderate($review, ReviewStatus::Approved);

        return back()->with('success', 'Review approved and now visible in the app.');
    }

    public function reject(Review $review): RedirectResponse
    {
        $this->authorize('moderate', $review);
        $this->reviews->moderate($review, ReviewStatus::Rejected);

        return back()->with('success', 'Review rejected.');
    }

    public function destroy(Review $review): RedirectResponse
    {
        $this->authorize('delete', $review);
        $this->reviews->delete($review);

        return back()->with('success', 'Review deleted.');
    }
}
