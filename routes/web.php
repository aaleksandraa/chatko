<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

Route::get('/password/reset', function (Request $request) {
    return view('auth.password-reset', [
        'token' => (string) $request->query('token', ''),
        'email' => (string) $request->query('email', ''),
        'tenantSlug' => (string) $request->query('tenant_slug', ''),
    ]);
})->name('password.reset.form');
