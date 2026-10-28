{{-- Panel administracyjny Netella-Reg.

     Rdzeń LibreNMS nie daje formularza: wysyłamy POST na plugin.update
     z plugin_active=1 i KOMPLETEM pól settings[...], bo LibreNMS zastępuje
     cały JSON ustawień. Pola wielokrotnego wyboru mają ukryty pusty element,
     żeby odznaczenie wszystkich ról też się zapisało.

     Każdy klucz ustawień jest tu także zmienną widoku (@include z $settings),
     dlatego dane pomocnicze są w $nregInfo. --}}

@php($s = $settings)
@php($i = $nregInfo)

<style>
    .nreg-adm { margin: 15px; max-width: 1100px; }
    .nreg-adm h5 { margin: 22px 0 6px; font-weight: 600; }
    .nreg-adm .pomoc { font-size: 12px; opacity: .75; margin: 0 0 8px; }
    .nreg-adm .table { width: auto; }
    .nreg-adm code { white-space: pre-wrap; word-break: break-all; }
    .nreg-adm .nreg-mono { font-family: monospace; }
</style>

<div class="nreg-adm">
    <h4>Netella-Reg <small>wersja {{ $i['version'] }}</small></h4>

    @if(! $i['dbOk'])
        <div class="alert alert-danger">
            <strong>Brak dostępu do bazy wtyczki <code>{{ $i['database'] }}</code>.</strong>
            Utwórz ją raz jako administrator bazy, potem uruchom <code>lnms netella-reg:install</code>:
            <br><code>{{ $i['dbHint'] }}</code>
            <br><small>{{ $i['dbError'] }}</small>
        </div>
    @else
        <p class="pomoc">Baza <code>{{ $i['database'] }}</code> &middot; schemat: <code>{{ json_encode($i['versions']) }}</code></p>
    @endif

    <form method="POST" action="{{ route('plugin.update', ['plugin' => $i['pluginName']]) }}">
        @csrf
        <input type="hidden" name="plugin_active" value="1">

        <h5>Moduły</h5>
        <table class="table table-condensed">
            <thead><tr><th>Moduł</th><th>Włączony</th><th>Ostatnia synchronizacja</th></tr></thead>
            <tbody>
            @foreach($i['modules'] as $key => $m)
                <tr>
                    <td>{{ $m['name'] }} <small class="text-muted">({{ $key }})</small></td>
                    <td>
                        <input type="hidden" name="settings[module_{{ $key }}]" value="0">
                        <input type="checkbox" name="settings[module_{{ $key }}]" value="1" @checked($m['enabled'])>
                    </td>
                    <td>
                        @if($m['sync'] === null)
                            <span class="text-muted">jeszcze nie było</span>
                        @elseif($m['sync']['ok'])
                            {{ $m['sync']['at'] }} ({{ $m['sync']['ms'] }} ms)
                        @else
                            <span class="text-danger">{{ $m['sync']['at'] }}: {{ $m['sync']['error'] }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <h5>Uprawnienia</h5>
        <p class="pomoc">
            Administrator LibreNMS ma zawsze wszystkie prawa. Prawo wyższe obejmuje niższe
            (administracja &gt; usuwanie &gt; edycja &gt; przeglądanie). Pusta lista przy
            przeglądaniu oznacza każdego zalogowanego, przy pozostałych tylko administratora.
        </p>
        <table class="table table-condensed">
            <thead><tr><th>Rola</th>@foreach($i['levels'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($i['roles'] as $role)
                <tr>
                    <td>{{ $role }}</td>
                    @foreach($i['levels'] as $field => $label)
                        @php($chosen = is_array($s[$field] ?? null) ? $s[$field] : array_map('trim', explode(',', (string) ($s[$field] ?? ''))))
                        <td><input type="checkbox" name="settings[{{ $field }}][]" value="{{ $role }}" @checked(in_array($role, $chosen, true))></td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
        @foreach($i['levels'] as $field => $label)
            <input type="hidden" name="settings[{{ $field }}][]" value="">
        @endforeach

        <h5>Dane z LibreNMS</h5>
        <div class="form-group">
            <label>Karencja dla rekordów, które zniknęły z LibreNMS (dni)</label>
            <input class="form-control" style="width: 120px;" type="number" min="0" max="3650" name="settings[grace_days]" value="{{ (int) ($s['grace_days'] ?? 30) }}">
            <p class="pomoc">Przez ten czas numer lub adres nadal liczy się jako zajęty (status „zniknął”).</p>
        </div>
        <div class="form-group">
            <label>Urządzenia wyłączone w LibreNMS</label>
            <select class="form-control" style="width: 420px;" name="settings[disabled_devices]">
                <option value="stale" @selected(($s['disabled_devices'] ?? 'stale') === 'stale')>zajęte; w adresacji widoczne po zaznaczeniu „Pokaż adresy z wyłączonych hostów”</option>
                <option value="ignore" @selected(($s['disabled_devices'] ?? '') === 'ignore')>ignorowane (traktowane jak wolne)</option>
            </select>
            <p class="pomoc">LibreNMS zostawia w bazie dane urządzeń wyłączonych, nawet sprzed wielu miesięcy.</p>
        </div>

        <h5>Adresacja IP</h5>
        <div class="form-group">
            <label>Pomijane zakresy IPv4</label>
            <input class="form-control nreg-mono" style="width: 520px;" name="settings[ipam_ignore]" value="{{ $s['ipam_ignore'] ?? '' }}">
            <p class="pomoc">Prefiksy po przecinku. Adresy z tych zakresów nie trafiają do rejestru (np. loopback 127.0.0.0/8).</p>
        </div>
        <div class="form-group">
            <label>Pomijane interfejsy (wyrażenie regularne)</label>
            <input class="form-control nreg-mono" style="width: 520px;" name="settings[ipam_ignore_if]" value="{{ $s['ipam_ignore_if'] ?? '' }}">
            <p class="pomoc">Domyślnie wewnętrzne interfejsy Junipera (em0.0, jsrv.1) z adresami 128.0.0.x/2. Puste = nic nie pomijamy.</p>
        </div>
        <div class="form-group">
            <label>Poziomy grupowania drzewa (maski)</label>
            <input class="form-control nreg-mono" style="width: 200px;" name="settings[ipam_levels]" value="{{ $s['ipam_levels'] ?? '8,16,24' }}">
            <p class="pomoc">Wewnątrz agregatów (i adresacji wewnętrznej) drzewo rozwija się klasa po klasie na tych maskach, gdy nie ma tam prawdziwego prefiksu. Np. 8,16,24 albo 8,12,16,20,24.</p>
        </div>
        <div class="form-group">
            <label>Adresacja wewnętrzna</label>
            <input class="form-control nreg-mono" style="width: 420px;" name="settings[ipam_private]" value="{{ $s['ipam_private'] ?? '10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10' }}">
            <p class="pomoc">Prefiksy, którymi dysponujecie w całości (zwykle prywatne RFC 1918 i CGNAT). W drzewie są korzeniami jak agregat,
                z wolnym miejscem, o ile coś w nich leży. Adresacja publiczna nie dostaje nad sobą żadnej klasy: korzeniem jest najszerszy
                prawdziwy prefiks (sieć z LibreNMS albo agregat). Swój przydział publiczny (np. od RIPE) dodaj jako agregat.</p>
        </div>

        <h5>Adresacja IPv6</h5>
        <div class="form-group">
            <label>Poziomy grupowania drzewa IPv6 (maski)</label>
            <input class="form-control nreg-mono" style="width: 200px;" name="settings[ipam6_levels]" value="{{ $s['ipam6_levels'] ?? '32,48,56,64' }}">
            <p class="pomoc">Jak w IPv4, tylko wewnątrz agregatów. W prefiksach /64 i szerszych zajętość liczy się w blokach /64.</p>
        </div>
        <div class="form-group">
            <label>Adresacja wewnętrzna IPv6</label>
            <input class="form-control nreg-mono" style="width: 420px;" name="settings[ipam6_private]" value="{{ $s['ipam6_private'] ?? 'fc00::/7' }}">
            <p class="pomoc">Domyślnie ULA (fc00::/7). Jest w drzewie korzeniem, o ile coś w niej leży. Adresy IPv6 z LibreNMS zbiera moduł „Adresacja IP”.</p>
        </div>

        <h5>Kopie zapasowe</h5>
        <div class="form-group">
            <label>Ile pełnych kopii trzymać</label>
            <input class="form-control" style="width: 120px;" type="number" min="1" max="365" name="settings[backup_keep]" value="{{ (int) ($s['backup_keep'] ?? 14) }}">
            <p class="pomoc">Synchronizacja robi jedną kopię na dobę, więc 14 to około dwóch tygodni. Lista kopii jest niżej.</p>
        </div>

        <h5>Baza wtyczki</h5>
        <div class="form-group">
            <label>Nazwa bazy (puste = librenms_netella_reg)</label>
            <input class="form-control" style="width: 300px;" name="settings[db_name]" value="{{ $s['db_name'] ?? '' }}" pattern="[A-Za-z0-9_]{0,64}">
        </div>

        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Zapisz</button>
    </form>

    <h5>Kopie zapasowe</h5>
    <p class="pomoc">
        Pełna kopia bazy wtyczki (wpisy ręczne, przestrzenie, dziennik zmian, migawki LibreNMS) i ustawień, raz na dobę
        z synchronizacji. Pliki są w <code>{{ $i['backupDir'] }}</code>. Baza wtyczki jest osobna, więc kopie LibreNMS
        jej zwykle nie obejmują. Ustawienia mają też osobną kopię
        @if($i['settingsBackup'])<code>{{ $i['settingsBackup'] }}</code>@else(jeszcze nie powstała)@endif:
        gdy znikną (np. po aktualizacji LibreNMS), synchronizacja przywróci je sama i zapisze to w dzienniku.
    </p>
    <p>
        <button type="button" class="btn btn-default btn-sm" id="nreg-backup-now"><i class="fa fa-download"></i> Utwórz kopię teraz</button>
        <span id="nreg-backup-msg" class="pomoc"></span>
    </p>
    @if($i['backups'])
        <table class="table table-condensed">
            <thead><tr><th>Plik</th><th>Utworzona</th><th>Rodzaj</th><th>Rozmiar</th></tr></thead>
            <tbody>
            @foreach($i['backups'] as $b)
                <tr>
                    <td class="nreg-mono"><a href="{{ $b['url'] }}">{{ $b['file'] }}</a></td>
                    <td>{{ $b['at'] }}</td>
                    <td>{{ ['auto' => 'automatyczna', 'manual' => 'ręczna', 'pre-restore' => 'przed przywróceniem'][$b['reason']] ?? $b['reason'] }}</td>
                    <td>{{ number_format($b['size'] / 1024, 0, ',', ' ') }} KiB</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="pomoc">Nie ma jeszcze żadnej kopii.</p>
    @endif
    <p class="pomoc">
        Przywracanie tylko z konsoli serwera. Bez <code>--force</code> pokazuje sam podgląd, a przed przywróceniem
        bieżący stan trafia do kopii „przed przywróceniem”:<br>
        <code>lnms netella-reg:restore &lt;plik&gt; [--with-settings] --force</code>
    </p>

    <h5>Cron</h5>
    <p class="pomoc">Synchronizacja z LibreNMS co 5 minut (np. plik <code>/etc/cron.d/netella-reg</code>):</p>
    <code>{{ $i['cron'] }}</code>
</div>

<script>
document.getElementById('nreg-backup-now').addEventListener('click', function () {
    var btn = this, msg = document.getElementById('nreg-backup-msg');
    var token = @json(csrf_token());
    btn.disabled = true; msg.textContent = 'Tworzę kopię…';
    fetch(@json($i['apiUrl']) + '/core/backup_create', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) { throw new Error(j.error || 'błąd'); }
        msg.textContent = 'Utworzono ' + j.created.file + '. Odświeżam listę…';
        window.location.reload();
    }).catch(function (e) { msg.textContent = 'Nie udało się: ' + e.message; btn.disabled = false; });
});
</script>
