<?php

namespace Modules\GHInstaller\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\GHInstaller\Services\GitHubClient;
use Modules\GHInstaller\Services\Installer;

class GHInstallerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'ghinstaller');
        $this->app->singleton(Installer::class, function () {
            return new Installer(
                storage_path('app/ghinstaller'),
                config('modules.paths.modules', base_path('Modules')),
                new GitHubClient((string) config('ghinstaller.token', '')),
                function ($alias) { return \App\Module::isActive($alias); }
            );
        });
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ghinstaller');
        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');
        \Eventy::addAction('menu.manage.after_mailboxes', function () {
            if (auth()->check() && auth()->user()->isAdmin()) {
                echo view('ghinstaller::menu')->render();
            }
        });
    }
}
