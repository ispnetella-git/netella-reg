{{-- Główna strona Netella-Reg: zakładki modułów i Dziennik.

     Wspólny JS (window.NReg) daje modułom: wywołania API, escapowanie,
     formatowanie zmian z dziennika. Zakładki modułów są osobnymi widokami
     (RegModule::tabView) i korzystają z NReg po zdarzeniu "nreg:ready".

     Zmienne mają prefiks nreg - patrz Hooks\Page. --}}

<style>
    .nreg { margin-top: 12px; }
    .nreg-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
    .nreg-head h3 { margin: 0 0 8px; font-weight: 600; }
    .nreg-sub { font-size: 12px; opacity: .75; }
    .nreg .nav-tabs { margin-bottom: 12px; }
    .nreg-toolbar { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-bottom: 10px; }
    .nreg-toolbar form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
    .nreg-toolbar .form-control { height: 30px; padding: 4px 8px; }
    .nreg-num { width: 80px !important; }
    .nreg-mono { font-family: monospace; }
    .nreg-muted { opacity: .7; }
    .nreg-diff { font-size: 12px; margin: 0; padding-left: 16px; }
    .nreg-diff li { word-break: break-word; }
    .nreg-diff del { opacity: .65; }
    .nreg-diff ins { text-decoration: none; font-weight: 600; }
    .nreg-pager { display: flex; gap: 8px; align-items: center; }
    .nreg-filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
    .nreg-filters .form-control { height: 30px; padding: 4px 8px; width: auto; }
</style>

<div class="container-fluid nreg">
    <div class="nreg-head">
        <h3>Netella-Reg</h3>
        <span class="nreg-sub">rejestr VLAN-ów i adresacji &middot; wersja {{ $nregBoot['version'] }}</span>
    </div>

    @if($nregError)
        <div class="alert alert-danger">
            <strong>Netella-Reg nie może odczytać swojej bazy.</strong>
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
                <a href="#nreg-tab-audit" data-key="audit" role="tab" data-toggle="tab">Dziennik zmian</a>
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
            if (!j.ok) { throw new Error(j.error || 'Błąd'); }
            return j;
        });
    }

    function short(v, max) {
        max = max || 160;
        var s = (v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v === null || v === undefined ? '∅' : v);
        return s.length > max ? s.slice(0, max) + '…' : s;
    }

    // Zmiany z dziennika jako lista "pole: przed → po".
    function diffHtml(row) {
        var b = row.before || {}, a = row.after || {};
        if (row.action === 'initial_import') { return 'zaimportowano ' + esc(a.count) + ' rekordów'; }
        var keys = Object.keys(b).concat(Object.keys(a)).filter(function (k, i, arr) { return arr.indexOf(k) === i; });
        if (!keys.length) { return ''; }
        var html = '<ul class="nreg-diff">';
        keys.forEach(function (k) {
            var hasB = Object.prototype.hasOwnProperty.call(b, k), hasA = Object.prototype.hasOwnProperty.call(a, k);
            html += '<li><span class="nreg-mono">' + esc(k) + '</span>: ';
            if (hasB && row.before !== null) { html += '<del>' + esc(short(b[k])) + '</del>'; }
            if (hasB && hasA && row.before !== null && row.after !== null) { html += ' → '; }
            if (hasA && row.after !== null) { html += '<ins>' + esc(short(a[k])) + '</ins>'; }
            html += '</li>';
        });
        return html + '</ul>';
    }

    function who(row) {
        return row.source === 'sync' ? '<span class="nreg-muted">synchronizacja</span>' : esc(row.username || '?');
    }

    window.NReg = { boot: boot, api: api, esc: esc, diffHtml: diffHtml, who: who, short: short };

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
