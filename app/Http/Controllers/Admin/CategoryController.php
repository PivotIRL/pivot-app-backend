<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hobby;
use App\Http\Requests\Admin\CategoryRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Hobby::withCount('activities');

        if ($request->filled('search')) {
            $search = strtolower(trim($request->search));
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
        }

        $categories = $query->orderBy('created_at', 'desc')->paginate(10);

        return view('admin.categories.index', compact('categories'));
    }

    public function store(CategoryRequest $request)
    {
        $data = $request->safe()->except('image');
        $path = $this->storeImage($request);
        if ($path) $data['image_path'] = $path;
        try {
            Hobby::create($data);
        } catch (\Throwable $error) {
            if ($path) Storage::disk('public')->delete($path);
            throw $error;
        }

        return redirect()->route('admin.categories.index')->with('success', 'Category created successfully.');
    }

    public function show($id)
    {
        $category = Hobby::with('activities')->withCount('activities')->findOrFail($id);

        return view('admin.categories.show', compact('category'));
    }

    public function update(CategoryRequest $request, $id)
    {
        $category = Hobby::findOrFail($id);
        $data = $request->safe()->except('image');
        $oldPath = $category->image_path;
        $path = $this->storeImage($request);
        if ($path) $data['image_path'] = $path;
        try {
            $category->update($data);
        } catch (\Throwable $error) {
            if ($path) Storage::disk('public')->delete($path);
            throw $error;
        }
        if ($path && $oldPath) Storage::disk('public')->delete($oldPath);

        return redirect()->route('admin.categories.index')->with('success', 'Category updated successfully.');
    }

    private function storeImage(CategoryRequest $request): ?string
    {
        if (! $request->hasFile('image')) return null;
        $path = $request->file('image')->store('categories', 'public');
        if (! $path) throw \Illuminate\Validation\ValidationException::withMessages(['image' => 'Image upload failed. Please try again.']);
        return $path;
    }

    public function updateActive(Request $request, $id)
    {
        $data = $request->validate(['active' => 'required|boolean']);
        Hobby::findOrFail($id)->update($data);
        return back()->with('success', 'Category status updated successfully.');
    }

    public function destroy($id)
    {
        $category = Hobby::findOrFail($id);
        $path = $category->image_path;
        $category->delete();
        if ($path) Storage::disk('public')->delete($path);

        return redirect()->route('admin.categories.index')->with('success', 'Category deleted successfully.');
    }
}