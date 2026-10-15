<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Product;
use App\Services\InventoryService;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $products = Product::query()
            ->with(['services' => fn ($q) => $q->ordered()])
            ->withExists('stockMovements as stock_counted')
            ->when($request->query('search'), function ($query, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('brand', $like));
            })
            ->when($request->query('stock') === 'low', fn ($q) => $q->needsRestock())
            ->ordered()
            ->paginate(25)->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'lowCount' => Product::query()->needsRestock()->count(),
            'uncounted' => Product::query()->whereDoesntHave('stockMovements')->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', ['product' => new Product]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $this->inventory->saveProduct(null, $request->validated());

        return redirect()->route('products.index')->with('success', 'Product added. Choose which services use it from each service\'s page.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.form', [
            'product' => $product->load(['services' => fn ($q) => $q->ordered()]),
            'movements' => $product->stockMovements()->with(['user', 'source'])->paginate(15, pageName: 'history'),
        ]);
    }

    /** A delivery arrived: add it to stock. */
    public function restock(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $this->inventory->restock($product, (float) $data['quantity'], $data['note'] ?? null);

        return back()->with('success', 'Added '.Fmt::quantity($data['quantity'])." {$product->unit} of {$product->name}. In stock: ".Fmt::quantity($product->stock_quantity)." {$product->unit}.");
    }

    /** Set stock to what was physically counted. */
    public function count(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $movement = $this->inventory->countStock($product, (float) $data['counted'], $data['note'] ?? null);

        return back()->with('success', $movement
            ? "{$product->name} stock set to ".Fmt::quantity($data['counted'])." {$product->unit}."
            : "{$product->name} stock already matches the count.");
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->inventory->saveProduct($product, $request->validated());

        return redirect()->route('products.index')->with('success', 'Product updated. New costs apply to services completed from now on.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->inventory->deleteProduct($product);

        return redirect()->route('products.index')->with('success', 'Product deleted. Completed visits keep their recorded costs.');
    }
}
