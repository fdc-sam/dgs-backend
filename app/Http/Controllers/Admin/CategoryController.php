<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\ServiceCategory;
use App\Services\AuditLogger;
use App\Services\CatalogAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(private readonly CatalogAdminService $catalog) {}

    public function index(): View
    {
        return view('admin.categories.index', [
            'categories' => ServiceCategory::withCount('services')->ordered()->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.categories.form', ['category' => new ServiceCategory]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $this->catalog->saveCategory(null, $request->validated(), $request->file('image'));

        return redirect()->route('categories.index')->with('success', 'Category created.');
    }

    public function edit(ServiceCategory $category): View
    {
        return view('admin.categories.form', ['category' => $category]);
    }

    public function update(CategoryRequest $request, ServiceCategory $category): RedirectResponse
    {
        $this->catalog->saveCategory($category, $request->validated(), $request->file('image'));

        return redirect()->route('categories.index')->with('success', 'Category updated.');
    }

    public function destroy(ServiceCategory $category, AuditLogger $audit): RedirectResponse
    {
        if ($category->services()->exists()) {
            return back()->with('error', 'Move or delete the services in this category first.');
        }
        $audit->log('category.deleted', $category, ['name' => $category->name]);
        $category->update(['slug' => $category->slug.'-deleted-'.now()->timestamp]);
        $category->delete();

        return redirect()->route('categories.index')->with('success', 'Category deleted.');
    }
}
