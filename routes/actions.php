<?php

use Aldanux\StatamicPolls\Http\Controllers\PollController;
use Illuminate\Support\Facades\Route;

Route::get('polls/{poll}/state', [PollController::class, 'state'])->name('statamic-polls.state');
Route::post('polls/{poll}/vote', [PollController::class, 'vote'])->name('statamic-polls.vote');
