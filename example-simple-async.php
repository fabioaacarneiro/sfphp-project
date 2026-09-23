<?php

/**
 * Example: Using Async in Controllers with Auto-Setup
 *
 * With the EnableAsync middleware, controllers automatically have async support.
 * No need for manual Scheduler setup!
 */

namespace App\Controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Async\CompositeFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

// Assume models exist:
// use App\Models\User;
// use App\Models\Post;
// use App\Models\Comment;

/**
 * Simple async controller - no boilerplate!
 *
 * The EnableAsync middleware automatically handles Scheduler setup,
 * so controllers can just use await() directly.
 */
class UserController
{
    /**
     * Show user profile with all data loaded concurrently
     *
     * Before: 300ms (3 queries sequentially)
     * After:  150ms (3 queries concurrently)
     */
    public function show(Request $request, int $id): Response
    {
        // No manual scheduler setup needed!
        // EnableAsync middleware handles it automatically

        try {
            // Load 3 things in parallel
            [$user, $posts, $comments] = await(
                CompositeFuture::all(
                    async(fn () => User::query()->findAsync($id)),
                    async(fn () =>
                        Post::query()
                            ->where('user_id', $id)
                            ->orderBy('created_at', 'desc')
                            ->limit(10)
                            ->getAsync()
                    ),
                    async(fn () =>
                        Comment::query()
                            ->where('user_id', $id)
                            ->orderBy('created_at', 'desc')
                            ->limit(10)
                            ->getAsync()
                    ),
                )
            );

            if (!$user) {
                return Response::json(['error' => 'User not found'], 404);
            }

            // Return view with all data
            return Response::view('user.profile', [
                'user' => $user,
                'posts' => $posts,
                'comments' => $comments,
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Search users with their stats
     *
     * Loads users and their stats concurrently
     */
    public function search(Request $request, string $q = ''): Response
    {
        try {
            // Find users matching search
            $users = await(
                User::query()
                    ->whereLike('name', "%$q%")
                    ->limit(10)
                    ->getAsync()
            );

            if (empty($users)) {
                return Response::json(['users' => [], 'stats' => []]);
            }

            // Load stats for all users concurrently
            $userIds = array_map(fn ($u) => $u->id, $users);

            $stats = await(
                CompositeFuture::all(
                    ...array_map(
                        fn ($userId) => async(function () use ($userId) {
                            return [
                                'user_id' => $userId,
                                'post_count' => await(
                                    Post::query()
                                        ->where('user_id', $userId)
                                        ->countAsync()
                                ),
                                'comment_count' => await(
                                    Comment::query()
                                        ->where('user_id', $userId)
                                        ->countAsync()
                                ),
                            ];
                        }),
                        $userIds
                    )
                )
            );

            return Response::json([
                'users' => $users,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Dashboard view with everything loaded in parallel
     *
     * Shows how to build a complex dashboard with multiple data sources
     */
    public function dashboard(Request $request, int $userId): Response
    {
        try {
            // Load all sections concurrently
            $sections = await(
                CompositeFuture::all(
                    async(fn () => User::query()->findAsync($userId)),
                    async(fn () => Post::query()->where('user_id', $userId)->getAsync()),
                    async(fn () => Comment::query()->where('user_id', $userId)->getAsync()),
                    async(fn () => User::query()->where('id', '!=', $userId)->limit(10)->getAsync()),
                )
            );

            list($user, $posts, $comments, $suggestions) = $sections;

            return Response::view('dashboard', [
                'user' => $user,
                'posts' => $posts,
                'comments' => $comments,
                'suggestions' => $suggestions,
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * API endpoint returning JSON
     *
     * Shows async working with JSON responses too
     */
    public function apiProfile(Request $request, int $id): Response
    {
        try {
            $user = await(User::query()->findAsync($id));

            if (!$user) {
                return Response::json(['error' => 'Not found'], 404);
            }

            $posts = await(Post::query()->where('user_id', $id)->limit(5)->getAsync());

            return Response::json([
                'user' => $user,
                'recent_posts' => $posts,
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }
}

/**
 * How to use this in your router/server:
 *
 * In your public/server.php or router setup:
 *
 * use SfphpProject\src\Http\Middleware\EnableAsync;
 *
 * $router = (new Router($container))
 *     ->middleware(new EnableAsync())  // <-- Add this!
 *     ->middleware(new SecurityHeaders())
 *     ->middleware(new SetLocale(...))
 *     // ... other middleware
 *
 * Now all your controllers can use await() directly!
 */
