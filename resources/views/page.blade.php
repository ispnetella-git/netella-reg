{{-- Główna strona Netella-Reg: zakładki modułów i Dziennik.

     Wspólny JS (window.NReg) daje modułom: wywołania API, escapowanie,
     formatowanie zmian z dziennika. Zakładki modułów są osobnymi widokami
     (RegModule::tabView) i korzystają z NReg po zdarzeniu "nreg:ready".

     Zmienne mają prefiks nreg - patrz Hooks\Page. --}}

<style>
    .nreg { margin-top: 12px; }
    .nreg-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
    .nreg-head h3 { margin: 0 0 8px; font-weight: 600; }
    .nreg-head .nreg-lang { margin-left: auto; width: auto; height: 28px; padding: 2px 6px; }
    .nreg-sub { font-size: 12px; opacity: .75; }
    .nreg .nav-tabs { margin-bottom: 12px; }
    .nreg-toolbar { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-bottom: 10px; }
    .nreg-toolbar form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
    .nreg-toolbar .form-control { height: 30px; padding: 4px 8px; }
    .nreg-num { width: 80px !important; }
    .nreg-mono { font-family: monospace; }
    .nreg-muted { opacity: .7; }
    .nreg-diff del { opacity: .65; }
    .nreg-diff ins { text-decoration: none; font-weight: 600; }
    .nreg-diff .sep { opacity: .5; padding: 0 4px; }
    /* Dziennik: jeden wpis = jedna linia; kliknięcie rozwija pełną treść. */
    .nreg-log { font-size: 12px; table-layout: auto; }
    .nreg-log > tbody > tr > td { white-space: nowrap; padding: 2px 6px; vertical-align: top; }
    .nreg-log > tbody > tr > td.nreg-diff { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 0; width: 100%; }
    .nreg-log > tbody > tr { cursor: pointer; }
    .nreg-log > tbody > tr.open > td.nreg-diff { white-space: normal; word-break: break-word; }
    .nreg-log > thead > tr > th { padding: 3px 6px; font-size: 12px; }
    .nreg-pager { display: flex; gap: 8px; align-items: center; }
    .nreg-filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
    .nreg-filters .form-control { height: 30px; padding: 4px 8px; width: auto; }
    /* Wspólne dla modułów: formularz wpisu ręcznego i blok wpisu w szczegółach. */
    .nreg-form { border: 1px solid rgba(128,128,128,.35); border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; max-width: 900px; }
    .nreg-form .row { margin-bottom: 6px; }
    .nreg-form label { font-weight: 600; margin-bottom: 2px; font-size: 12px; }
    .nreg-form .req::after { content: ' *'; color: #d9534f; }
    .nreg-manual { border-left: 4px solid #b57edc; padding: 6px 10px; margin: 8px 0; }
    /* Wiersz drzewa znaleziony przez „Szukaj” (albo link do adresu, prefiksu, zakresu). */
    .nreg-found { background: rgba(255, 193, 7, .45) !important; box-shadow: inset 3px 0 0 #f0ad4e; animation: nreg-flash .6s ease-in-out 3; }
    @keyframes nreg-flash { 50% { background: rgba(255, 152, 0, .85); } }
    .nreg-manual dl { margin: 0; }
    .nreg-manual dt { float: left; width: 130px; font-weight: normal; opacity: .75; }
    .nreg-manual dd { margin-left: 140px; }
</style>

<div class="container-fluid nreg">
    <div class="nreg-head">
        <h3>Netella-Reg</h3>
        <span class="nreg-sub">{{ nreg_t('rejestr VLAN-ów i adresacji') }} &middot; {{ nreg_t('wersja {v}', ['v' => $nregBoot['version']]) }}</span>
        <select class="form-control nreg-lang" id="nreg-lang" title="{{ nreg_t('Język interfejsu') }}">
            @foreach(\Ispnetella\NetellaReg\Core\Lang::LANGS as $code => $label)
                <option value="{{ $code }}" @if($code === $nregBoot['lang']) selected @endif>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if($nregError)
        <div class="alert alert-danger">
            <strong>{{ nreg_t('Netella-Reg nie może odczytać swojej bazy.') }}</strong>
            <pre style="white-space: pre-wrap; margin: 6px 0 0;">{{ $nregError }}</pre>
        </div>
    @else
        <ul class="nav nav-tabs" role="tablist" id="nreg-tabs">
            @foreach($nregTabs as $i => $tab)
                <li role="presentation" @if($i === 0) class="active" @endif>
                    <a href="#nreg-tab-{{ $tab['key'] }}" data-key="{{ $tab['key'] }}" role="tab" data-toggle="tab">{{ $tab['name'] }}</a>
                </li>
            @endforeach
            <li role="presentation" @if(count($nregTabs) === 0) class="active" @endif>
                <a href="#nreg-tab-audit" data-key="audit" role="tab" data-toggle="tab">{{ nreg_t('Dziennik zmian') }}</a>
            </li>
        </ul>

        <div class="tab-content">
            @foreach($nregTabs as $i => $tab)
                <div role="tabpanel" class="tab-pane @if($i === 0) active @endif" id="nreg-tab-{{ $tab['key'] }}">
                    @include($tab['view'], ['tab' => $tab])
                </div>
            @endforeach
            <div role="tabpanel" class="tab-pane @if(count($nregTabs) === 0) active @endif" id="nreg-tab-audit">
                @include('Netella-Reg::partials.audit')
            </div>
        </div>
    @endif
</div>

<script type="application/json" id="nreg-boot">{!! json_encode($nregBoot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
<script>
(function () {
    'use strict';
    var boot = JSON.parse(document.getElementById('nreg-boot').textContent);

    // Tłumaczenie: kluczem jest polski tekst, słownik (boot.i18n) jest pusty po polsku.
    // Wstawki {nazwa} wypełnia drugi argument: t("Usunięto {n} wpisów", { n: 3 }).
    var DICT = boot.i18n || {};
    function t(pl, vars) {
        var s = Object.prototype.hasOwnProperty.call(DICT, pl) ? DICT[pl] : pl;
        if (vars) {
            s = s.replace(/\{(\w+)\}/g, function (m, k) { return vars[k] !== undefined && vars[k] !== null ? String(vars[k]) : m; });
        }
        return s;
    }

    // Przełącznik języka: cookie (czyta je serwer: widoki i komunikaty API) i localStorage, potem przeładowanie.
    var LANG_KEY = 'netella-reg.lang';
    function setLang(l) {
        document.cookie = boot.langCookie + '=' + l + '; path=/; max-age=31536000; SameSite=Lax';
        try { localStorage.setItem(LANG_KEY, l); } catch (e) { /* brak localStorage */ }
    }
    (function () {
        var saved = null;
        try { saved = localStorage.getItem(LANG_KEY); } catch (e) { saved = null; }
        // Cookie wygasło albo zostało wyczyszczone, a przeglądarka pamięta inny wybór.
        if (saved && saved !== boot.lang && document.cookie.indexOf(boot.langCookie + '=') < 0) { setLang(saved); location.reload(); }
    })();
    var langSel = document.getElementById('nreg-lang');
    if (langSel) { langSel.addEventListener('change', function () { setLang(langSel.value); location.reload(); }); }

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function api(path, params, method) {
        method = method || 'GET';
        var url = boot.api + '/' + path;
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method === 'GET' && params) {
            var qs = new URLSearchParams();
            Object.keys(params).forEach(function (k) {
                if (params[k] !== '' && params[k] !== null && params[k] !== undefined) { qs.append(k, params[k]); }
            });
            if (qs.toString()) { url += '?' + qs.toString(); }
        } else if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-TOKEN'] = boot.csrf;
            opts.body = JSON.stringify(params || {});
        }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; });
        }).then(function (j) {
            if (!j.ok) { throw new Error(j.error || t('Błąd')); }
            return j;
        });
    }

    function short(v, max) {
        max = max || 160;
        var s = (v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v === null || v === undefined ? '∅' : v);
        return s.length > max ? s.slice(0, max) + '…' : s;
    }

    // Czytelne nazwy pól w dzienniku. Moduły mogą dopisać własne: NReg.fieldLabels.x = '...'.
    var FIELD_LABELS = {
        vlans: t('VLAN-y'), vlan: 'VLAN', name: t('nazwa'), description: t('opis'), owner: t('właściciel'), status: t('status'),
        devices: t('urządzenia'), adopted_at: t('przejęty'), tagged: t('tagowane'), untagged: t('nietagowane'), l3: 'L3',
        subif: t('subinterfejsy'), defined: t('w tabeli vlans'), disabled: t('wyłączone'), device: t('urządzenie'),
        valid_until: t('ważne do')
    };
    // Pola pomijane w linii dziennika (powtarzają informację z kolumny "Rekord").
    var SKIP_FIELDS = { count: 1, vlans: 1, device_id: 1, device: 1, hostname: 1, ips: 1, prefix: 1, range: 1, family: 1, port_id: 1, space: 1 };

    // Zmiany z dziennika w jednej linii: "pole: przed → po · pole2: …".
    function diffHtml(row) {
        var b = row.before || {}, a = row.after || {};
        if (row.action === 'initial_import') { return t('zaimportowano {n} rekordów', { n: esc(a.count) }); }
        var keys = Object.keys(b).concat(Object.keys(a)).filter(function (k, i, arr) {
            return arr.indexOf(k) === i && !SKIP_FIELDS[k];
        });
        var parts = keys.map(function (k) {
            var hasB = row.before !== null && Object.prototype.hasOwnProperty.call(b, k);
            var hasA = row.after !== null && Object.prototype.hasOwnProperty.call(a, k);
            var h = '<span class="nreg-muted">' + esc(FIELD_LABELS[k] || k) + ':</span> ';
            if (hasB) { h += '<del>' + esc(short(b[k], 80)) + '</del>'; }
            if (hasB && hasA) { h += ' → '; }
            if (hasA) { h += '<ins>' + esc(short(a[k], 80)) + '</ins>'; }
            return h;
        });
        return parts.join('<span class="sep">·</span>');
    }

    // Czego dotyczy wpis, po ludzku: "VLAN 1313-1399 (87)", "VLAN 100 · ASR KAMYK".
    function recordLabel(row) {
        var b = row.before || {}, a = row.after || {}, m;
        var list = a.vlans || b.vlans, count = a.count || b.count;
        if (row.entity_key === '*') { return t('wszystkie'); }
        if (row.module === 'core') { return t('ustawienia'); }
        var more = count > 1 ? ' <span class="nreg-muted">(' + count + ')</span>' : '';
        var entry = ' <span class="nreg-muted">(' + t('wpis') + ')</span>';
        if (list) { return 'VLAN ' + esc(list) + more; }
        if (a.range || b.range) { return t('zakres {range}', { range: esc(a.range || b.range) }); }
        if (a.ips || b.ips) { return 'IP ' + esc(a.ips || b.ips) + more; }
        if (a.prefix || b.prefix) { return esc(a.prefix || b.prefix); }
        if ((m = /^v(\d+):d(\d+)$/.exec(row.entity_key))) { return 'VLAN ' + m[1] + ' · ' + esc(a.device || b.device || t('urządzenie {id}', { id: m[2] })); }
        if ((m = /^v(\d+):m\d+$/.exec(row.entity_key))) { return 'VLAN ' + m[1] + entry; }
        if ((m = /^a:([^/]+)\/(\d+):p\d+$/.exec(row.entity_key))) { return 'IP ' + esc(m[1]) + '/' + m[2] + ' · ' + esc(a.device || b.device || '') + ' ' + esc(a['if'] || b['if'] || ''); }
        if ((m = /^a:([^:]+):m\d+$/.exec(row.entity_key))) { return 'IP ' + esc(m[1]) + entry; }
        if ((m = /^r:([^:]+):m\d+$/.exec(row.entity_key))) { return t('zakres {range}', { range: esc(m[1]) }) + entry; }
        if ((m = /^n:([^:]+):m\d+$/.exec(row.entity_key))) { return esc(m[1]) + entry; }
        if ((m = /^s(\d+)$/.exec(row.entity_key))) { return t('przestrzeń {name}', { name: esc(a.name || b.name || m[1]) }); }
        return esc(row.entity_key);
    }

    function who(row) {
        return row.source === 'sync' ? '<span class="nreg-muted">' + t('synchronizacja') + '</span>' : esc(row.username || '?');
    }

    // Jeden wiersz dziennika. withModule: kolumna modułu (w zakładce Dziennik).
    function logRow(r, withModule) {
        return '<tr title="' + esc(t('kliknij, aby rozwinąć')) + '"><td class="nreg-mono">' + esc(r.ts) + '</td>'
            + (withModule ? '<td>' + esc(r.module) + '</td>' : '')
            + '<td>' + recordLabel(r) + '</td><td>' + esc(r.action_label) + '</td><td>' + who(r) + '</td>'
            + '<td class="nreg-diff">' + diffHtml(r)
            + (r.comment ? '<span class="sep">·</span><span class="nreg-muted">„' + esc(r.comment) + '”</span>' : '') + '</td></tr>';
    }

    function logTable(rows, withModule) {
        return '<table class="table table-condensed table-hover nreg-log"><thead><tr><th>' + t('Data') + '</th>'
            + (withModule ? '<th>' + t('Moduł') + '</th>' : '') + '<th>' + t('Rekord') + '</th><th>' + t('Zdarzenie') + '</th><th>'
            + t('Kto') + '</th><th>' + t('Zmiany') + '</th></tr></thead><tbody>'
            + rows.map(function (r) { return logRow(r, withModule); }).join('') + '</tbody></table>';
    }

    // Rozwijanie wiersza dziennika kliknięciem (pełna treść zamiast "…").
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('table.nreg-log > tbody > tr');
        if (tr && !e.target.closest('a,button')) { tr.classList.toggle('open'); }
    });

    // Termin ważności wpisu ręcznego (pola valid_until, expiry, days_left z API).
    function expiryHtml(m) {
        if (!m || !m.valid_until) { return ''; }
        if (m.expiry === 'expired') {
            return '<span class="label label-danger" title="' + esc(t('wpis nadal zajmuje zasób')) + '">'
                + t('termin minął {date}', { date: esc(m.valid_until) }) + '</span>';
        }
        if (m.expiry === 'soon') {
            return '<span class="label label-warning">' + (m.days_left === 0
                ? t('do {date} (mija dziś)', { date: esc(m.valid_until) })
                : t('do {date} (za {n} dni)', { date: esc(m.valid_until), n: esc(m.days_left) })) + '</span>';
        }
        return '<span class="nreg-muted">' + t('do {date}', { date: esc(m.valid_until) }) + '</span>';
    }

    window.NReg = {
        boot: boot, t: t, api: api, esc: esc, diffHtml: diffHtml, who: who, short: short,
        recordLabel: recordLabel, logTable: logTable, fieldLabels: FIELD_LABELS, expiryHtml: expiryHtml
    };

    // Zakładka z adresu (#vlan, #audit) i zapamiętywanie wybranej.
    function showTab(key) {
        var a = document.querySelector('#nreg-tabs a[data-key="' + key + '"]');
        if (a && window.jQuery) { window.jQuery(a).tab('show'); }
    }
    if (location.hash) { showTab(location.hash.slice(1)); }
    if (window.jQuery) {
        window.jQuery('#nreg-tabs a').on('shown.bs.tab', function (e) {
            var key = e.target.getAttribute('data-key');
            history.replaceState(null, '', location.pathname + location.search + '#' + key);
            document.dispatchEvent(new CustomEvent('nreg:tab', { detail: key }));
        });
    }

    document.dispatchEvent(new CustomEvent('nreg:ready'));
})();
</script>
