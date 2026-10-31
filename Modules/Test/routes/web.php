<?php

use Illuminate\Support\Facades\Route;
use Modules\Test\Http\Controllers\TestController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('tests', TestController::class)->names('test');
});
