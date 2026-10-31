<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['web', 'auth'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\GHInstaller\\Http\\Controllers',
], function () {
    Route::get('/gh-installer', 'InstallerController@index')->name('ghinstaller.index');
    Route::post('/gh-installer/preview', 'InstallerController@preview')->middleware('throttle:6,1')->name('ghinstaller.preview');
    Route::post('/gh-installer/install', 'InstallerController@install')->middleware('throttle:6,1')->name('ghinstaller.install');
    Route::post('/gh-installer/cancel', 'InstallerController@cancel')->name('ghinstaller.cancel');
    Route::post('/gh-installer/check', 'InstallerController@check')->middleware('throttle:6,1')->name('ghinstaller.check');
    Route::post('/gh-installer/update', 'InstallerController@updatePreview')->middleware('throttle:6,1')->name('ghinstaller.update');
});
