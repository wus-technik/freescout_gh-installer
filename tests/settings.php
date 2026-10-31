<?php

// Exercise the real provider/controller hooks with small framework doubles.
namespace Illuminate\Support {
    class ServiceProvider {
        protected $app;
        public function __construct($app) { $this->app = $app; }
        protected function loadViewsFrom($path, $namespace) {}
        protected function loadRoutesFrom($path) {}
    }
}
namespace Illuminate\Routing {
    class Controller {
        public $middleware = [];
        protected function middleware($callback) { $this->middleware[] = $callback; }
    }
}
namespace Illuminate\Http {
    class Request {
        public function session() { return $GLOBALS['session']; }
    }
}
namespace {
    use Modules\GHInstaller\Http\Controllers\InstallerController;
    use Modules\GHInstaller\Providers\GHInstallerServiceProvider;
    use Modules\GHInstaller\Services\GitHubClient;
    use Modules\GHInstaller\Services\Installer;

    class Eventy {
        public static $filters = [];
        public static function addFilter($name, $callback, $priority = 20, $args = 1) { self::$filters[$name] = $callback; }
        public static function filter($name, $value, $section) { return call_user_func(self::$filters[$name], $value, $section); }
    }
    class Log {
        public static $last;
        public static function warning($message, $context) { self::$last = $context; }
    }
    class SessionDouble {
        public function get($key) { return null; }
        public function reflash() {}
    }
    class AuthDouble {
        public $admin = true;
        public function check() { return true; }
        public function user() { return $this; }
        public function isAdmin() { return $this->admin; }
    }
    class AppDouble {
        public $installer;
        public function make($class) {
            return $class === Installer::class ? $this->installer : new InstallerController();
        }
    }
    class RedirectDouble {
        public function route($name, $parameters) { return [$name, $parameters]; }
    }
    function auth() { return $GLOBALS['auth']; }
    function request() { return $GLOBALS['request']; }
    function redirect() { return new RedirectDouble(); }
    function config($key, $default = null) { return $key === 'ghinstaller.token' ? 'secret-test-token' : $default; }
    function abort_unless($condition, $code) { if (!$condition) { throw new \RuntimeException((string) $code); } }
    function abort_if($condition, $code) { abort_unless(!$condition, $code); }
    function checkSettings($condition, $message) {
        if (!$condition) { throw new \RuntimeException('FAIL: '.$message); }
    }
    function denies($call, $code) {
        try { $call(); } catch (\RuntimeException $error) {
            checkSettings($error->getMessage() === (string) $code, 'expected HTTP '.$code);
            return;
        }
        throw new \RuntimeException('FAIL: expected HTTP '.$code);
    }
    foreach (['Repository', 'GitHubClient', 'Archive', 'Installer'] as $class) { require __DIR__.'/../Services/'.$class.'.php'; }
    require __DIR__.'/../Http/Controllers/InstallerController.php';
    require __DIR__.'/../Providers/GHInstallerServiceProvider.php';
    $GLOBALS['auth'] = new AuthDouble();
    $GLOBALS['session'] = new SessionDouble();
    $GLOBALS['request'] = new \Illuminate\Http\Request();
    $app = new AppDouble();
    $app->installer = new Installer(sys_get_temp_dir().'/missing-ghinstaller-settings-test/work', __DIR__.'/../', new GitHubClient());
    (new GHInstallerServiceProvider($app))->boot();
    $sections = Eventy::filter('settings.sections', ['general' => ['title' => 'General']], null);
    checkSettings($sections['ghinstaller']['title'] === 'Github.com Installer', 'settings navigation title');
    checkSettings(isset($sections['general']), 'preserve other settings sections');
    checkSettings(Eventy::filter('settings.section_settings', [], 'ghinstaller') !== [], 'core settings section is discoverable');
    $params = Eventy::filter('settings.section_params', [], 'ghinstaller');
    checkSettings(array_key_exists('pending', $params['template_vars']), 'settings view receives preview state');
    checkSettings(isset($params['template_vars']['managed'], $params['template_vars']['diagnostics']), 'settings view receives modules and diagnostics');
    checkSettings(Eventy::filter('settings.view', 'settings/general', 'ghinstaller') === 'ghinstaller::index', 'native settings renders module partial');
    checkSettings(Eventy::filter('settings.view', 'settings/general', 'general') === 'settings/general', 'other settings template preserved');
    checkSettings(Eventy::filter('settings.section_params', ['other' => true], 'general') === ['other' => true], 'other settings parameters preserved');
    checkSettings((new InstallerController())->index(request(), $app->installer) === ['settings', ['section' => 'ghinstaller']], 'legacy URL redirects to native settings');
    denies(function () { Eventy::filter('settings.before_save', request(), 'ghinstaller'); }, 405);
    $GLOBALS['auth']->admin = false;
    checkSettings(!isset(Eventy::filter('settings.sections', [], null)['ghinstaller']), 'section hidden from non-admins');
    denies(function () { Eventy::filter('settings.section_settings', [], 'ghinstaller'); }, 403);
    denies(function () { Eventy::filter('settings.section_params', [], 'ghinstaller'); }, 403);
    $controller = new InstallerController();
    denies(function () use ($controller) { call_user_func($controller->middleware[0], request(), function () {}); }, 403);
    $errorMessage = new \ReflectionMethod(InstallerController::class, 'errorMessage');
    if (PHP_VERSION_ID < 80100) { $errorMessage->setAccessible(true); }
    $result = $errorMessage->invoke($controller, new \ErrorException('Example secret-test-token at path /private/file'));
    checkSettings(strpos(Log::$last['message'], 'secret-test-token') === false, 'configured token redacted in log');
    checkSettings(strpos(Log::$last['message'], '[redacted]') !== false, 'diagnostic message retained');
    checkSettings(strpos($result, '/private/file') === false, 'raw filesystem details not returned in error');
    echo "OK: settings hooks, admin boundaries, legacy redirect and log redaction.\n";
}
