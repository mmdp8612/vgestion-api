<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'VisualGestion API',
        'documentation' => url('/docs'),
    ]);
});

Route::view('/docs', 'docs')->name('docs');
