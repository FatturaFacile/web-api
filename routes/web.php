<?php

use Illuminate\Support\Facades\Route;

// Redirect root API traffic to public website
Route::get('/', function () {
    return redirect()->away('https://www.fatturafacile.eu', 301);
});

// Direct OpenAPI spec file routes for Swagger UI / tooling
Route::get('/docs/api-docs.json', function () {
    $path = storage_path('api-docs/api-docs.json');
    abort_unless(file_exists($path), 404);

    return response()->file($path, ['Content-Type' => 'application/json']);
});

Route::get('/docs/api-docs.yaml', function () {
    $path = storage_path('api-docs/api-docs.yaml');
    abort_unless(file_exists($path), 404);

    return response()->file($path, ['Content-Type' => 'application/x-yaml']);
});
