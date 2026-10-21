<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Services\CatalogAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function __construct(private readonly CatalogAdminService $catalog) {}

    public function index(Request $request): View
    {
        $services = Service::query()
            ->with(['category', 'products'])
            ->withCount(['variants', 'branches', 'staff'])
            ->when($request->query('search'), fn ($q, $s) => $q->whereLike('name', '%'.addcslashes($s, '%_\\').'%'))
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->ordered()
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.services.index', [
            'services' => $services,
            'categories' => ServiceCategory::ordered()->pluck('name', 'id'),
        ]);
    }

    public function create(): View
    {
        return view('admin.services.form', $this->formData(new Service([
            'status' => RecordStatus::Active, 'buffer_minutes' => 0, 'duration_minutes' => 60, 'is_featured' => false,
        ])));
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $this->catalog->saveService(null, $request->validated(), $request->file('image'), $request->file('gcash_qr'));

        return redirect()->route('services.index')->with('success', 'Service created.');
    }

    public function edit(Service $service): View
    {
        return view('admin.services.form', $this->formData($service->load(['variants', 'branches', 'staff', 'products'])));
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $this->catalog->saveService($service, $request->validated(), $request->file('image'), $request->file('gcash_qr'));

        return redirect()->route('services.index')->with('success', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->catalog->deleteService($service);

        return redirect()->route('services.index')->with('success', 'Service deleted. Past appointments keep their records.');
    }

    private function formData(Service $service): array
    {
        return [
            'service' => $service,
            'categories' => ServiceCategory::ordered()->pluck('name', 'id'),
            'branches' => Branch::orderBy('name')->get(),
            'staffList' => StaffProfile::with('user')->get()->sortBy('user.name'),
            'products' => Product::ordered()->get(),
        ];
    }
}
