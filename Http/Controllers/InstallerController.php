<?php

namespace Modules\GHInstaller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\GHInstaller\Services\Installer;

class InstallerController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->check() && auth()->user()->isAdmin(), 403);
            return $next($request);
        });
    }

    public function index(Request $request, Installer $installer)
    {
        $installer->cleanup();
        $pending = $request->session()->get('ghinstaller.pending');
        if ($pending && $pending['expires'] < time()) {
            $installer->discard($pending['id']);
            $request->session()->forget('ghinstaller.pending');
            $pending = null;
        }
        $managed = $installer->managed();
        return response()->view('ghinstaller::index', compact('pending', 'managed'))->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request, Installer $installer)
    {
        $this->validateInput($request);
        $this->clearPending($request, $installer);
        try {
            $pending = $installer->prepare($request->input('repository'), (string) $request->input('ref', ''));
            $request->session()->put('ghinstaller.pending', $pending);
            $request->session()->put('ghinstaller.confirmation', bin2hex(random_bytes(24)));
        } catch (\Throwable $error) {
            return redirect()->route('ghinstaller.index')->withInput($request->only('repository', 'ref'))->with('ghinstaller.error', $this->errorMessage($error));
        }
        return redirect()->route('ghinstaller.index');
    }

    public function install(Request $request, Installer $installer)
    {
        $pending = $request->session()->get('ghinstaller.pending');
        $confirmation = $request->session()->get('ghinstaller.confirmation');
        if (!$pending || !is_string($confirmation) || !is_string($request->input('confirmation'))
            || !hash_equals($confirmation, $request->input('confirmation')) || $request->input('trusted') !== '1') {
            return redirect()->route('ghinstaller.index')->with('ghinstaller.error', 'Bitte zuerst das Repository prüfen und dem Modulcode ausdrücklich vertrauen.');
        }
        // Consume once; repeat requests cannot install the same preview twice.
        $request->session()->forget(['ghinstaller.pending', 'ghinstaller.confirmation']);
        try {
            $manifest = $installer->install($pending);
        } catch (\Throwable $error) {
            return redirect()->route('ghinstaller.index')->with('ghinstaller.error', $this->errorMessage($error));
        }
        // Publishing succeeded. Cache failure must not report a failed installation.
        try {
            \Module::clearCache();
            \Artisan::call('freescout:clear-cache');
        } catch (\Throwable $error) {
            return redirect()->route('ghinstaller.index')->with('ghinstaller.success', $manifest['name'].' wurde installiert. Bitte den FreeScout-Cache über die Systemwerkzeuge leeren und das Modul unter Verwalten → Module aktivieren.');
        }
        return redirect()->route('ghinstaller.index')->with('ghinstaller.success', $manifest['name'].' wurde installiert. Jetzt unter Verwalten → Module aktivieren.');
    }

    public function cancel(Request $request, Installer $installer)
    {
        $this->clearPending($request, $installer);
        return redirect()->route('ghinstaller.index');
    }

    public function check(Request $request, Installer $installer)
    {
        $request->validate(['alias' => 'required|string|max:80']);
        try {
            $result = $installer->check($request->input('alias'));
            return redirect()->route('ghinstaller.index')->with('ghinstaller.checks', [$request->input('alias') => $result]);
        } catch (\Throwable $error) {
            return redirect()->route('ghinstaller.index')->with('ghinstaller.error', $this->errorMessage($error));
        }
    }

    public function updatePreview(Request $request, Installer $installer)
    {
        $request->validate(['alias' => 'required|string|max:80']);
        $this->clearPending($request, $installer);
        try {
            $pending = $installer->prepareUpdate($request->input('alias'));
            $request->session()->put('ghinstaller.pending', $pending);
            $request->session()->put('ghinstaller.confirmation', bin2hex(random_bytes(24)));
        } catch (\Throwable $error) {
            return redirect()->route('ghinstaller.index')->with('ghinstaller.error', $this->errorMessage($error));
        }
        return redirect()->route('ghinstaller.index');
    }

    private function errorMessage(\Throwable $error)
    {
        if ($error instanceof \RuntimeException) { return $error->getMessage(); }
        // Never return filesystem paths, transport diagnostics or credentials to the browser.
        \Log::warning('GHInstaller operation failed', ['type' => get_class($error)]);
        return 'Der Vorgang ist fehlgeschlagen. Bitte Schreibrechte und Serverkonfiguration prüfen.';
    }

    private function clearPending(Request $request, Installer $installer)
    {
        $pending = $request->session()->pull('ghinstaller.pending');
        $request->session()->forget('ghinstaller.confirmation');
        if ($pending) { $installer->discard($pending['id']); }
    }

    private function validateInput(Request $request)
    {
        $request->validate([
            'repository' => 'required|string|max:255',
            'ref' => 'nullable|string|max:200',
        ]);
    }
}
