<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — Проект «Семейная аптечка»
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

// Интерактивная веб-документация API (Scalar UI)
Route::get('/docs', function () {
    return view('docs');
});

// Прямая отдача спецификаций OpenAPI 3.1
Route::get('/openapi.json', function () {
    $path = public_path('openapi.json');
    if (! file_exists($path)) {
        abort(404, 'OpenAPI JSON specification file not found.');
    }
    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/json; charset=utf-8',
    ]);
});

Route::get('/openapi.yaml', function () {
    $path = public_path('openapi.yaml');
    if (! file_exists($path)) {
        abort(404, 'OpenAPI YAML specification file not found.');
    }
    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/x-yaml; charset=utf-8',
    ]);
});
