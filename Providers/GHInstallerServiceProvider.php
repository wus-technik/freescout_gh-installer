<?php

namespace Modules\GHInstaller\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\GHInstaller\Services\GitHubClient;
use Modules\GHInstaller\Services\Installer;
use Modules\GHInstaller\Http\Controllers\InstallerController;

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
        \Eventy::addFilter('settings.sections', function ($sections) {
            if (auth()->check() && auth()->user()->isAdmin()) {
                $sections['ghinstaller'] = ['title' => 'Github.com Installer', 'icon' => 'download-alt', 'order' => 400];
            }
            return $sections;
        });
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section === 'ghinstaller') {
                abort_unless(auth()->check() && auth()->user()->isAdmin(), 403);
                return ['ghinstaller' => true];
            }
            return $settings;
        }, 20, 2);
        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section === 'ghinstaller') {
                $params['template_vars'] = $this->app->make(InstallerController::class)->viewData(request(), $this->app->make(Installer::class));
            }
            return $params;
        }, 20, 2);
        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section === 'ghinstaller' ? 'ghinstaller::index' : $view;
        }, 20, 2);
        \Eventy::addFilter('settings.before_save', function ($request, $section) {
            // This section uses dedicated POST actions, not the generic option saver.
            abort_if($section === 'ghinstaller', 405);
            return $request;
        }, 20, 2);
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath('ghinstaller').'/css/installer.css';
            return $styles;
        });
    }
}
