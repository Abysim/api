<?php

use App\Http\Controllers\BlueskyController;
use App\Http\Controllers\FlickrPhotoController;
use App\Http\Controllers\NewsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::any('/bluesky', [BlueskyController::class, 'index']);

Route::get('flickr-photo', [FlickrPhotoController::class, 'process']);

Route::get('news', [NewsController::class, 'process']);
