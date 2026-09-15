<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BienesraicesController;
use App\Http\Controllers\Api\BrokersController;
use App\Http\Controllers\Api\ConsultasController;
use App\Http\Controllers\Api\UbicacionesController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::get('/bienesraices', [BienesraicesController::class, 'index'])
    ->middleware(['broker.auth', 'throttle:api']);

Route::get('/bienesraices/{idBroker}/{idBienes}', [BienesraicesController::class, 'show'])
    ->where([
        'idBroker' => '[A-Za-z0-9]{1,6}',
        'idBienes' => '[0-9]+(?:\.[0-9]+)?',
    ])
    ->middleware(['broker.auth', 'throttle:api']);

Route::get('/ubicaciones', [UbicacionesController::class, 'index'])
    ->middleware(['broker.auth', 'throttle:api']);

Route::post('/consultas', [ConsultasController::class, 'store'])
    ->middleware(['broker.auth', 'throttle:consultas']);

Route::get('/brokers/{idBroker}', [BrokersController::class, 'show'])
    ->where('idBroker', '[A-Za-z0-9]{1,6}')
    ->middleware(['broker.auth', 'throttle:api']);
