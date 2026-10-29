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
    .nreg-adm h4 { display: flex; align-items: baseline; gap: 8px; }
    .nreg-adm h4 .nreg-lang { margin-left: auto; width: auto; height: 28px; padding: 2px 6px; font-size: 12px; }
    .nreg-adm h5 { margin: 22px 0 6px; font-weight: 600; }
    .nreg-adm .pomoc { font-size: 12px; opacity: .75; margin: 0 0 8px; }
    .nreg-adm .table { width: auto; }
    .nreg-adm code { white-space: pre-wrap; word-break: break-all; }
    .nreg-adm .nreg-mono { font-family: monospace; }
</style>

<div class="nreg-adm">
    <h4>Netella-Reg <small>{{ nreg_t('wersja {v}', ['v' => $i['version']]) }}</small>
        <select class="form-control nreg-lang" id="nreg-lang" title="{{ nreg_t('Język interfejsu') }}">
            @foreach(\Ispnetella\NetellaReg\Core\Lang::LANGS as $code => $label)
                <option value="{{ $code }}" @selected($code === \Ispnetella\NetellaReg\Core\Lang::current())>{{ $label }}</option>
            @endforeach
        </select>
    </h4>

    @if(! $i['dbOk'])
        <div class="alert alert-danger">
            <strong>{!! nreg_t('Brak dostępu do bazy wtyczki <code>{db}</code>.', ['db' => e($i['database'])]) !!}</strong>
            {!! nreg_t('Utwórz ją raz jako administrator bazy, potem uruchom <code>lnms netella-reg:install</code>:') !!}
            <br><code>{{ $i['dbHint'] }}</code>
            <br><small>{{ $i['dbError'] }}</small>
        </div>
    @else
        <p class="pomoc">{!! nreg_t('Baza <code>{db}</code> &middot; schemat: <code>{schema}</code>', ['db' => e($i['database']), 'schema' => e(json_encode($i['versions']))]) !!}</p>
    @endif

    <form method="POST" action="{{ route('plugin.update', ['plugin' => $i['pluginName']]) }}">
        @csrf
        <input type="hidden" name="plugin_active" value="1">

        <h5>{{ nreg_t('Moduły') }}</h5>
        <table class="table table-condensed">
            <thead><tr><th>{{ nreg_t('Moduł') }}</th><th>{{ nreg_t('Włączony') }}</th><th>{{ nreg_t('Ostatnia synchronizacja') }}</th></tr></thead>
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
                            <span class="text-muted">{{ nreg_t('jeszcze nie było') }}</span>
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

        <h5>{{ nreg_t('Uprawnienia') }}</h5>
        <p class="pomoc">
            {{ nreg_t('Administrator LibreNMS ma zawsze wszystkie prawa. Prawo wyższe obejmuje niższe (administracja > usuwanie > edycja > przeglądanie). Pusta lista przy przeglądaniu oznacza każdego zalogowanego, przy pozostałych tylko administratora.') }}
        </p>
        <table class="table table-condensed">
            <thead><tr><th>{{ nreg_t('Rola') }}</th>@foreach($i['levels'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
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

        <h5>{{ nreg_t('Dane z LibreNMS') }}</h5>
        <div class="form-group">
            <label>{{ nreg_t('Karencja dla rekordów, które zniknęły z LibreNMS (dni)') }}</label>
            <input class="form-control" style="width: 120px;" type="number" min="0" max="3650" name="settings[grace_days]" value="{{ (int) ($s['grace_days'] ?? 30) }}">
            <p class="pomoc">{{ nreg_t('Przez ten czas numer lub adres nadal liczy się jako zajęty (status „zniknął”).') }}</p>
        </div>
        <div class="form-group">
            <label>{{ nreg_t('Urządzenia wyłączone w LibreNMS') }}</label>
            <select class="form-control" style="width: 420px;" name="settings[disabled_devices]">
                <option value="stale" @selected(($s['disabled_devices'] ?? 'stale') === 'stale')>{{ nreg_t('zajęte; w adresacji widoczne po zaznaczeniu „Pokaż adresy z wyłączonych hostów”') }}</option>
                <option value="ignore" @selected(($s['disabled_devices'] ?? '') === 'ignore')>{{ nreg_t('ignorowane (traktowane jak wolne)') }}</option>
            </select>
            <p class="pomoc">{{ nreg_t('LibreNMS zostawia w bazie dane urządzeń wyłączonych, nawet sprzed wielu miesięcy.') }}</p>
        </div>

        <h5>{{ nreg_t('Adresy IPv4') }}</h5>
        <div class="form-group">
            <label>{{ nreg_t('Pomijane zakresy IPv4') }}</label>
            <input class="form-control nreg-mono" style="width: 520px;" name="settings[ipam_ignore]" value="{{ $s['ipam_ignore'] ?? '' }}">
            <p class="pomoc">{{ nreg_t('Prefiksy po przecinku. Adresy z tych zakresów nie trafiają do rejestru (np. loopback 127.0.0.0/8).') }}</p>
        </div>
        <div class="form-group">
            <label>{{ nreg_t('Pomijane interfejsy (wyrażenie regularne)') }}</label>
            <input class="form-control nreg-mono" style="width: 520px;" name="settings[ipam_ignore_if]" value="{{ $s['ipam_ignore_if'] ?? '' }}">
            <p class="pomoc">{{ nreg_t('Domyślnie wewnętrzne interfejsy Junipera (em0.0, jsrv.1) z adresami 128.0.0.x/2. Puste = nic nie pomijamy.') }}</p>
        </div>
        <div class="form-group">
            <label>{{ nreg_t('Poziomy grupowania drzewa (maski)') }}</label>
            <input class="form-control nreg-mono" style="width: 200px;" name="settings[ipam_levels]" value="{{ $s['ipam_levels'] ?? '8,16,24' }}">
            <p class="pomoc">{{ nreg_t('Wewnątrz agregatów (i adresacji wewnętrznej) drzewo rozwija się klasa po klasie na tych maskach, gdy nie ma tam prawdziwego prefiksu. Np. 8,16,24 albo 8,12,16,20,24.') }}</p>
        </div>
        <div class="form-group">
            <label>{{ nreg_t('Adresacja wewnętrzna') }}</label>
            <input class="form-control nreg-mono" style="width: 420px;" name="settings[ipam_private]" value="{{ $s['ipam_private'] ?? '10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10' }}">
            <p class="pomoc">{{ nreg_t('Prefiksy, którymi dysponujecie w całości (zwykle prywatne RFC 1918 i CGNAT). W drzewie są korzeniami jak agregat, z wolnym miejscem, o ile coś w nich leży. Adresacja publiczna nie dostaje nad sobą żadnej klasy: korzeniem jest najszerszy prawdziwy prefiks (sieć z LibreNMS albo agregat). Swój przydział publiczny (np. od RIPE) dodaj jako agregat.') }}</p>
        </div>

        <h5>{{ nreg_t('Adresy IPv6') }}</h5>
        <div class="form-group">
            <label>{{ nreg_t('Poziomy grupowania drzewa IPv6 (maski)') }}</label>
            <input class="form-control nreg-mono" style="width: 200px;" name="settings[ipam6_levels]" value="{{ $s['ipam6_levels'] ?? '32,48,56,64' }}">
            <p class="pomoc">{{ nreg_t('Jak w IPv4, tylko wewnątrz agregatów. W prefiksach szerszych niż /64 zajętość liczy się w blokach /64, w samej /64 - w adresach.') }}</p>
        </div>
        <div class="form-group">
            <label>{{ nreg_t('Adresacja wewnętrzna IPv6') }}</label>
            <input class="form-control nreg-mono" style="width: 420px;" name="settings[ipam6_private]" value="{{ $s['ipam6_private'] ?? 'fc00::/7' }}">
            <p class="pomoc">{{ nreg_t('Domyślnie ULA (fc00::/7). Jest w drzewie korzeniem, o ile coś w niej leży. Adresy IPv6 z LibreNMS zbiera moduł „Adresy IPv4”.') }}</p>
        </div>

        <h5>{{ nreg_t('Kopie zapasowe') }}</h5>
        <div class="form-group">
            <label>{{ nreg_t('Ile pełnych kopii trzymać') }}</label>
            <input class="form-control" style="width: 120px;" type="number" min="1" max="365" name="settings[backup_keep]" value="{{ (int) ($s['backup_keep'] ?? 14) }}">
            <p class="pomoc">{{ nreg_t('Synchronizacja robi jedną kopię na dobę, więc 14 to około dwóch tygodni. Lista kopii jest niżej.') }}</p>
        </div>

        <h5>{{ nreg_t('Baza wtyczki') }}</h5>
        <div class="form-group">
            <label>{{ nreg_t('Nazwa bazy (puste = librenms_netella_reg)') }}</label>
            <input class="form-control" style="width: 300px;" name="settings[db_name]" value="{{ $s['db_name'] ?? '' }}" pattern="[A-Za-z0-9_]{0,64}">
        </div>

        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> {{ nreg_t('Zapisz') }}</button>
    </form>

    <h5>{{ nreg_t('Kopie zapasowe') }}</h5>
    <p class="pomoc">
        {!! nreg_t('Pełna kopia bazy wtyczki (wpisy ręczne, przestrzenie, dziennik zmian, migawki LibreNMS) i ustawień, raz na dobę z synchronizacji. Pliki są w <code>{dir}</code>. Baza wtyczki jest osobna, więc kopie LibreNMS jej zwykle nie obejmują.', ['dir' => e($i['backupDir'])]) !!}
        {{ nreg_t('Ustawienia mają też osobną kopię') }}
        @if($i['settingsBackup'])<code>{{ $i['settingsBackup'] }}</code>@else({{ nreg_t('jeszcze nie powstała') }})@endif:
        {{ nreg_t('gdy znikną (np. po aktualizacji LibreNMS), synchronizacja przywróci je sama i zapisze to w dzienniku.') }}
    </p>
    <p>
        <button type="button" class="btn btn-default btn-sm" id="nreg-backup-now"><i class="fa fa-download"></i> {{ nreg_t('Utwórz kopię teraz') }}</button>
        <span id="nreg-backup-msg" class="pomoc"></span>
    </p>
    @if($i['backups'])
        @php($reasons = ['auto' => nreg_t('automatyczna'), 'manual' => nreg_t('ręczna'), 'pre-restore' => nreg_t('przed przywróceniem')])
        <table class="table table-condensed">
            <thead><tr><th>{{ nreg_t('Plik') }}</th><th>{{ nreg_t('Utworzona') }}</th><th>{{ nreg_t('Rodzaj') }}</th><th>{{ nreg_t('Rozmiar') }}</th></tr></thead>
            <tbody>
            @foreach($i['backups'] as $b)
                <tr>
                    <td class="nreg-mono"><a href="{{ $b['url'] }}">{{ $b['file'] }}</a></td>
                    <td>{{ $b['at'] }}</td>
                    <td>{{ $reasons[$b['reason']] ?? $b['reason'] }}</td>
                    <td>{{ number_format($b['size'] / 1024, 0, ',', ' ') }} KiB</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="pomoc">{{ nreg_t('Nie ma jeszcze żadnej kopii.') }}</p>
    @endif
    <p class="pomoc">
        {!! nreg_t('Przywracanie tylko z konsoli serwera. Bez <code>--force</code> pokazuje sam podgląd, a przed przywróceniem bieżący stan trafia do kopii „przed przywróceniem”:') !!}<br>
        <code>lnms netella-reg:restore &lt;{{ nreg_t('plik') }}&gt; [--with-settings] --force</code>
    </p>

    <h5>Cron</h5>
    <p class="pomoc">{!! nreg_t('Synchronizacja z LibreNMS co 5 minut (np. plik <code>/etc/cron.d/netella-reg</code>):') !!}</p>
    <code>{{ $i['cron'] }}</code>
</div>

<script>
(function () {
    // Przełącznik języka: to samo cookie i klucz localStorage co na stronie wtyczki, potem przeładowanie.
    var sel = document.getElementById('nreg-lang');
    if (!sel) { return; }
    sel.addEventListener('change', function () {
        document.cookie = @json(\Ispnetella\NetellaReg\Core\Lang::COOKIE) + '=' + sel.value + '; path=/; max-age=31536000; SameSite=Lax';
        try { localStorage.setItem('netella-reg.lang', sel.value); } catch (e) { /* brak localStorage */ }
        window.location.reload();
    });
})();

document.getElementById('nreg-backup-now').addEventListener('click', function () {
    var btn = this, msg = document.getElementById('nreg-backup-msg');
    var token = @json(csrf_token());
    btn.disabled = true; msg.textContent = @json(nreg_t('Tworzę kopię…'));
    fetch(@json($i['apiUrl']) + '/core/backup_create', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) { throw new Error(j.error || @json(nreg_t('błąd'))); }
        msg.textContent = @json(nreg_t('Utworzono {file}. Odświeżam listę…')).replace('{file}', function () { return j.created.file; });
        window.location.reload();
    }).catch(function (e) { msg.textContent = @json(nreg_t('Nie udało się: {error}')).replace('{error}', function () { return e.message; }); btn.disabled = false; });
});
</script>
