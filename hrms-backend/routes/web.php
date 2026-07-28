<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/run-migrations', function () {
    try {
        Artisan::call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);

        return 'Database migrated and seeded successfully!';
    } catch (\Exception $e) {
        return 'Error: '.$e->getMessage();
    }
});
