<div class="ghinstaller">
    <div class="margin-top margin-bottom">
        @if (session('ghinstaller.error'))
            <div class="alert alert-danger" role="alert">{{ session('ghinstaller.error') }}</div>
        @endif
        @if (session('ghinstaller.success'))
            <div class="alert alert-success" role="status">{{ session('ghinstaller.success') }} <a href="{{ route('modules') }}">Zur Modulverwaltung</a></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($pending)
            <h3>{{ !empty($pending['previous']) ? 'Update prüfen' : 'Installation prüfen' }}</h3>
            <dl class="dl-horizontal">
                <dt>Modul</dt><dd>{{ $pending['manifest']['name'] }}</dd>
                <dt>Version</dt><dd>{{ $pending['manifest']['version'] }}</dd>
                <dt>Alias</dt><dd>{{ $pending['manifest']['alias'] }}</dd>
                <dt>Repository</dt><dd><a href="https://github.com/{{ $pending['repository'] }}" target="_blank" rel="noopener noreferrer">{{ $pending['repository'] }}</a></dd>
                <dt>Branch / Tag</dt><dd>{{ $pending['ref'] }}</dd>
                <dt>Commit</dt><dd><a href="https://github.com/{{ $pending['repository'] }}/commit/{{ $pending['commit'] }}" target="_blank" rel="noopener noreferrer"><code>{{ $pending['commit'] }}</code></a></dd>
                @if (!empty($pending['previous']))
                    <dt>Bisheriger Commit</dt><dd><code>{{ $pending['previous']['source']['commit'] }}</code></dd>
                @endif
            </dl>
            <p>Installiert wird genau dieser Commit. Die Vorschau gilt zehn Minuten. Anschließend aktivierst du das Modul in der Modulverwaltung.</p>
            @if (!empty($pending['previous']))
                <div class="alert alert-warning">Vor dem Update das Modul <a href="{{ route('modules') }}" target="_blank" rel="noopener noreferrer">in der Modulverwaltung deaktivieren</a> und Datenbank sowie Modulverzeichnis sichern. Das Update ersetzt den gesamten Modulordner einschließlich lokaler Änderungen. Nach dem Update wieder aktivieren, damit FreeScout Migrationen ausführt.</div>
            @endif
            <form method="POST" action="{{ route('ghinstaller.install') }}">
                {{ csrf_field() }}
                <input type="hidden" name="confirmation" value="{{ session('ghinstaller.confirmation') }}">
                <div class="checkbox"><label><input type="checkbox" name="trusted" value="1" required> Ich vertraue dem Quellcode dieses Repositories. Ein Modul kann beim Aktivieren PHP-Code mit den Rechten von FreeScout ausführen und auf dessen Daten zugreifen.</label></div>
                <button type="submit" class="btn btn-primary">{{ !empty($pending['previous']) ? 'Update installieren' : 'Modul installieren' }}</button>
            </form>
            <form method="POST" action="{{ route('ghinstaller.cancel') }}" class="margin-top">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-default">Abbrechen</button>
            </form>
        @else
            <p>Gib die URL eines GitHub-Repositories mit einem FreeScout-Modul ein. Das Modul wird zuerst heruntergeladen und geprüft.</p>
            <form method="POST" action="{{ route('ghinstaller.preview') }}" class="form-horizontal margin-top">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="repository" class="col-sm-3 control-label">GitHub-Repository-URL</label>
                    <div class="col-sm-9">
                    <input id="repository" name="repository" type="url" class="form-control" required maxlength="255" placeholder="https://github.com/organisation/freescout-modul" value="{{ old('repository') }}" aria-describedby="repository-help">
                    <p id="repository-help" class="help-block">Das Repository muss genau ein Modul mit module.json und passenden Provider-Dateien enthalten. GitHub.com wird unterstützt.</p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="ref" class="col-sm-3 control-label">Branch, Tag oder Commit</label>
                    <div class="col-sm-9">
                    <input id="ref" name="ref" type="text" class="form-control" maxlength="200" placeholder="Standard-Branch des Repositories" value="{{ old('ref') }}">
                    <p class="help-block">Optional. Leer lassen, um dem Standard-Branch zu folgen.</p>
                    </div>
                </div>
                <div class="form-group"><div class="col-sm-9 col-sm-offset-3"><button type="submit" class="btn btn-primary">Repository prüfen</button></div></div>
            </form>
        @endif
    </div>
    @if (count($managed))
        <h3>Über GitHub installierte Module</h3>
        <div>
            <p>Die Prüfung vergleicht den installierten Commit mit dem aktuellen Stand der gespeicherten Referenz. Bei einem festen Tag oder Commit bleibt diese Referenz fest.</p>
            @foreach ($managed as $alias => $module)
                <div class="ghinstaller-module">
                <h3>{{ $module['manifest']['name'] }} <small>{{ $module['manifest']['version'] ?? '' }}</small></h3>
                <p>{{ $module['source']['repository'] }} · {{ $module['source']['ref'] }}<br>Installierter Commit: <code>{{ $module['source']['commit'] }}</code></p>
                @if (!empty($module['manifest']['latestVersionUrl']) || !empty($module['manifest']['latestVersionZipUrl']))
                    <p>Dieses Modul hat eine eigene Update-Anbindung. <a href="{{ route('modules') }}">Updates über die Modulverwaltung</a>.</p>
                @else
                    @php $check = session('ghinstaller.checks', [])[$alias] ?? null; @endphp
                    @if ($check)
                        <div class="alert {{ $check['changed'] ? 'alert-info' : 'alert-success' }}" role="status">
                            {{ $check['changed'] ? 'Die Referenz zeigt auf einen anderen Commit. Ein Update kann geprüft werden.' : 'Das Modul ist auf dem aktuellen Commit.' }}<br>
                            Aktueller Commit auf GitHub: <code>{{ $check['available'] }}</code>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('ghinstaller.check') }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="alias" value="{{ $alias }}">
                        <button type="submit" class="btn btn-default">Auf Änderungen prüfen</button>
                    </form>
                    @if ($check && $check['changed'])
                        <form method="POST" action="{{ route('ghinstaller.update') }}" class="margin-top">
                            {{ csrf_field() }}
                            <input type="hidden" name="alias" value="{{ $alias }}">
                            <button type="submit" class="btn btn-primary">Update prüfen</button>
                        </form>
                    @endif
                @endif
                </div>
            @endforeach
        </div>
    @endif
    <details @if (!$diagnostics['modules_writable'] || !$diagnostics['work_writable'] || in_array(false, $diagnostics['extensions'], true)) open @endif>
        <summary>Serverprüfung und Schreibrechte</summary>
        <table class="table table-condensed ghinstaller-checks">
            <tbody>
                <tr><th>PHP-Version</th><td>{{ $diagnostics['php'] }}</td></tr>
                <tr><th>PHP-Prozessbenutzer</th><td>{{ $diagnostics['user'] ?? 'Nicht ermittelbar; PHP-FPM-Pool bzw. Apache-Konfiguration prüfen.' }}</td></tr>
                @foreach ($diagnostics['extensions'] as $extension => $available)
                    <tr><th>PHP {{ $extension }}</th><td class="{{ $available ? 'text-success' : 'text-danger' }}">{{ $available ? 'Verfügbar' : 'Fehlt' }}</td></tr>
                @endforeach
                <tr><th>Module</th><td><code>{{ $diagnostics['modules'] }}</code><br><span class="{{ $diagnostics['modules_writable'] ? 'text-success' : 'text-danger' }}">{{ $diagnostics['modules_writable'] ? 'Beschreibbar' : 'Nicht beschreibbar' }}</span></td></tr>
                <tr><th>Arbeitsverzeichnis</th><td><code>{{ $diagnostics['work'] }}</code><br><span class="{{ $diagnostics['work_writable'] ? 'text-success' : 'text-danger' }}">{{ $diagnostics['work_writable'] ? 'Beschreibbar bzw. anlegbar' : 'Nicht beschreibbar / nicht anlegbar' }}</span></td></tr>
            </tbody>
        </table>
        <p class="help-block">Der PHP-Prozessbenutzer benötigt Schreibrechte in beiden Verzeichnissen. Bei ihm als Eigentümer: Modulordner 755, Moduldateien 644 und das private Arbeitsverzeichnis 700. Details und Befehle stehen in der README.</p>
    </details>
</div>
