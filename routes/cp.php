<?php

use Aldanux\StatamicPolls\Http\Controllers\CP\PollsController;
use Illuminate\Support\Facades\Route;

Route::prefix('polls')->name('statamic-polls.')->group(function () {
    Route::get('/', [PollsController::class, 'index'])->name('index');
    Route::get('listing', [PollsController::class, 'listing'])->name('listing');
    Route::get('{poll}', [PollsController::class, 'show'])->name('show');
    Route::get('{poll}/export', [PollsController::class, 'export'])->name('export');
    Route::delete('{poll}/ips/{ip}', [PollsController::class, 'voidIp'])->name('void-ip');
});
