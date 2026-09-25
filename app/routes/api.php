<?php

/**
 * API Routes — REST Endpoints
 *
 * All routes here return JSON responses (REST APIs).
 * These routes are used to build single-page applications (SPAs)
 * or mobile app backends.
 *
 * Example API routes (uncomment to use):
 *
 * use SfphpProject\app\controllers\UserController;
 * use SfphpProject\src\Router;
 *
 * // Get all users
 * Router::get("/api/users", [UserController::class, 'index']);
 *
 * // Get single user
 * Router::get("/api/users/id:number", [UserController::class, 'show']);
 *
 * // Create user
 * Router::post("/api/users", [UserController::class, 'store']);
 *
 * // Update user
 * Router::put("/api/users/id:number", [UserController::class, 'update']);
 *
 * // Delete user
 * Router::delete("/api/users/id:number", [UserController::class, 'destroy']);
 *
 * @package SfphpProject
 * @subpackage app/routes
 */

use SfphpProject\src\Router;

// Define your REST API routes here
// Example: Router::get("/api/users", [UserController::class, 'index']);
