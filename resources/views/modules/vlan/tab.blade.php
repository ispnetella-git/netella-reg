{{-- Zakładka modułu VLAN: mapa numerów 1-4094, sprawdzanie zakresu,
     szukanie wolnych numerów, wpisy ręczne, szczegóły i historia numeru.

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
    .nreg-vrow i.mk { box-shadow: inset 0 -3px 0 #8e44ad; }
    .nreg-v-f { background: #dff0d8; }
    .nreg-v-l { background: #337ab7; }
    .nreg-v-s { background: #999; }
    .nreg-v-m { background: #8e44ad; }
    .nreg-v-r { background: #5bc0de; }
    .nreg-v-g { background: #f0ad4e; }
    .nreg-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; margin: 4px 0 8px; }
    .nreg-legend span i { display: inline-block; width: 12px; height: 12px; vertical-align: -2px; margin-right: 4px; border-radius: 2px; }
    .nreg-ports { font-size: 12px; }
    .nreg-ports summary { cursor: pointer; }
    #nreg-vlan-detail h4 { margin-top: 14px; }
    .nreg-form { border: 1px solid rgba(128,128,128,.35); border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; max-width: 900px; }
    .nreg-form .row { margin-bottom: 6px; }
    .nreg-form label { font-weight: 600; margin-bottom: 2px; font-size: 12px; }
    .nreg-form .req::after { content: ' *'; color: #d9534f; }
    .nreg-manual { border-left: 4px solid #8e44ad; padding: 6px 10px; margin: 8px 0; }
    .nreg-manual dl { margin: 0; }
    .nreg-manual dt { float: left; width: 130px; font-weight: normal; opacity: .75; }
    .nreg-manual dd { margin-left: 140px; }
    #nreg-vlan-manual-list summary { cursor: pointer; font-weight: 600; margin: 6px 0; }
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
    @if($nregBoot['canEdit'])
        <button class="btn btn-success btn-sm" id="nreg-vlan-add" type="button"><i class="fa fa-plus"></i> Dodaj VLAN</button>
    @endif
    <span class="nreg-sub" id="nreg-vlan-sync"></span>
    @if($nregBoot['canAdmin'])
        <button class="btn btn-default btn-xs" id="nreg-vlan-syncnow" type="button"><i class="fa fa-refresh"></i> Synchronizuj teraz</button>
    @endif
</div>

@if($nregBoot['canEdit'])
    <form class="nreg-form" id="nreg-vlan-form" style="display:none" autocomplete="off">
        <h4 id="nreg-vf-title" style="margin-top:0">Dodaj VLAN</h4>
        <input type="hidden" name="id">
        <div class="row">
            <div class="col-sm-3">
                <label class="req" for="nreg-vf-range">Numer lub zakres</label>
                <input class="form-control nreg-mono" id="nreg-vf-range" name="range" placeholder="np. 1400 albo 1400-1405">
            </div>
            <div class="col-sm-3">
                <label for="nreg-vf-status">Status</label>
                <select class="form-control" id="nreg-vf-status" name="status">
                    <option value="used">używany</option>
                    <option value="reserved">rezerwacja</option>
                </select>
            </div>
            <div class="col-sm-6">
                <label for="nreg-vf-name">Nazwa</label>
                <input class="form-control" id="nreg-vf-name" name="name" maxlength="64" placeholder="np. KLIENT-X-BSA">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <label class="req" for="nreg-vf-description">Opis</label>
                <textarea class="form-control" id="nreg-vf-description" name="description" rows="2" maxlength="1000"
                          placeholder="do czego służy, skąd dokąd biegnie"></textarea>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6">
                <label for="nreg-vf-owner">Właściciel / klient</label>
                <input class="form-control" id="nreg-vf-owner" name="owner" maxlength="128">
            </div>
            <div class="col-sm-6">
                <label for="nreg-vf-devices">Urządzenia / lokalizacja</label>
                <input class="form-control" id="nreg-vf-devices" name="devices" maxlength="1000" placeholder="opcjonalnie, np. S6730 Kamyk, ASR KOP">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <label for="nreg-vf-comment">Komentarz do zmiany (trafi do dziennika)</label>
                <input class="form-control" id="nreg-vf-comment" name="comment" maxlength="1000" placeholder="opcjonalnie, np. numer zgłoszenia">
            </div>
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-save"></i> Zapisz</button>
        <button class="btn btn-default btn-sm" type="button" id="nreg-vf-cancel">Anuluj</button>
        <span class="text-danger" id="nreg-vf-error" style="margin-left:10px"></span>
    </form>
@endif

<div id="nreg-vlan-result"></div>
<div class="nreg-legend" id="nreg-vlan-legend"></div>
<div class="nreg-vgrid" id="nreg-vlan-grid"><p class="nreg-muted">Ładowanie mapy…</p></div>
<details id="nreg-vlan-manual-list"><summary>Wpisy ręczne</summary><div></div></details>
<div id="nreg-vlan-detail"></div>

<script>
document.addEventListener('nreg:ready', function () {
    'use strict';
    var N = window.NReg, esc = N.esc, can = N.boot;
    var grid = document.getElementById('nreg-vlan-grid'), result = document.getElementById('nreg-vlan-result');
    var detail = document.getElementById('nreg-vlan-detail'), legend = document.getElementById('nreg-vlan-legend');
    var form = document.getElementById('nreg-vlan-form'), manualList = document.getElementById('nreg-vlan-manual-list');
    var statuses = '', manualMask = '', labels = {}, selected = null;
    var STATE_CSS = { f: 'nreg-v-f', l: 'nreg-v-l', s: 'nreg-v-s', m: 'nreg-v-m', r: 'nreg-v-r', g: 'nreg-v-g' };

    function cell(v) { return grid.querySelector('i[data-v="' + v + '"]'); }
    function alertBox(kind, html) { return '<div class="alert alert-' + kind + '">' + html + '</div>'; }

    function renderGrid() {
        var h = '';
        for (var row = 0; row <= 40; row++) {
            h += '<div class="nreg-vrow"><b>' + (row * 100) + '</b>';
            for (var c = 0; c < 100; c++) {
                var v = row * 100 + c;
                if (v < 1 || v > 4094) { h += '<i class="x"></i>'; continue; }
                var s = statuses.charAt(v - 1) || 'f', mk = manualMask.charAt(v - 1) === '1';
                // Pasek u dołu: wpis ręczny przy numerze, który ma też dane LibreNMS.
                var cls = STATE_CSS[s] + (mk && s !== 'm' && s !== 'r' ? ' mk' : '');
                h += '<i data-v="' + v + '" class="' + cls + '" title="VLAN ' + v + ': ' + esc(labels[s] || s)
                    + (mk && s !== 'm' && s !== 'r' ? ' + wpis ręczny' : '') + '"></i>';
            }
            h += '</div>';
        }
        grid.innerHTML = h;
        if (selected) { var o = cell(selected); if (o) { o.classList.add('sel'); } }
    }

    function renderLegend(counts) {
        legend.innerHTML = Object.keys(labels).map(function (s) {
            return '<span><i class="' + STATE_CSS[s] + '"></i>' + esc(labels[s]) + ': <strong>' + (counts[s] || 0) + '</strong></span>';
        }).join('') + '<span><i class="nreg-v-l" style="box-shadow: inset 0 -3px 0 #8e44ad"></i>LibreNMS + wpis ręczny</span>';
    }

    function renderSync(sync) {
        var el = document.getElementById('nreg-vlan-sync');
        if (!sync) { el.innerHTML = '<span class="text-warning">Brak synchronizacji. Uruchom <code>lnms netella-reg:sync</code> albo dodaj cron.</span>'; return; }
        el.innerHTML = 'Dane z LibreNMS z ' + esc(sync.at) + (sync.ok ? '' : ' <span class="text-danger">(błąd: ' + esc(sync.error) + ')</span>');
    }

    function loadMap() {
        return N.api('vlan/map').then(function (j) {
            statuses = j.statuses; manualMask = j.manual || ''; labels = j.labels;
            renderLegend(j.counts); renderGrid(); renderSync(j.sync);
        }).catch(function (e) { grid.innerHTML = alertBox('danger', esc(e.message)); });
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

    function addButton(range) {
        return can.canEdit ? ' <button class="btn btn-success btn-xs" type="button" data-add="' + esc(range) + '"><i class="fa fa-plus"></i> Dodaj jako wpis ręczny</button>' : '';
    }

    function check(range) {
        result.innerHTML = '<p class="nreg-muted">Sprawdzam…</p>';
        N.api('vlan/check', { range: range }).then(function (j) {
            highlight(expand(j.range));
            var free = j.summary.f ? j.summary.f.count : 0;
            if (j.all_free) {
                result.innerHTML = alertBox('success', '<strong>Wolne.</strong> Wszystkie numery z zakresu <span class="nreg-mono">'
                    + esc(j.range) + '</span> (' + j.total + ') są wolne w całej sieci.' + addButton(j.range));
                return;
            }
            var h = '<strong>Zajęte ' + (j.total - free) + ' z ' + j.total + '.</strong> ';
            Object.keys(j.summary).forEach(function (s) {
                h += '<br>' + esc(j.summary[s].label) + ' (' + j.summary[s].count + '): <span class="nreg-mono">' + esc(j.summary[s].list) + '</span>';
            });
            if (j.summary.f) { h += addButton(j.summary.f.list); }
            h = alertBox('warning', h) + '<table class="table table-condensed"><thead><tr><th>VLAN</th><th>Status</th><th>Urządzenia (LibreNMS)</th><th>Wpis ręczny</th></tr></thead><tbody>';
            j.occupied.forEach(function (o) {
                h += '<tr><td><a href="#" data-vlan="' + o.vlan + '">' + o.vlan + '</a></td><td>' + esc(labels[o.status] || o.status)
                    + '</td><td>' + esc(o.devices.join(', ')) + '</td><td>'
                    + (o.manual ? esc(o.manual.description) + (o.manual.owner ? ' <span class="nreg-muted">(' + esc(o.manual.owner) + ')</span>' : '') : '')
                    + '</td></tr>';
            });
            result.innerHTML = h + '</tbody></table>';
        }).catch(function (e) { result.innerHTML = alertBox('danger', esc(e.message)); });
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
                result.innerHTML = alertBox('warning', 'Brak ' + j.count + ' kolejnych wolnych numerów w przedziale ' + j.from + '-' + j.to + '.');
                return;
            }
            highlight(expand(j.range));
            result.innerHTML = alertBox('success', 'Pierwszy wolny ciąg ' + j.count + ' numerów: <strong class="nreg-mono">'
                + esc(j.range) + '</strong>' + addButton(j.range));
            document.getElementById('nreg-vlan-range').value = j.range;
        }).catch(function (e) { result.innerHTML = alertBox('danger', esc(e.message)); });
    }

    function portList(list) {
        if (!list || !list.length) { return ''; }
        if (list.length <= 6) { return esc(list.join(', ')); }
        return '<details class="nreg-ports"><summary>' + list.length + ' portów</summary>' + esc(list.join(', ')) + '</details>';
    }

    // --- wpisy ręczne ---

    function manualButtons(m) {
        var h = '';
        if (can.canEdit) { h += '<button class="btn btn-default btn-xs" type="button" data-edit="' + m.id + '"><i class="fa fa-pencil"></i> Edytuj</button> '; }
        if (can.canDelete) { h += '<button class="btn btn-danger btn-xs" type="button" data-del="' + m.id + '" data-del-vlan="' + m.vlan + '"><i class="fa fa-trash"></i> Usuń</button>'; }
        return h;
    }

    function manualBlock(m, statusLabels) {
        var adopted = m.adopted_at
            ? '<span class="label label-primary">przejęty przez LibreNMS ' + esc(m.adopted_at) + '</span> dane LibreNMS mają pierwszeństwo'
            : '<span class="label label-default">tylko ręcznie</span> LibreNMS nie widzi tego numeru';
        return '<div class="nreg-manual"><strong>Wpis ręczny</strong> ' + adopted + '<dl style="margin-top:6px">'
            + '<dt>Status</dt><dd>' + esc((statusLabels || {})[m.status] || m.status) + '</dd>'
            + (m.name ? '<dt>Nazwa</dt><dd>' + esc(m.name) + '</dd>' : '')
            + '<dt>Opis</dt><dd>' + esc(m.description) + '</dd>'
            + (m.owner ? '<dt>Właściciel</dt><dd>' + esc(m.owner) + '</dd>' : '')
            + (m.devices ? '<dt>Urządzenia</dt><dd>' + esc(m.devices) + '</dd>' : '')
            + '<dt>Dodał</dt><dd>' + esc(m.created_by || '?') + ', ' + esc(m.created_at) + '</dd>'
            + (m.updated_at !== m.created_at ? '<dt>Ostatnia zmiana</dt><dd>' + esc(m.updated_by || '?') + ', ' + esc(m.updated_at) + '</dd>' : '')
            + '</dl><div style="margin-top:6px">' + manualButtons(m) + '</div></div>';
    }

    var manualCache = {};

    function loadManualList() {
        var box = manualList.querySelector('div');
        return N.api('vlan/manual_list').then(function (j) {
            manualCache = {};
            j.rows.forEach(function (m) { manualCache[m.id] = m; });
            manualList.querySelector('summary').textContent = 'Wpisy ręczne (' + j.rows.length + ')';
            if (!j.rows.length) {
                box.innerHTML = '<p class="nreg-muted">Brak wpisów ręcznych.' + (can.canEdit ? ' Dodaj je przyciskiem „Dodaj VLAN”.' : '') + '</p>';
                return;
            }
            var h = '<table class="table table-condensed table-hover"><thead><tr><th>VLAN</th><th>Status</th><th>Stan teraz</th>'
                + '<th>Nazwa</th><th>Opis</th><th>Właściciel</th><th>Urządzenia</th><th>Dodał</th><th></th></tr></thead><tbody>';
            j.rows.forEach(function (m) {
                h += '<tr><td><a href="#" data-vlan="' + m.vlan + '">' + m.vlan + '</a></td><td>' + esc(m.status_label) + '</td>'
                    + '<td>' + esc(m.current_label) + (m.adopted_at ? ' <span class="label label-primary" title="' + esc(m.adopted_at) + '">przejęty</span>' : '') + '</td>'
                    + '<td>' + esc(m.name || '') + '</td><td>' + esc(m.description) + '</td><td>' + esc(m.owner || '') + '</td>'
                    + '<td>' + esc(m.devices || '') + '</td><td style="font-size:11px">' + esc(m.created_by || '?') + '<br>' + esc(m.created_at) + '</td>'
                    + '<td style="white-space:nowrap">' + manualButtons(m) + '</td></tr>';
            });
            box.innerHTML = h + '</tbody></table>';
        }).catch(function (e) { box.innerHTML = alertBox('danger', esc(e.message)); });
    }

    function openForm(entry, range) {
        if (!form) { return; }
        form.reset();
        document.getElementById('nreg-vf-error').textContent = '';
        form.elements.id.value = entry ? entry.id : '';
        var r = document.getElementById('nreg-vf-range');
        r.value = entry ? entry.vlan : (range || document.getElementById('nreg-vlan-range').value || '');
        r.disabled = !!entry;
        document.getElementById('nreg-vf-title').textContent = entry ? 'Edycja wpisu ręcznego VLAN ' + entry.vlan : 'Dodaj VLAN (wpis ręczny)';
        if (entry) {
            ['status', 'name', 'description', 'owner', 'devices'].forEach(function (f) { form.elements[f].value = entry[f] || (f === 'status' ? 'used' : ''); });
        }
        form.style.display = '';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        (entry ? form.elements.description : r).focus();
    }

    function refreshAll() {
        return Promise.all([loadMap(), loadManualList()]).then(function () { if (selected) { showDetail(selected, true); } });
    }

    if (form) {
        document.getElementById('nreg-vf-cancel').addEventListener('click', function () { form.style.display = 'none'; });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var err = document.getElementById('nreg-vf-error'), btn = form.querySelector('button[type="submit"]');
            var data = {};
            ['status', 'name', 'description', 'owner', 'devices', 'comment'].forEach(function (f) { data[f] = form.elements[f].value; });
            var id = form.elements.id.value, path;
            if (id) { data.id = id; path = 'vlan/manual_update'; } else { data.range = form.elements.range.value; path = 'vlan/manual_create'; }
            btn.disabled = true; err.textContent = '';
            N.api(path, data, 'POST').then(function (j) {
                form.style.display = 'none';
                if (id) {
                    result.innerHTML = alertBox('success', 'Zapisano zmiany wpisu VLAN ' + esc(j.entry.vlan) + '.');
                } else {
                    var h = 'Dodano ' + j.count + ' wpis(ów): <span class="nreg-mono">' + esc(j.created) + '</span>.';
                    if (j.already_in_librenms) {
                        h += '<br>Numery <span class="nreg-mono">' + esc(j.already_in_librenms) + '</span> są już w LibreNMS. Wpis zostaje jako adnotacja, a pierwszeństwo mają dane LibreNMS.';
                    }
                    result.innerHTML = alertBox(j.already_in_librenms ? 'warning' : 'success', h);
                    highlight(expand(j.created));
                }
                return refreshAll();
            }).catch(function (e2) { err.textContent = e2.message; }).then(function () { btn.disabled = false; });
        });
        document.getElementById('nreg-vlan-add').addEventListener('click', function () { openForm(null); });
    }

    function deleteEntry(id, vlan) {
        if (!window.confirm('Usunąć wpis ręczny VLAN ' + vlan + '? Zostanie w dzienniku zmian.')) { return; }
        var comment = window.prompt('Komentarz do usunięcia (opcjonalnie, trafi do dziennika):', '');
        if (comment === null) { return; }
        N.api('vlan/manual_delete', { id: id, comment: comment }, 'POST').then(function () {
            result.innerHTML = alertBox('success', 'Usunięto wpis ręczny VLAN ' + esc(vlan) + '.');
            return refreshAll();
        }).catch(function (e) { result.innerHTML = alertBox('danger', esc(e.message)); });
    }

    // Jeden handler na kliknięcia w całej zakładce: numery, dodaj, edytuj, usuń.
    document.getElementById('nreg-tab-vlan').addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('a[data-vlan]'))) {
            e.preventDefault(); showDetail(parseInt(t.getAttribute('data-vlan'), 10)); detail.scrollIntoView({ behavior: 'smooth' });
        } else if ((t = e.target.closest('button[data-add]'))) {
            openForm(null, t.getAttribute('data-add'));
        } else if ((t = e.target.closest('button[data-edit]'))) {
            var id = t.getAttribute('data-edit');
            if (manualCache[id]) { openForm(manualCache[id]); }
        } else if ((t = e.target.closest('button[data-del]'))) {
            deleteEntry(t.getAttribute('data-del'), t.getAttribute('data-del-vlan'));
        }
    });

    function showDetail(v, quiet) {
        if (selected) { var o = cell(selected); if (o) { o.classList.remove('sel'); } }
        selected = v;
        var c = cell(v); if (c) { c.classList.add('sel'); }
        if (!quiet) { detail.innerHTML = '<p class="nreg-muted">Ładowanie VLAN-u ' + v + '…</p>'; }
        N.api('vlan/detail', { vlan: v }).then(function (j) {
            var h = '<h4>VLAN ' + j.vlan + ' &middot; ' + esc(j.status_label)
                + (j.names.length ? ' &middot; <span class="nreg-muted">' + esc(j.names.join(' / ')) + '</span>' : '') + '</h4>';
            if (j.names.length > 1) {
                h += '<div class="alert alert-info" style="padding:6px 10px">Ten numer ma różne nazwy na różnych urządzeniach.</div>';
            }
            if (j.manual) {
                manualCache[j.manual.id] = j.manual;
                h += manualBlock(j.manual, j.status_labels);
            } else if (can.canEdit) {
                h += '<p><button class="btn btn-success btn-xs" type="button" data-add="' + j.vlan + '"><i class="fa fa-plus"></i> Dodaj wpis ręczny</button></p>';
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
                        + esc(r.action_label) + (r.comment ? '<br><small class="nreg-muted">' + esc(r.comment) + '</small>' : '')
                        + '</td><td>' + N.who(r) + '</td><td>' + N.diffHtml(r) + '</td></tr>';
                });
                h += '</tbody></table>';
            }
            detail.innerHTML = h;
        }).catch(function (e) { detail.innerHTML = alertBox('danger', esc(e.message)); });
    }

    grid.addEventListener('click', function (e) {
        var i = e.target.closest('i[data-v]');
        if (i) { showDetail(parseInt(i.getAttribute('data-v'), 10)); }
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
                result.innerHTML = alertBox('info', '<pre style="margin:0;white-space:pre-wrap">' + esc(j.log.join('\n')) + '</pre>');
                return refreshAll();
            }).catch(function (e) {
                result.innerHTML = alertBox('danger', esc(e.message));
            }).then(function () { syncBtn.disabled = false; });
        });
    }

    Promise.all([loadMap(), loadManualList()]).then(function () {
        var q = new URLSearchParams(location.search);
        if (q.get('range')) { document.getElementById('nreg-vlan-range').value = q.get('range'); check(q.get('range')); }
        var v = parseInt(q.get('vlan') || '', 10);
        if (v >= 1 && v <= 4094) { showDetail(v); }
    });
});
</script>
