<?php

namespace App\Http\Controllers;

use App\Models\Research;
use App\Models\UserDailyArticle;
use App\Models\UserArticle;
use App\Http\Resources\ResearchResource;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserDailyArticlesController extends Controller
{
    public function getDailyArticle(Request $request)
    {
        $user = $request->user();

        $timezone = $request->header('Timezone') ?? config('app.timezone');
        $today = Carbon::today($timezone)->toDateString();

        $existingAssignment = UserDailyArticle::where('user_id', $user->id)
            ->where('assigned_date', $today)
            ->first();

        if ($existingAssignment) {
            $article = Research::find($existingAssignment->article_id);
            return response()->json(new ResearchResource($article));
        }

        $alreadySeenIds = UserDailyArticle::where('user_id', $user->id)
            ->pluck('article_id');

        $newArticle = Research::whereNotIn('id', $alreadySeenIds)
            ->whereNotNull('files')
            ->inRandomOrder()
            ->first();

        if (!$newArticle) {
            $newArticle = Research::whereNotNull('files')
                ->inRandomOrder()
                ->first();
        }

        if (!$newArticle) {
            return response()->json(['message' => 'No articles available'], 404);
        }

        UserDailyArticle::create([
            'user_id' => $user->id,
            'article_id' => $newArticle->id,
            'assigned_date' => $today,
        ]);

        return response()->json(new ResearchResource($newArticle));
    }
    
    public function getArticleLibrary(Request $request)
    {
        $user = $request->user();

        $unlockedArticles = Research::join('user_articles', 'research_articles.id', '=', 'user_articles.article_id')
            ->where('user_articles.user_id', $user->id)
            ->orderBy('user_articles.created_at', 'desc')
            ->select('research_articles.*', 'user_articles.created_at', 'user_articles.id as user_article_id')
            ->get();

        return ResearchResource::collection($unlockedArticles);
    }

    public function addArticleLibrary(Request $request)
    {
        $request->validate([
            'article_id' => 'required|exists:research_articles,id'
        ]);

        $user = $request->user();
        $exists = UserArticle::where('user_id', $user->id)
            ->where('article_id', $request->article_id)
            ->first();

        if ($exists) {
            return response()->json(['message' => 'Article already in library'], 400);
        }

        $userArticle = UserArticle::create([
            'user_id' => $user->id,
            'article_id' => $request->article_id,
        ]);

        return response()->json($userArticle, 201);
    }

    public function deleteArticleLibrary(Request $request, $id)
    {
        $user = $request->user();

        $deleted = UserArticle::where('user_id', $user->id)
            ->where('article_id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Article not found in library'], 404);
        }

        return response()->json(['message' => 'Article removed from library']);
    }
}
