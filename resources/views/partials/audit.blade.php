{{-- Zakładka Dziennik zmian: wszystkie moduły, zmiany użytkowników i synchronizacji. --}}
<form class="nreg-filters" id="nreg-audit-form">
    <select class="form-control" name="module">
        <option value="">wszystkie moduły</option>
        <option value="core">rdzeń (ustawienia)</option>
        @foreach($nregBoot['modules'] as $m)
            <option value="{{ $m['key'] }}">{{ $m['name'] }}</option>
        @endforeach
    </select>
    <select class="form-control" name="source">
        <option value="">każde źródło</option>
        <option value="user">użytkownicy</option>
        <option value="sync">synchronizacja</option>
    </select>
    <select class="form-control" name="action" id="nreg-audit-action">
        <option value="">każde zdarzenie</option>
    </select>
    <input class="form-control" name="q" placeholder="szukaj: klucz, urządzenie, użytkownik…" style="min-width: 260px;">
    <button class="btn btn-default btn-sm" type="submit"><i class="fa fa-search"></i> Filtruj</button>
</form>

<div id="nreg-audit-body"><p class="nreg-muted">Ładowanie…</p></div>

<script>
document.addEventListener('nreg:ready', function () {
    'use strict';
    var N = window.NReg, form = document.getElementById('nreg-audit-form'), body = document.getElementById('nreg-audit-body');
    var page = 1, perPage = 50, loaded = false, labelsFilled = false;

    function params() {
        var p = { page: page, per_page: perPage };
        new FormData(form).forEach(function (v, k) { p[k] = v; });
        return p;
    }

    function load() {
        loaded = true;
        body.innerHTML = '<p class="nreg-muted">Ładowanie…</p>';
        N.api('core/audit', params()).then(function (j) {
            if (!labelsFilled) {
                var sel = document.getElementById('nreg-audit-action');
                Object.keys(j.labels).forEach(function (k) {
                    var o = document.createElement('option'); o.value = k; o.textContent = j.labels[k]; sel.appendChild(o);
                });
                labelsFilled = true;
            }
            if (!j.rows.length) { body.innerHTML = '<p class="nreg-muted">Brak wpisów.</p>'; return; }
            var pages = Math.max(1, Math.ceil(j.total / perPage));
            var h = '<table class="table table-condensed table-hover"><thead><tr>'
                + '<th style="width:150px">Data</th><th>Moduł</th><th>Rekord</th><th>Zdarzenie</th><th>Kto</th><th>Zmiany</th></tr></thead><tbody>';
            j.rows.forEach(function (r) {
                h += '<tr><td class="nreg-mono">' + N.esc(r.ts) + '</td><td>' + N.esc(r.module) + '</td>'
                    + '<td class="nreg-mono">' + N.esc(r.entity_key) + '</td><td>' + N.esc(r.action_label)
                    + (r.comment ? '<br><small class="nreg-muted">' + N.esc(r.comment) + '</small>' : '') + '</td>'
                    + '<td>' + N.who(r) + '</td><td>' + N.diffHtml(r) + '</td></tr>';
            });
            h += '</tbody></table><div class="nreg-pager">'
                + '<button class="btn btn-default btn-xs" data-p="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>&laquo; nowsze</button>'
                + '<span>strona ' + page + ' z ' + pages + ' (' + j.total + ' wpisów)</span>'
                + '<button class="btn btn-default btn-xs" data-p="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>starsze &raquo;</button></div>';
            body.innerHTML = h;
        }).catch(function (e) {
            body.innerHTML = '<div class="alert alert-danger">' + N.esc(e.message) + '</div>';
        });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); page = 1; load(); });
    body.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-p]');
        if (b && !b.disabled) { page = parseInt(b.getAttribute('data-p'), 10); load(); }
    });

    // Ładujemy dopiero przy pierwszym otwarciu zakładki.
    document.addEventListener('nreg:tab', function (e) { if (e.detail === 'audit' && !loaded) { load(); } });
    if (document.getElementById('nreg-tab-audit').classList.contains('active')) { load(); }
});
</script>
