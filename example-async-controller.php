<?php

/**
 * Example: Async Controller with SFPHP
 *
 * This example shows how to use async/await in a controller
 * to load multiple pieces of data concurrently.
 */

namespace App\Controllers;

use SfphpProject\src\Async\Context;
use SfphpProject\src\Async\Scheduler;
use SfphpProject\src\Async\CompositeFuture;
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;

// Assume we have User, Post, Comment models
// use App\Models\User;
// use App\Models\Post;
// use App\Models\Comment;

class DashboardController
{
    /**
     * Show the dashboard with user info, posts, and comments
     *
     * This method loads three pieces of data concurrently:
     * 1. User profile
     * 2. Recent posts
     * 3. Recent comments
     *
     * Without async: ~300ms (100ms + 150ms + 50ms sequential)
     * With async: ~150ms (max of 100ms, 150ms, 50ms concurrent)
     */
    public function show(int $userId)
    {
        // Create a Scheduler for this request
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            // Load multiple pieces of data concurrently
            [$user, $posts, $comments] = await(
                CompositeFuture::all(
                    // Load user profile
                    async(function () use ($userId) {
                        echo "[Dashboard] Loading user...\n";
                        return User::query()->findAsync($userId);
                    }),

                    // Load recent posts
                    async(function () use ($userId) {
                        echo "[Dashboard] Loading posts...\n";
                        return Post::query()
                            ->where('user_id', $userId)
                            ->orderBy('created_at', 'desc')
                            ->limit(10)
                            ->getAsync();
                    }),

                    // Load recent comments
                    async(function () use ($userId) {
                        echo "[Dashboard] Loading comments...\n";
                        return Comment::query()
                            ->where('user_id', $userId)
                            ->orderBy('created_at', 'desc')
                            ->limit(10)
                            ->getAsync();
                    }),
                )
            );

            // All three queries completed concurrently
            echo "[Dashboard] Data loaded!\n";

            // Return view with all data
            return [
                'user' => $user,
                'posts' => $posts,
                'comments' => $comments,
            ];
        } finally {
            // Clean up context
            Context::popScheduler();
        }
    }

    /**
     * Search users with their recent posts
     *
     * This shows how to use async with multiple operations
     */
    public function search(string $query)
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            // Find users matching the search
            $users = await(
                User::query()
                    ->whereLike('name', "%$query%")
                    ->limit(10)
                    ->getAsync()
            );

            // For each user, load their recent posts concurrently
            $userIds = array_map(fn ($user) => $user->id, $users);

            $postsByUser = await(
                CompositeFuture::all(
                    ...array_map(
                        fn ($userId) => async(function () use ($userId) {
                            $posts = await(
                                Post::query()
                                    ->where('user_id', $userId)
                                    ->limit(5)
                                    ->getAsync()
                            );
                            return [$userId => $posts];
                        }),
                        $userIds
                    )
                )
            );

            // Merge results
            $posts = array_merge(...$postsByUser);

            return [
                'users' => $users,
                'posts' => $posts,
            ];
        } finally {
            Context::popScheduler();
        }
    }

    /**
     * Handle a page with multiple sections loaded in parallel
     *
     * This demonstrates how async can handle complex layouts
     * with multiple data sources
     */
    public function complexPage(int $userId)
    {
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            // Load all sections in parallel
            $sections = await(
                CompositeFuture::all(
                    // Profile section
                    async(fn () =>
                        User::query()->findAsync($userId)
                    ),

                    // Timeline section
                    async(fn () =>
                        Post::query()
                            ->where('user_id', $userId)
                            ->orderBy('created_at', 'desc')
                            ->limit(20)
                            ->getAsync()
                    ),

                    // Followers section
                    async(fn () =>
                        User::query()
                            ->join('followers', 'users.id', '=', 'followers.follower_id')
                            ->where('followers.user_id', $userId)
                            ->select('users.*')
                            ->getAsync()
                    ),

                    // Notifications section
                    async(fn () =>
                        Notification::query()
                            ->where('user_id', $userId)
                            ->where('read', false)
                            ->getAsync()
                    ),

                    // Recent activity
                    async(fn () =>
                        Activity::query()
                            ->where('user_id', $userId)
                            ->orderBy('created_at', 'desc')
                            ->limit(30)
                            ->getAsync()
                    ),
                )
            );

            list($profile, $posts, $followers, $notifications, $activity) = $sections;

            return [
                'profile' => $profile,
                'posts' => $posts,
                'followers' => $followers,
                'notifications' => $notifications,
                'activity' => $activity,
            ];
        } finally {
            Context::popScheduler();
        }
    }
}

/**
 * Example of integrating async into your request pipeline
 *
 * Your HTTP router/framework should be modified to:
 * 1. Create a Scheduler at the start of request handling
 * 2. Push it to Context
 * 3. Call your controller methods
 * 4. Pop the context when done
 */
class AsyncRequestHandler
{
    public function handleRequest($controller, $method, $params)
    {
        // Create scheduler for this request
        $scheduler = new Scheduler();
        Context::pushScheduler($scheduler);

        try {
            // Call the controller method
            $result = $controller->{$method}(...$params);

            // Return the result
            return $result;
        } finally {
            // Clean up
            Context::popScheduler();
        }
    }
}

/**
 * Output example (when these queries actually run with real database):
 *
 * [Dashboard] Loading user...
 * [Dashboard] Loading posts...
 * [Dashboard] Loading comments...
 * [Dashboard] Data loaded!
 *
 * Total time: ~150ms instead of ~300ms
 * 50% faster!
 */
