<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    try {
        $version = DB::scalar('SELECT version()');
        return view('welcome', ['mysql_version' => $version]);
    } catch (\Exception $e) {
        report($e);
        return response()->view('welcome', ['error' => 'Database connection failed.'], 503);
    }
});

Route::get('/healthz', function () {
    try {
        // PDOインスタンスを取得しようとすることで、接続を試みます。
        DB::connection()->getPdo();
        return response('OK', 200);
    } catch (\Exception $e) {
        // 接続失敗時はエラーをログに記録し、503を返します。
        report($e);
        return response('Service Unavailable', 503);
    }
});