<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AchievementRequest;
use App\Models\Achievement;
use App\Services\AuditLogger;
use App\Services\FileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AchievementController extends Controller
{
    public function index(): View
    {
        return view('admin.achievements.index', [
            'achievements' => Achievement::query()->latest()->paginate(self::PER_PAGE),
        ]);
    }

    public function create(): View
    {
        return view('admin.achievements.form', ['achievement' => new Achievement]);
    }

    public function store(AchievementRequest $request, FileUploadService $uploads, AuditLogger $audit): RedirectResponse
    {
        $achievement = Achievement::create([
            ...$request->safe()->only(['title', 'description']),
            'image' => $uploads->storeImage($request->file('image'), 'achievements'),
        ]);
        $audit->log('achievement.created', $achievement, null, $achievement->only(['title', 'description']));

        return redirect()->route('achievements.index')->with('success', 'Achievement added to the app home page.');
    }

    public function edit(Achievement $achievement): View
    {
        return view('admin.achievements.form', ['achievement' => $achievement]);
    }

    public function update(AchievementRequest $request, Achievement $achievement, FileUploadService $uploads, AuditLogger $audit): RedirectResponse
    {
        $original = $achievement->only(['title', 'description', 'image']);
        $achievement->fill($request->safe()->only(['title', 'description']));

        if ($request->hasFile('image')) {
            $achievement->image = $uploads->storeImage($request->file('image'), 'achievements', $achievement->image);
        }

        $achievement->save();
        $audit->changes('achievement.updated', $achievement, $original);

        return redirect()->route('achievements.index')->with('success', 'Achievement updated.');
    }

    public function destroy(Achievement $achievement, FileUploadService $uploads, AuditLogger $audit): RedirectResponse
    {
        $audit->log('achievement.deleted', $achievement, $achievement->only(['title', 'description']));
        $uploads->delete($achievement->image);
        $achievement->delete();

        return redirect()->route('achievements.index')->with('success', 'Achievement removed from the app.');
    }
}
