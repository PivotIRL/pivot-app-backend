<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hobby;
use App\Http\Requests\Admin\CategoryRequest;
use Illuminate\Http\Request;

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
        Hobby::create($request->validated());

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
        $category->update($request->validated());

        return redirect()->route('admin.categories.index')->with('success', 'Category updated successfully.');
    }

    public function destroy($id)
    {
        $category = Hobby::findOrFail($id);
        $category->delete();

        return redirect()->route('admin.categories.index')->with('success', 'Category deleted successfully.');
    }
}