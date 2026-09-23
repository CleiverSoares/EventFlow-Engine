<?php

use App\Http\Controllers\Lab\ExportLabController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/lab/exports');
});

Route::get('/lab/exports', [ExportLabController::class, 'index'])->name('lab.exports');
Route::get('/lab/exports/snapshot', [ExportLabController::class, 'snapshot'])->name('lab.exports.snapshot');
