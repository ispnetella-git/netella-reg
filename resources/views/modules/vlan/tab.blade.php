{{-- Zakładka modułu VLAN: mapa numerów 1-4094, sprawdzanie zakresu,
     szukanie wolnych numerów, szczegóły i historia numeru.

     Adres ?vlan=1400#vlan otwiera szczegóły numeru, ?range=1400-1450#vlan
     sprawdza zakres - z takich linków korzysta panel na stronie urządzenia. --}}

<style>
    .nreg-vgrid { overflow-x: auto; padding-bottom: 6px; user-select: none; }
    .nreg-vrow { display: flex; align-items: center; height: 12px; margin-bottom: 1px; white-space: nowrap; }
    .nreg-vrow > b { display: inline-block; width: 42px; font: 10px monospace; font-weight: normal; text-align: right; padding-right: 6px; opacity: .7; }
    .nreg-vrow i { display: inline-block; width: 10px; height: 11px; margin-right: 1px; cursor: pointer; border-radius: 1px; }
    .nreg-vrow i:nth-of-type(10n) { margin-right: 4px; }
    .nreg-vrow i.x { visibility: hidden; cursor: default; }
    .nreg-vrow i.hl { outline: 2px solid #d9534f; outline-offset: -1px; }
    .nreg-vrow i.sel { outline: 2px solid #000; outline-offset: 0; }
    .nreg-v-f { background: #dff0d8; }
    .nreg-v-l { background: #337ab7; }
    .nreg-v-s { background: #999; }
    .nreg-v-g { background: #f0ad4e; }
    .nreg-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; margin: 4px 0 8px; }
    .nreg-legend span i { display: inline-block; width: 12px; height: 12px; vertical-align: -2px; margin-right: 4px; border-radius: 2px; }
    .nreg-ports { font-size: 12px; }
    .nreg-ports summary { cursor: pointer; }
    #nreg-vlan-detail h4 { margin-top: 14px; }
</style>

<div class="nreg-toolbar">
    <form id="nreg-vlan-check">
        <label for="nreg-vlan-range" style="margin:0">Zakres:</label>
        <input class="form-control nreg-mono" id="nreg-vlan-range" placeholder="np. 1400-1450" style="width: 180px;">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-check"></i> Sprawdź</button>
    </form>
    <form id="nreg-vlan-free">
        <span>Znajdź</span>
        <input class="form-control nreg-num" type="number" min="1" max="4094" value="1" id="nreg-vlan-count">
        <span>kolejnych wolnych od</span>
        <input class="form-control nreg-num" type="number" min="1" max="4094" value="2" id="nreg-vlan-from">
        <span>do</span>
        <input class="form-control nreg-num" type="number" min="1" max="4094" value="4094" id="nreg-vlan-to">
        <button class="btn btn-default btn-sm" type="submit"><i class="fa fa-search"></i> Szukaj</button>
    </form>
    <span class="nreg-sub" id="nreg-vlan-sync"></span>
    @if($nregBoot['canAdmin'])
        <button class="btn btn-default btn-xs" id="nreg-vlan-syncnow" type="button"><i class="fa fa-refresh"></i> Synchronizuj teraz</button>
    @endif
</div>

<div id="nreg-vlan-result"></div>
<div class="nreg-legend" id="nreg-vlan-legend"></div>
<div class="nreg-vgrid" id="nreg-vlan-grid"><p class="nreg-muted">Ładowanie mapy…</p></div>
<div id="nreg-vlan-detail"></div>

<script>
document.addEventListener('nreg:ready', function () {
    'use strict';
    var N = window.NReg, esc = N.esc;
    var grid = document.getElementById('nreg-vlan-grid'), result = document.getElementById('nreg-vlan-result');
    var detail = document.getElementById('nreg-vlan-detail'), legend = document.getElementById('nreg-vlan-legend');
    var statuses = '', labels = {}, selected = null;
    var STATE_CSS = { f: 'nreg-v-f', l: 'nreg-v-l', s: 'nreg-v-s', g: 'nreg-v-g' };

    function cell(v) { return grid.querySelector('i[data-v="' + v + '"]'); }

    function renderGrid() {
        var h = '';
        for (var row = 0; row <= 40; row++) {
            h += '<div class="nreg-vrow"><b>' + (row * 100) + '</b>';
            for (var c = 0; c < 100; c++) {
                var v = row * 100 + c;
                if (v < 1 || v > 4094) { h += '<i class="x"></i>'; continue; }
                var s = statuses.charAt(v - 1) || 'f';
                h += '<i data-v="' + v + '" class="' + STATE_CSS[s] + '" title="VLAN ' + v + ': ' + esc(labels[s] || s) + '"></i>';
            }
            h += '</div>';
        }
        grid.innerHTML = h;
    }

    function renderLegend(counts) {
        legend.innerHTML = Object.keys(labels).map(function (s) {
            return '<span><i class="' + STATE_CSS[s] + '"></i>' + esc(labels[s]) + ': <strong>' + (counts[s] || 0) + '</strong></span>';
        }).join('');
    }

    function renderSync(sync) {
        var el = document.getElementById('nreg-vlan-sync');
        if (!sync) { el.innerHTML = '<span class="text-warning">Brak synchronizacji. Uruchom <code>lnms netella-reg:sync</code> albo dodaj cron.</span>'; return; }
        el.innerHTML = 'Dane z LibreNMS z ' + esc(sync.at) + (sync.ok ? '' : ' <span class="text-danger">(błąd: ' + esc(sync.error) + ')</span>');
    }

    function loadMap() {
        return N.api('vlan/map').then(function (j) {
            statuses = j.statuses; labels = j.labels;
            renderLegend(j.counts); renderGrid(); renderSync(j.sync);
        }).catch(function (e) { grid.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }

    function highlight(numbers) {
        grid.querySelectorAll('i.hl').forEach(function (i) { i.classList.remove('hl'); });
        (numbers || []).forEach(function (v) { var c = cell(v); if (c) { c.classList.add('hl'); } });
    }

    function expand(list) {
        var out = [];
        String(list || '').split(',').forEach(function (p) {
            if (!p) { return; }
            var ab = p.split('-'), a = parseInt(ab[0], 10), b = parseInt(ab[1] || ab[0], 10);
            for (var v = a; v <= b; v++) { out.push(v); }
        });
        return out;
    }

    function check(range) {
        result.innerHTML = '<p class="nreg-muted">Sprawdzam…</p>';
        N.api('vlan/check', { range: range }).then(function (j) {
            highlight(expand(j.range));
            var free = j.summary.f ? j.summary.f.count : 0;
            if (j.all_free) {
                result.innerHTML = '<div class="alert alert-success"><strong>Wolne.</strong> Wszystkie numery z zakresu <span class="nreg-mono">'
                    + esc(j.range) + '</span> (' + j.total + ') są wolne w całej sieci.</div>';
                return;
            }
            var h = '<div class="alert alert-warning"><strong>Zajęte ' + (j.total - free) + ' z ' + j.total + '.</strong> ';
            Object.keys(j.summary).forEach(function (s) {
                h += '<br>' + esc(j.summary[s].label) + ' (' + j.summary[s].count + '): <span class="nreg-mono">' + esc(j.summary[s].list) + '</span>';
            });
            h += '</div><table class="table table-condensed"><thead><tr><th>VLAN</th><th>Status</th><th>Urządzenia</th></tr></thead><tbody>';
            j.occupied.forEach(function (o) {
                h += '<tr><td><a href="#" data-vlan="' + o.vlan + '">' + o.vlan + '</a></td><td>' + esc(labels[o.status] || o.status)
                    + '</td><td>' + esc(o.devices.join(', ')) + '</td></tr>';
            });
            result.innerHTML = h + '</tbody></table>';
        }).catch(function (e) { result.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }

    function findFree() {
        var p = {
            count: document.getElementById('nreg-vlan-count').value,
            from: document.getElementById('nreg-vlan-from').value,
            to: document.getElementById('nreg-vlan-to').value
        };
        N.api('vlan/free', p).then(function (j) {
            if (!j.found) {
                highlight([]);
                result.innerHTML = '<div class="alert alert-warning">Brak ' + j.count + ' kolejnych wolnych numerów w przedziale ' + j.from + '-' + j.to + '.</div>';
                return;
            }
            highlight(expand(j.range));
            result.innerHTML = '<div class="alert alert-success">Pierwszy wolny ciąg ' + j.count + ' numerów: <strong class="nreg-mono">'
                + esc(j.range) + '</strong></div>';
            document.getElementById('nreg-vlan-range').value = j.range;
        }).catch(function (e) { result.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }

    function portList(list) {
        if (!list || !list.length) { return ''; }
        if (list.length <= 6) { return esc(list.join(', ')); }
        return '<details class="nreg-ports"><summary>' + list.length + ' portów</summary>' + esc(list.join(', ')) + '</details>';
    }

    function showDetail(v) {
        if (selected) { var o = cell(selected); if (o) { o.classList.remove('sel'); } }
        selected = v;
        var c = cell(v); if (c) { c.classList.add('sel'); }
        detail.innerHTML = '<p class="nreg-muted">Ładowanie VLAN-u ' + v + '…</p>';
        N.api('vlan/detail', { vlan: v }).then(function (j) {
            var h = '<h4>VLAN ' + j.vlan + ' &middot; ' + esc(j.status_label)
                + (j.names.length ? ' &middot; <span class="nreg-muted">' + esc(j.names.join(' / ')) + '</span>' : '') + '</h4>';
            if (j.names.length > 1) {
                h += '<div class="alert alert-info" style="padding:6px 10px">Ten numer ma różne nazwy na różnych urządzeniach.</div>';
            }
            if (!j.occurrences.length) {
                h += '<p>LibreNMS nie widział tego numeru na żadnym urządzeniu.</p>';
            } else {
                h += '<table class="table table-condensed table-hover"><thead><tr><th>Urządzenie</th><th>Nazwa</th><th>Tagowane</th>'
                    + '<th>Nietagowane</th><th>L3</th><th>Subinterfejsy</th><th>Widziany</th><th>Stan</th></tr></thead><tbody>';
                j.occurrences.forEach(function (o) {
                    h += '<tr' + (o.state === 'f' ? ' class="nreg-muted"' : '') + '><td><a href="' + esc(o.device_url) + '">' + esc(o.device) + '</a>'
                        + (o.disabled ? ' <span class="label label-default">wyłączone</span>' : '') + '</td>'
                        + '<td>' + esc(o.name || '') + (o.defined ? '' : ' <small class="nreg-muted" title="brak w tabeli vlans">(bez definicji)</small>') + '</td>'
                        + '<td>' + portList(o.tagged) + '</td><td>' + portList(o.untagged) + '</td>'
                        + '<td>' + portList(o.l3) + '</td><td>' + portList(o.subif) + '</td>'
                        + '<td class="nreg-mono" style="font-size:11px">' + esc(o.first_seen) + '<br>' + esc(o.gone_at ? 'zniknął ' + o.gone_at : o.last_seen) + '</td>'
                        + '<td>' + esc(o.state_label) + '</td></tr>';
                });
                h += '</tbody></table>';
            }
            h += '<h4>Historia</h4>';
            if (!j.history.length) {
                h += '<p class="nreg-muted">Brak zmian od pierwszego importu.</p>';
            } else {
                h += '<table class="table table-condensed"><thead><tr><th style="width:150px">Data</th><th>Rekord</th><th>Zdarzenie</th><th>Kto</th><th>Zmiany</th></tr></thead><tbody>';
                j.history.forEach(function (r) {
                    h += '<tr><td class="nreg-mono">' + esc(r.ts) + '</td><td class="nreg-mono">' + esc(r.entity_key) + '</td><td>'
                        + esc(r.action_label) + '</td><td>' + N.who(r) + '</td><td>' + N.diffHtml(r) + '</td></tr>';
                });
                h += '</tbody></table>';
            }
            detail.innerHTML = h;
        }).catch(function (e) { detail.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }

    grid.addEventListener('click', function (e) {
        var i = e.target.closest('i[data-v]');
        if (i) { showDetail(parseInt(i.getAttribute('data-v'), 10)); }
    });
    result.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-vlan]');
        if (a) { e.preventDefault(); showDetail(parseInt(a.getAttribute('data-vlan'), 10)); detail.scrollIntoView({ behavior: 'smooth' }); }
    });
    document.getElementById('nreg-vlan-check').addEventListener('submit', function (e) {
        e.preventDefault(); check(document.getElementById('nreg-vlan-range').value);
    });
    document.getElementById('nreg-vlan-free').addEventListener('submit', function (e) { e.preventDefault(); findFree(); });

    var syncBtn = document.getElementById('nreg-vlan-syncnow');
    if (syncBtn) {
        syncBtn.addEventListener('click', function () {
            syncBtn.disabled = true;
            N.api('core/sync', {}, 'POST').then(function (j) {
                result.innerHTML = '<div class="alert alert-info"><pre style="margin:0;white-space:pre-wrap">' + esc(j.log.join('\n')) + '</pre></div>';
                return loadMap();
            }).catch(function (e) {
                result.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
            }).then(function () { syncBtn.disabled = false; });
        });
    }

    loadMap().then(function () {
        var q = new URLSearchParams(location.search);
        if (q.get('range')) { document.getElementById('nreg-vlan-range').value = q.get('range'); check(q.get('range')); }
        var v = parseInt(q.get('vlan') || '', 10);
        if (v >= 1 && v <= 4094) { showDetail(v); }
    });
});
</script>
