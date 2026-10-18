<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('game', ['initialRoomCode' => null]);
});

Route::get('/snake', function () {
    return view('game', ['initialRoomCode' => null]);
});

Route::get('/room/{code}', function (string $code) {
    return view('game', ['initialRoomCode' => strtoupper($code)]);
});

Route::get('/snake/room/{code}', function (string $code) {
    return view('game', ['initialRoomCode' => strtoupper($code)]);
});
