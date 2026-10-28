{{-- Zakładka modułu adresacji IPv6: drzewo prefiksów z zajętością w blokach /64,
     szczegóły sieci, adresu i zakresu, wpisy ręczne (prefiksy, adresy, zakresy a-b),
     „Wolne /N”, wyszukiwanie (adres, prefiks, tekst) i agregaty z RIPE. Te same zasady widoku co w IPv4 (przełączniki adresacji spoza
     agregatów i wyłączonych hostów). Linki między zakładkami muszą mieć pełny adres
     strony (can.page), bo LibreNMS ustawia <base href>. --}}

<style>
    .nreg6-cols { display: flex; gap: 16px; align-items: flex-start; }
    .nreg6-left { flex: 1 1 58%; min-width: 0; }
    .nreg6-right { flex: 1 1 42%; min-width: 0; position: sticky; top: 60px; max-height: calc(100vh - 80px); overflow: auto; }
    .nreg6-right h4 { margin-top: 4px; word-break: break-all; }
    .nreg6-right dl dt { float: left; width: 110px; font-weight: normal; opacity: .75; }
    .nreg6-right dl dd { margin-left: 120px; }
    #nreg-tab-ipam6 .tn { display: flex; align-items: center; gap: 6px; padding: 1px 4px; font-size: 12px; white-space: nowrap; border-radius: 3px; }
    #nreg-tab-ipam6 .tn:hover { background: rgba(128,128,128,.12); }
    #nreg-tab-ipam6 .tn.sel { background: rgba(51,122,183,.18); }
    #nreg-tab-ipam6 .tn .tg { width: 14px; text-align: center; cursor: pointer; opacity: .8; flex: 0 0 14px; }
    #nreg-tab-ipam6 .tn .cidr { font-family: monospace; font-size: 13px; cursor: pointer; min-width: 230px; }
    #nreg-tab-ipam6 .tn .lbl { overflow: hidden; text-overflow: ellipsis; flex: 1 1 auto; min-width: 40px; opacity: .85; }
    #nreg-tab-ipam6 .tn .cnt { font-family: monospace; font-size: 11px; opacity: .8; min-width: 120px; text-align: right; }
    #nreg-tab-ipam6 .bar { display: inline-block; width: 70px; height: 8px; background: rgba(128,128,128,.25); border-radius: 4px; overflow: hidden; flex: 0 0 70px; }
    #nreg-tab-ipam6 .bar i { display: block; height: 100%; background: #5cb85c; }
    #nreg-tab-ipam6 .bar i.w { background: #f0ad4e; }
    #nreg-tab-ipam6 .bar i.c { background: #d9534f; }
    #nreg-tab-ipam6 .bdg { font-size: 10px; padding: 1px 5px; border-radius: 3px; color: #fff; }
    #nreg-tab-ipam6 .bdg.l { background: #337ab7; } #nreg-tab-ipam6 .bdg.s { background: #999; } #nreg-tab-ipam6 .bdg.m { background: #b57edc; }
    #nreg-tab-ipam6 .bdg.r { background: #5bc0de; } #nreg-tab-ipam6 .bdg.v { background: transparent; color: inherit; border: 1px solid rgba(128,128,128,.5); }
    #nreg-tab-ipam6 .ta { display: flex; gap: 8px; font-size: 12px; padding: 1px 4px; white-space: nowrap; cursor: pointer; }
    #nreg-tab-ipam6 .ta:hover { background: rgba(128,128,128,.12); }
    #nreg-tab-ipam6 .ta .ip { font-family: monospace; font-size: 13px; min-width: 230px; }
    #nreg-tab-ipam6 .ta .who { overflow: hidden; text-overflow: ellipsis; opacity: .85; }
    #nreg-tab-ipam6 .tf { display: flex; gap: 8px; align-items: center; font-size: 12px; padding: 1px 4px; white-space: nowrap; color: #337ab7; font-style: italic; }
    #nreg-tab-ipam6 .tf .ip { font-family: monospace; font-size: 13px; min-width: 230px; }
    #nreg-tab-ipam6 .tf.add { cursor: pointer; }
    #nreg-tab-ipam6 .tf.add:hover { background: rgba(51,122,183,.12); }
    #nreg-tab-ipam6 .tf .plus { font-style: normal; opacity: 0; }
    #nreg-tab-ipam6 .tf.add:hover .plus { opacity: 1; }
    #nreg-tab-ipam6 .chips a { display: inline-block; font-family: monospace; font-size: 12px; margin: 0 4px 4px 0; padding: 1px 6px; border: 1px solid rgba(128,128,128,.45); border-radius: 3px; }
    #nreg-tab-ipam6 .copy { margin-left: 4px; font-size: 13px; opacity: .55; cursor: pointer; }
    #nreg-tab-ipam6 .copy:hover { opacity: 1; text-decoration: none; }
    #nreg-tab-ipam6 .tn.rng { border-left: 3px solid #b57edc; }
    #nreg-tab-ipam6 .bdg.d { background: #d9534f; }
    #nreg6-manual summary { cursor: pointer; font-weight: 600; margin: 6px 0; }
    #nreg6-ripe { max-width: 1200px; }
    #nreg6-rout table { font-size: 12px; margin-bottom: 6px; }
    #nreg6-rout input.nm { height: 24px; padding: 1px 5px; font-size: 12px; width: 180px; }
</style>

@if(! ($tab['data']['gmp'] ?? false))
    <div class="alert alert-warning">Brak rozszerzenia PHP <code>gmp</code>, więc adresacja IPv6 nie działa.
        Zainstaluj je (np. <code>apt install php-gmp</code>) i przeładuj PHP-FPM.</div>
@else
@if(! ($tab['data']['ipamEnabled'] ?? true))
    <div class="alert alert-warning">Moduł „Adresacja IP” jest wyłączony, a to on zbiera adresy IPv6 z LibreNMS - dane LibreNMS się nie odświeżają.</div>
@endif

<div class="nreg-toolbar">
    <label style="margin:0">Przestrzeń:</label>
    <select class="form-control" id="nreg6-space" style="width:auto;height:30px;padding:4px 8px"></select>
    <form id="nreg6-search">
        <input class="form-control nreg-mono" id="nreg6-q" placeholder="adres, prefiks albo tekst" style="width: 240px;">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-search"></i> Szukaj</button>
    </form>
    <form id="nreg6-free">
        <span>Wolne /</span>
        <input class="form-control nreg-num" type="number" min="8" max="128" value="56" id="nreg6-len" style="width:64px !important">
        <span>w</span>
        <input class="form-control nreg-mono" id="nreg6-within" placeholder="np. 2a0e:39c0::/29" style="width: 200px;">
        <button class="btn btn-default btn-sm" type="submit"><i class="fa fa-search"></i> Znajdź</button>
    </form>
    @if($nregBoot['canEdit'])
        <button class="btn btn-success btn-sm" id="nreg6-add" type="button"><i class="fa fa-plus"></i> Dodaj adresację</button>
        <button class="btn btn-default btn-sm" id="nreg6-ripebtn" type="button" title="Pobierz prefiksy IPv6 numeru AS z RIPE i dodaj wybrane jako agregaty">
            <i class="fa fa-globe"></i> Pobierz z RIPE</button>
    @endif
    <label class="nreg-sub" style="margin:0;font-weight:normal;opacity:1" title="Sieci i adresy z LibreNMS spoza adresacji wewnętrznej i prefiksów dodanych ręcznie (np. połączenia z peerami).">
        <input type="checkbox" id="nreg6-outside" style="vertical-align:-2px"> Pokaż adresację spoza agregatów</label>
    @if(($nregBoot['disabledMode'] ?? 'stale') === 'stale')
        <label class="nreg-sub" style="margin:0;font-weight:normal;opacity:1">
            <input type="checkbox" id="nreg6-stale" style="vertical-align:-2px"> Pokaż adresy z wyłączonych hostów</label>
    @endif
    <span class="nreg-sub" id="nreg6-sync"></span>
</div>

@if($nregBoot['canEdit'])
    <form class="nreg-form" id="nreg6-form" style="display:none" autocomplete="off">
        <h4 id="nreg6-ftitle" style="margin-top:0">Dodaj adresację IPv6</h4>
        <input type="hidden" name="id">
        <div class="row">
            <div class="col-sm-4">
                <label class="req" for="nreg6-target">Prefiks, adres albo zakres</label>
                <input class="form-control nreg-mono" id="nreg6-target" name="target" placeholder="2a0e:39c0::/48, 2a0e:39c0::1 albo a-b">
            </div>
            <div class="col-sm-3">
                <label for="nreg6-role">Rola</label>
                <select class="form-control" id="nreg6-role" name="role"></select>
            </div>
            <div class="col-sm-5">
                <label class="req" for="nreg6-name">Nazwa</label>
                <input class="form-control" id="nreg6-name" name="name" maxlength="64">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <label for="nreg6-description">Opis</label>
                <textarea class="form-control" id="nreg6-description" name="description" rows="2" maxlength="1000"></textarea>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-4">
                <label for="nreg6-owner">Właściciel / klient</label>
                <input class="form-control" id="nreg6-owner" name="owner" maxlength="128">
            </div>
            <div class="col-sm-2">
                <label for="nreg6-vlan">VLAN</label>
                <input class="form-control" id="nreg6-vlan" name="vlan" type="number" min="1" max="4094">
            </div>
            <div class="col-sm-2">
                <label for="nreg6-valid">Ważne do</label>
                <input class="form-control" id="nreg6-valid" name="valid_until" type="date">
            </div>
            <div class="col-sm-4">
                <label for="nreg6-devices">Urządzenia / lokalizacja</label>
                <input class="form-control" id="nreg6-devices" name="devices" maxlength="1000">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <label for="nreg6-comment">Komentarz do zmiany (trafi do dziennika)</label>
                <input class="form-control" id="nreg6-comment" name="comment" maxlength="1000">
            </div>
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-save"></i> Zapisz</button>
        <button class="btn btn-default btn-sm" type="button" id="nreg6-cancel">Anuluj</button>
        <span class="text-danger" id="nreg6-error" style="margin-left:10px"></span>
    </form>

    <div class="nreg-form" id="nreg6-ripe" style="display:none">
        <h4 style="margin-top:0">Agregaty IPv6 z RIPE według numeru AS</h4>
        <form class="nreg-toolbar" id="nreg6-rform" style="margin:0 0 6px" autocomplete="off">
            <label for="nreg6-asn" style="margin:0">Numer AS:</label>
            <input class="form-control nreg-mono" id="nreg6-asn" style="width: 140px;">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-download"></i> Pobierz z RIPE</button>
            <button class="btn btn-default btn-sm" type="button" id="nreg6-rclose">Zamknij</button>
        </form>
        <div id="nreg6-rout"></div>
    </div>
@endif

<div id="nreg6-result"></div>
<details id="nreg6-manual"><summary>Prefiksy ręczne</summary><div></div></details>

<div class="nreg6-cols">
    <div class="nreg6-left"><div id="nreg6-tree"><p class="nreg-muted">Ładowanie drzewa…</p></div></div>
    <div class="nreg6-right" id="nreg6-detail"><p class="nreg-muted">Kliknij prefiks albo adres, żeby zobaczyć szczegóły.</p></div>
</div>

<script>
document.addEventListener('nreg:ready', function () {
    'use strict';
    var N = window.NReg, esc = N.esc, can = N.boot;
    var tab = document.getElementById('nreg-tab-ipam6');
    var treeEl = document.getElementById('nreg6-tree'), detail = document.getElementById('nreg6-detail');
    var result = document.getElementById('nreg6-result'), form = document.getElementById('nreg6-form');
    var spaceSel = document.getElementById('nreg6-space');
    var space = 1, manualCache = {}, selectedEl = null, loaded = false, detailSeq = 0, DETAIL_HINT = detail.innerHTML;
    var STATE_BDG = { l: 'LibreNMS', s: 'host wyłączony', m: 'ręczny', r: 'rezerwacja' };
    // Role wpisów ręcznych (z manual_list) i rodzaj wpisu po treści pola.
    var ROLES = {
        prefix: { aggregate: 'agregat', pool: 'pula (np. prefiksy dla klientów)', used: 'sieć używana', reserved: 'rezerwacja' },
        address: { used: 'używany', reserved: 'rezerwacja' },
        range: { dhcp: 'pula DHCPv6', 'static': 'adresy statyczne', reserved: 'rezerwacja' }
    };
    function kindOf(text) {
        text = String(text || '').trim();
        if (/[-–]/.test(text)) { return 'range'; }
        var m = /\/(\d{1,3})$/.exec(text);
        return m && parseInt(m[1], 10) < 128 ? 'prefix' : (text ? 'address' : 'prefix');
    }
    function fillRoles(kind, current) {
        var sel = form.elements.role, roles = ROLES[kind];
        sel.innerHTML = Object.keys(roles).map(function (k) { return '<option value="' + k + '">' + esc(roles[k]) + '</option>'; }).join('');
        if (current && roles[current]) { sel.value = current; }
    }
    var outsideBox = document.getElementById('nreg6-outside'), staleBox = document.getElementById('nreg6-stale');
    var showOutside = localStorage.getItem('nreg6-outside') === '1', showStale = !!staleBox && localStorage.getItem('nreg6-stale') === '1';
    outsideBox.checked = showOutside;
    if (staleBox) { staleBox.checked = showStale; }

    function alertBox(kind, html) { return '<div class="alert alert-' + kind + '">' + html + '</div>'; }
    function api(path, params, method) {
        params = Object.assign({ space: space, stale: showStale ? 1 : 0, outside: showOutside ? 1 : 0 }, params || {});
        return N.api('ipam6/' + path, params, method);
    }
    // Duże liczby IPv6 przychodzą jako tekst: odstępy tysięcy.
    function big(s) { return String(s).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
    function bar(util) {
        var cls = util >= 90 ? 'c' : (util >= 70 ? 'w' : '');
        return '<span class="bar" title="zajętość ' + util + '%"><i class="' + cls + '" style="width:' + Math.min(100, util) + '%"></i></span>';
    }
    function copyBtn(text) { return '<a href="#" class="copy" data-copy="' + esc(text) + '" title="kopiuj ' + esc(text) + '"><i class="fa fa-clipboard"></i></a>'; }
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        return ok ? Promise.resolve() : Promise.reject(new Error('copy'));
    }

    // ---------------------------------------------------------------- drzewo

    function badges(n) {
        var h = '';
        if (n.virtual) { h += '<span class="bdg v"' + (n.outside ? ' title="grupa porządkowa: poza agregatami i adresacją wewnętrzną"' : '') + '>grupa /' + n.len + '</span>'; }
        if (n.sources.indexOf('implicit') >= 0 && !n.manual && !n.state) { h += '<span class="bdg v">wewnętrzna</span>'; }
        if (n.state) { h += '<span class="bdg ' + n.state + '">' + esc(STATE_BDG[n.state]) + '</span>'; }
        if (n.manual) {
            h += '<span class="bdg ' + (n.manual.role === 'reserved' ? 'r' : 'm') + '">' + esc(n.manual.role_label) + '</span>';
            if (n.manual.expiry === 'expired') { h += '<span class="bdg d">termin minął</span>'; }
        }
        return h;
    }

    function rangeRow(r, depth) {
        var m = r.manual;
        return '<div class="tn rng" style="padding-left:' + (depth * 16 + 4) + 'px">'
            + '<span class="tg">·</span><span class="cidr" data-open-range="' + r.id + '">' + esc(r.first + ' – ' + r.last) + '</span>'
            + '<span class="bdg ' + (m.role === 'reserved' ? 'r' : 'm') + '">' + esc(m.role_label) + '</span>'
            + (m.expiry === 'expired' ? '<span class="bdg d">termin minął</span>' : '')
            + '<span class="lbl" title="' + esc(r.label || '') + '">' + esc(r.label || '') + '</span>'
            + '<span class="cnt" title="adresów w zakresie / rozmiar">' + r.addresses + '/' + big(r.size) + ' adr.</span></div>';
    }

    function nodeRow(n, depth) {
        if (n.range) { return rangeRow(n, depth); }
        var s = n.stats;
        return '<div class="tn" data-cidr="' + esc(n.cidr) + '" style="padding-left:' + (depth * 16 + 4) + 'px">'
            + '<span class="tg" data-toggle-node="' + esc(n.cidr) + '">' + (n.len < 128 ? '▸' : '·') + '</span>'
            + '<span class="cidr" data-open-node="' + esc(n.cidr) + '">' + esc(n.cidr) + '</span>' + badges(n)
            + '<span class="lbl" title="' + esc(n.label || '') + '">' + esc(n.label || '') + '</span>'
            + bar(s.util) + '<span class="cnt" title="zajęte / pojemność w ' + esc(s.unit === '/64' ? 'blokach /64' : 'adresach') + ', adresów: ' + s.addresses + '">'
            + big(s.used) + '/' + big(s.capacity) + ' ' + esc(s.unit) + '</span></div>'
            + '<div class="tc" data-children-of="' + esc(n.cidr) + '" style="display:none"></div>';
    }

    function addrRow(a, depth) {
        var who = a.rows.map(function (r) { return r.device + ' ' + r['if'] + (r.alias ? ' (' + r.alias + ')' : ''); });
        if (a.manual) { who.push((a.manual.name || a.manual.description) + ' [ręczny' + (a.manual.expiry === 'expired' ? ', termin minął' : '') + ']'); }
        return '<div class="ta" data-open-ip="' + esc(a.ip) + '" style="padding-left:' + (depth * 16 + 22) + 'px">'
            + '<span class="ip">' + esc(a.ip) + '</span><span class="bdg ' + a.state + '">' + esc(STATE_BDG[a.state]) + '</span>'
            + '<span class="who">' + esc(who.join(' · ')) + '</span></div>';
    }

    function freeRow(f, depth) {
        var addable = can.canEdit;
        return '<div class="tf' + (addable ? ' add' : '') + '"' + (addable ? ' data-add="' + esc(f.cidr) + '" title="kliknij, aby dodać prefiks"' : '')
            + ' style="padding-left:' + (depth * 16 + 22) + 'px"><span class="ip">' + esc(f.cidr) + '</span><span>wolny</span>'
            + (addable ? '<span class="plus"><i class="fa fa-plus"></i> dodaj</span>' : '') + '</div>';
    }

    function loadChildren(cidr, container, depth) {
        return api('tree', { node: cidr || '' }).then(function (j) {
            if (!cidr) {
                var el = document.getElementById('nreg6-sync');
                el.innerHTML = (j.sync ? 'Dane z LibreNMS z ' + esc(j.sync.at) : '')
                    + (j.hidden ? ' · ukrytych adresów spoza agregatów: ' + j.hidden : '')
                    + (j.suspicious ? ' · pominiętych z maską krótszą niż /16: ' + j.suspicious : '');
            }
            // Kolejność po adresie: n to adres szesnastkowo (stała długość).
            var items = j.children.map(function (n) { return { n: n.n, l: n.range ? 129 : n.len, html: nodeRow(n, depth) }; })
                .concat(j.addresses.map(function (a) { return { n: a.n, l: 128, html: addrRow(a, depth) }; }))
                .concat((j.free || []).map(function (f) { return { n: f.n, l: f.len, html: freeRow(f, depth) }; }));
            items.sort(function (x, y) { return x.n < y.n ? -1 : (x.n > y.n ? 1 : x.l - y.l); });
            var h = items.map(function (i) { return i.html; }).join('');
            if (j.addresses_total > j.addresses.length) {
                h += '<div class="nreg-muted" style="padding-left:' + (depth * 16 + 22) + 'px;font-size:11px">… i ' + (j.addresses_total - j.addresses.length) + ' więcej</div>';
            }
            container.innerHTML = h || '<p class="nreg-muted" style="padding-left:' + (depth * 16 + 4) + 'px">Brak adresacji IPv6 w tym widoku.'
                + (!cidr && j.hidden ? ' Zaznacz „Pokaż adresację spoza agregatów” albo dodaj swój prefiks (np. z RIPE).' : '') + '</p>';
            container.querySelectorAll('.tc').forEach(function (c) { c.setAttribute('data-depth', depth + 1); });
            container.setAttribute('data-loaded', '1');
        });
    }

    function toggle(cidr, open) {
        var row = treeEl.querySelector('.tn[data-cidr="' + cidr + '"]');
        var box = treeEl.querySelector('.tc[data-children-of="' + cidr + '"]');
        if (!row || !box) { return Promise.resolve(); }
        var tg = row.querySelector('.tg');
        var show = open === undefined ? box.style.display === 'none' : open;
        box.style.display = show ? '' : 'none';
        tg.textContent = show ? '▾' : '▸';
        if (show && !box.getAttribute('data-loaded')) {
            box.innerHTML = '<p class="nreg-muted" style="font-size:11px;padding-left:20px">Ładowanie…</p>';
            return loadChildren(cidr, box, parseInt(box.getAttribute('data-depth') || '0', 10));
        }
        return Promise.resolve();
    }

    function loadRoot() {
        treeEl.innerHTML = '<p class="nreg-muted">Ładowanie drzewa…</p>';
        return loadChildren('', treeEl, 0).catch(function (e) { treeEl.innerHTML = alertBox('danger', esc(e.message)); });
    }

    function expandPath(path, ip) {
        var p = Promise.resolve();
        path.forEach(function (c) { p = p.then(function () { return toggle(c, true); }); });
        return p.then(function () {
            var el = ip ? treeEl.querySelector('.ta[data-open-ip="' + ip + '"]') : null;
            el = el || (path.length ? treeEl.querySelector('.tn[data-cidr="' + path[path.length - 1] + '"]') : null);
            if (el) { select(el); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });
    }

    function select(el) {
        if (selectedEl) { selectedEl.classList.remove('sel'); }
        selectedEl = el; el.classList.add('sel');
    }

    // ---------------------------------------------------------------- szczegóły

    function clearDetail() {
        detailSeq++;
        if (selectedEl) { selectedEl.classList.remove('sel'); selectedEl = null; }
        detail.innerHTML = DETAIL_HINT;
    }

    function detailLoader(text) {
        var seq = ++detailSeq;
        detail.innerHTML = '<p class="nreg-muted">' + esc(text) + '</p>';
        return function (html) { if (seq === detailSeq) { detail.innerHTML = html; } };
    }

    function lnmsTable(rows) {
        if (!rows.length) { return ''; }
        return '<table class="table table-condensed" style="font-size:12px"><thead><tr><th>Urządzenie</th><th>Interfejs</th><th>Opis portu</th><th>Adres</th><th>Stan</th></tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr><td><a href="' + esc(can.deviceUrl + '/' + r.device_id) + '">' + esc(r.device) + '</a></td><td>' + esc(r['if'])
                    + (r.vlan ? ' <a href="' + esc(can.page) + '?vlan=' + r.vlan + '#vlan">VLAN ' + r.vlan + '</a>' : '') + '</td><td>' + esc(r.alias || '') + '</td>'
                    + '<td class="nreg-mono">' + esc(r.ip + '/' + r.len) + '</td><td><span class="bdg ' + r.state + '">' + esc(STATE_BDG[r.state]) + '</span></td></tr>';
            }).join('') + '</tbody></table>';
    }

    function manualBlock(m) {
        manualCache[m.id] = m;
        var h = '<div class="nreg-manual"><strong>Wpis ręczny</strong> <span class="bdg ' + (m.role === 'reserved' ? 'r' : 'm') + '">' + esc(m.role_label) + '</span>'
            + '<dl style="margin-top:6px">' + (m.kind === 'range' ? '<dt>Zakres</dt><dd class="nreg-mono" style="word-break:break-all">' + esc(m.first + ' – ' + m.last)
                + copyBtn(m.first + '-' + m.last) + ' (' + big(m.size) + ' adr.)</dd>' : '')
            + (m.name ? '<dt>Nazwa</dt><dd>' + esc(m.name) + '</dd>' : '')
            + (m.description ? '<dt>Opis</dt><dd>' + esc(m.description) + '</dd>' : '') + (m.owner ? '<dt>Właściciel</dt><dd>' + esc(m.owner) + '</dd>' : '')
            + (m.vlan ? '<dt>VLAN</dt><dd><a href="' + esc(can.page) + '?vlan=' + m.vlan + '#vlan">' + m.vlan + '</a></dd>' : '')
            + (m.valid_until ? '<dt>Ważne do</dt><dd>' + N.expiryHtml(m) + '</dd>' : '')
            + (m.devices ? '<dt>Urządzenia</dt><dd>' + esc(m.devices) + '</dd>' : '')
            + '<dt>Dodał</dt><dd>' + esc(m.created_by || '?') + ', ' + esc(m.created_at) + '</dd></dl><div style="margin-top:6px">';
        if (can.canEdit) { h += '<button class="btn btn-default btn-xs" type="button" data-edit="' + m.id + '"><i class="fa fa-pencil"></i> Edytuj</button> '; }
        if (can.canDelete) { h += '<button class="btn btn-danger btn-xs" type="button" data-del="' + m.id + '" data-label="' + esc(m.cidr) + '"><i class="fa fa-trash"></i> Usuń</button>'; }
        return h + '</div></div>';
    }

    function openNode(cidr) {
        var put = detailLoader('Ładowanie ' + cidr + '…');
        api('node', { cidr: cidr }).then(function (j) {
            var n = j.node, s = n.stats, unit = s.unit === '/64' ? 'bloków /64' : 'adresów';
            var h = '<h4 class="nreg-mono">' + esc(n.cidr) + copyBtn(n.cidr) + ' ' + badges(n) + '</h4>'
                + '<dl><dt>Zakres</dt><dd class="nreg-mono" style="word-break:break-all">' + esc(n.first) + ' – ' + esc(n.last) + copyBtn(n.first + '-' + n.last) + '</dd>'
                + '<dt>Pojemność</dt><dd>' + big(s.capacity) + ' ' + unit + '</dd>'
                + '<dt>Zajęte</dt><dd>' + big(s.used) + ' (' + s.util + '%) ' + bar(s.util) + '</dd>'
                + '<dt>Adresów</dt><dd>' + s.addresses + '</dd>'
                + (n.label ? '<dt>Opis</dt><dd>' + esc(n.label) + '</dd>' : '') + '</dl>';
            if (s.unit === '/64') { h += '<p class="nreg-muted" style="font-size:12px">W prefiksach szerszych niż /64 zajętość liczy się w blokach /64: adres albo mała sieć zajmuje całą swoją /64. W samej /64 liczą się adresy.</p>'; }
            if (n.outside) { h += '<p class="nreg-muted" style="font-size:12px">Grupa /48 porządkuje drzewo: to, co tu leży, jest poza agregatami i adresacją wewnętrzną.</p>'; }
            else if (n.virtual) { h += '<p class="nreg-muted" style="font-size:12px">To poziom grupowania /' + n.len + ' - nie ma tu prawdziwego prefiksu.</p>'; }
            if (n.lnms.length) { h += '<h5>Na interfejsach (LibreNMS)</h5>' + lnmsTable(n.lnms); }
            if (n.manual) { h += manualBlock(n.manual); }
            else if (can.canEdit) {
                h += '<p><button class="btn btn-success btn-xs" type="button" data-add="' + esc(n.cidr) + '"><i class="fa fa-plus"></i> Opisz ten prefiks (wpis ręczny)</button></p>';
            }
            if (j.free_blocks.length) {
                h += '<h5>Wolne bloki (największe)</h5><div class="chips">'
                    + j.free_blocks.map(function (b) { return '<a href="#" data-add="' + esc(b.cidr) + '" title="dodaj jako wpis ręczny">' + esc(b.cidr) + '</a>'; }).join('') + '</div>';
            }
            h += '<h5>Historia</h5>' + (j.history.length ? N.logTable(j.history, false) : '<p class="nreg-muted">Brak zmian.</p>');
            put(h);
            if (!n.virtual) { document.getElementById('nreg6-within').value = n.cidr; }
        }).catch(function (e) { put(alertBox('danger', esc(e.message))); });
    }

    function openRange(id) {
        var put = detailLoader('Ładowanie zakresu…');
        api('range', { id: id }).then(function (j) {
            var r = j.range;
            var h = '<h4 class="nreg-mono">' + esc(r.first + ' – ' + r.last) + copyBtn(r.first + '-' + r.last) + '</h4>'
                + '<dl><dt>Rozmiar</dt><dd>' + big(r.size) + ' adr.</dd><dt>Adresów w środku</dt><dd>' + j.addresses_total + '</dd>'
                + (j.container ? '<dt>W prefiksie</dt><dd><a href="#" class="nreg-mono" data-open-node="' + esc(j.container) + '">' + esc(j.container) + '</a></dd>' : '')
                + '</dl>' + manualBlock(r.manual);
            if (j.addresses.length) {
                h += '<h5>Adresy w zakresie (' + j.addresses_total + ')</h5><div class="chips">' + j.addresses.map(function (a) {
                    return '<a href="#" data-goto-ip="' + esc(a.ip) + '" title="' + esc(STATE_BDG[a.state] || '') + '">' + esc(a.ip) + '</a>';
                }).join('') + (j.addresses_total > j.addresses.length ? ' …' : '') + '</div>';
            }
            h += '<h5>Historia</h5>' + (j.history.length ? N.logTable(j.history, false) : '<p class="nreg-muted">Brak zmian.</p>');
            put(h);
        }).catch(function (e) { put(alertBox('danger', esc(e.message))); });
    }

    function openIp(ip) {
        var put = detailLoader('Ładowanie ' + ip + '…');
        api('search', { q: ip }).then(function (j) {
            var a = j.address, net = j.path.length ? j.path[j.path.length - 1] : null;
            var h = '<h4 class="nreg-mono">' + esc(j.ip) + copyBtn(j.ip) + (a ? ' <span class="bdg ' + a.state + '">' + esc(STATE_BDG[a.state]) + '</span>' : '') + '</h4>'
                + (net ? '<p style="font-size:12px">W prefiksie: <a href="#" data-open-node="' + esc(net) + '" class="nreg-mono">' + esc(net) + '</a></p>' : '');
            if (!a) {
                h += '<p>Adres jest wolny w tej przestrzeni.</p>';
                if (can.canEdit) { h += '<p><button class="btn btn-success btn-xs" type="button" data-add="' + esc(j.ip) + '"><i class="fa fa-plus"></i> Dodaj jako wpis ręczny</button></p>'; }
            } else {
                if (a.rows.length) { h += '<h5>LibreNMS</h5>' + lnmsTable(a.rows); }
                if (a.manual) { h += manualBlock(a.manual); }
            }
            h += '<h5>Historia</h5>' + (j.history && j.history.length ? N.logTable(j.history, false) : '<p class="nreg-muted">Brak zmian.</p>');
            put(h);
        }).catch(function (e) { put(alertBox('danger', esc(e.message))); });
    }

    // ---------------------------------------------------------------- wpisy ręczne

    function loadManual() {
        var box = document.querySelector('#nreg6-manual > div');
        return api('manual_list').then(function (j) {
            j.rows.forEach(function (m) { manualCache[m.id] = m; });
            ROLES = { prefix: j.prefix_roles, address: j.address_roles, range: j.range_roles };
            document.querySelector('#nreg6-manual summary').textContent = 'Wpisy ręczne (' + j.rows.length + ')';
            if (!j.rows.length) { box.innerHTML = '<p class="nreg-muted">Brak wpisów ręcznych.</p>'; return; }
            box.innerHTML = '<table class="table table-condensed table-hover" style="font-size:12px"><thead><tr><th>Prefiks / adres / zakres</th><th>Rola</th><th>Ważne do</th>'
                + '<th>Nazwa</th><th>Opis</th><th>Właściciel</th><th>Dodał</th></tr></thead><tbody>'
                + j.rows.map(function (m) {
                    var go = m.kind === 'range' ? 'data-goto-range="' + m.id + '"' : (m.kind === 'address' ? 'data-goto-ip="' : 'data-goto-node="') + (m.kind === 'range' ? '' : esc(m.cidr) + '"');
                    return '<tr><td class="nreg-mono" style="word-break:break-all"><a href="#" ' + go + '>' + esc(m.kind === 'range' ? m.first + ' – ' + m.last : m.cidr) + '</a></td><td>' + esc(m.role_label) + '</td>'
                        + '<td>' + N.expiryHtml(m) + '</td><td>' + esc(m.name || '') + '</td><td>' + esc(m.description || '') + '</td><td>' + esc(m.owner || '') + '</td>'
                        + '<td style="font-size:11px">' + esc(m.created_by || '?') + '<br>' + esc(m.created_at) + '</td></tr>';
                }).join('') + '</tbody></table>';
        }).catch(function (e) { box.innerHTML = alertBox('danger', esc(e.message)); });
    }

    function openForm(entry, target) {
        if (!form) { return; }
        if (!entry) { clearDetail(); }
        form.reset();
        document.getElementById('nreg6-error').textContent = '';
        form.elements.id.value = entry ? entry.id : '';
        form.elements.target.value = entry ? entry.cidr : (target || '');
        form.elements.target.disabled = !!entry;
        fillRoles(entry ? entry.kind : kindOf(form.elements.target.value), entry ? entry.role : null);
        document.getElementById('nreg6-ftitle').textContent = entry ? 'Edycja wpisu ' + entry.cidr : 'Dodaj adresację IPv6 (wpis ręczny)';
        if (entry) { ['name', 'description', 'owner', 'vlan', 'devices', 'valid_until'].forEach(function (f) { form.elements[f].value = entry[f] || ''; }); }
        form.style.display = '';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        (entry ? form.elements.name : form.elements.target).focus();
    }

    function refreshAll() { return Promise.all([loadRoot(), loadManual()]); }

    if (form) {
        document.getElementById('nreg6-cancel').addEventListener('click', function () { form.style.display = 'none'; });
        document.getElementById('nreg6-add').addEventListener('click', function () { openForm(null, document.getElementById('nreg6-within').value); });
        form.elements.target.addEventListener('input', function () { fillRoles(kindOf(form.elements.target.value), form.elements.role.value); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var err = document.getElementById('nreg6-error'), btn = form.querySelector('button[type="submit"]'), data = {};
            ['role', 'name', 'description', 'owner', 'vlan', 'devices', 'valid_until', 'comment'].forEach(function (f) { data[f] = form.elements[f].value; });
            var id = form.elements.id.value;
            if (id) { data.id = id; } else { data.target = form.elements.target.value; }
            btn.disabled = true; err.textContent = '';
            api(id ? 'manual_update' : 'manual_create', data, 'POST').then(function (j) {
                form.style.display = 'none';
                result.innerHTML = alertBox('success', id ? 'Zapisano zmiany.' : 'Dodano ' + ({ prefix: 'prefiks', address: 'adres', range: 'zakres' }[j.kind] || 'wpis')
                    + ' <span class="nreg-mono">' + esc(j.label) + '</span>.');
                clearDetail();
                return refreshAll();
            }).catch(function (e2) { err.textContent = e2.message; }).then(function () { btn.disabled = false; });
        });
    }

    function deleteEntry(id, label) {
        if (!window.confirm('Usunąć wpis ręczny ' + label + '? Zostanie w dzienniku zmian.')) { return; }
        var comment = window.prompt('Komentarz do usunięcia (opcjonalnie, trafi do dziennika):', '');
        if (comment === null) { return; }
        api('manual_delete', { id: id, comment: comment }, 'POST').then(function (j) {
            result.innerHTML = alertBox('success', 'Usunięto wpis ręczny <span class="nreg-mono">' + esc(j.deleted) + '</span>.');
            clearDetail();
            return refreshAll();
        }).catch(function (e) { result.innerHTML = alertBox('danger', esc(e.message)); });
    }

    // ---------------------------------------------------------------- RIPE

    var ripePanel = document.getElementById('nreg6-ripe'), ripeOut = document.getElementById('nreg6-rout'), ripeData = null;

    function ripeState(p) {
        if (p.unsupported) { return '<span class="nreg-muted">' + esc(p.unsupported_label || '') + '</span>'; }
        var out = [];
        if (p.exists) { out.push('<span class="label label-default">już jest: ' + esc(p.exists) + '</span>'); }
        if (p.in_aggregate) { out.push('w agregacie <span class="nreg-mono">' + esc(p.in_aggregate) + '</span>'); }
        if (p.parent) { out.push('w szerszym z listy <span class="nreg-mono">' + esc(p.parent) + '</span>'); }
        if (p.lnms) { out.push(p.lnms + ' sieci w LibreNMS'); }
        return out.join('<br>') || '<span class="nreg-muted">brak w rejestrze</span>';
    }

    function ripeRender(j) {
        ripeData = j;
        var h = '<p><strong>AS' + j.asn + '</strong>' + (j.holder ? ' &middot; ' + esc(j.holder) : '') + '</p>';
        if (j.warnings.length) { h += alertBox('warning', j.warnings.map(esc).join('<br>')); }
        var v6 = j.prefixes.filter(function (p) { return p.family === 6; }).length;
        if (!v6) { h += '<p>RIPE nie zna prefiksów IPv6 tego AS.</p>'; }
        h += '<table class="table table-condensed table-hover"><thead><tr><th></th><th>Prefiks</th><th>Nazwa agregatu</th><th>Źródło</th><th>W rejestrze</th></tr></thead><tbody>';
        j.prefixes.forEach(function (p, i) {
            var off = p.unsupported || !!p.exists;
            h += '<tr' + (off ? ' class="nreg-muted"' : '') + '><td><input type="checkbox" data-ripe="' + i + '"' + (p.checked ? ' checked' : '') + (off ? ' disabled' : '') + '></td>'
                + '<td class="nreg-mono">' + esc(p.cidr) + '</td>'
                + '<td>' + (off ? '' : '<input class="form-control nm" data-ripe-name="' + i + '" maxlength="64" value="' + esc(p.name || '') + '">') + '</td>'
                + '<td>' + esc(p.sources_label.join(', ')) + '</td><td>' + ripeState(p) + '</td></tr>';
        });
        h += '</tbody></table><div class="row"><div class="col-sm-6"><label for="nreg6-rdesc">Opis agregatów</label>'
            + '<input class="form-control" id="nreg6-rdesc" maxlength="1000" value="' + esc('Przydział AS' + j.asn + (j.holder ? ' ' + j.holder : '') + ' (z RIPE)') + '"></div></div>'
            + '<button class="btn btn-success btn-sm" type="button" id="nreg6-rimport" style="margin-top:6px"><i class="fa fa-plus"></i> Dodaj zaznaczone jako agregaty</button>';
        ripeOut.innerHTML = h;
    }

    if (ripePanel) {
        document.getElementById('nreg6-ripebtn').addEventListener('click', function () {
            clearDetail();
            if (form) { form.style.display = 'none'; }
            ripePanel.style.display = '';
            var asn = document.getElementById('nreg6-asn');
            if (!asn.value) { asn.value = localStorage.getItem('nreg-ripe-asn') || ''; }
            asn.focus();
        });
        document.getElementById('nreg6-rclose').addEventListener('click', function () { ripePanel.style.display = 'none'; });
        document.getElementById('nreg6-rform').addEventListener('submit', function (e) {
            e.preventDefault();
            var asn = document.getElementById('nreg6-asn').value.trim(), btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            ripeOut.innerHTML = '<p class="nreg-muted">Pobieram dane z RIPE (do kilkunastu sekund)…</p>';
            api('ripe_lookup', { asn: asn }).then(function (j) { localStorage.setItem('nreg-ripe-asn', asn); ripeRender(j); })
                .catch(function (e2) { ripeOut.innerHTML = alertBox('danger', esc(e2.message)); }).then(function () { btn.disabled = false; });
        });
        ripeOut.addEventListener('click', function (e) {
            if (!e.target.closest('#nreg6-rimport')) { return; }
            var items = Array.prototype.slice.call(ripeOut.querySelectorAll('input[data-ripe]:checked')).map(function (c) {
                var i = c.getAttribute('data-ripe'), nm = ripeOut.querySelector('input[data-ripe-name="' + i + '"]');
                return { cidr: ripeData.prefixes[i].cidr, name: nm ? nm.value : '' };
            });
            if (!items.length) { window.alert('Zaznacz co najmniej jeden prefiks.'); return; }
            if (!window.confirm('Dodać ' + items.length + ' agregat(ów) IPv6? Każdy trafi do dziennika zmian.')) { return; }
            api('ripe_import', { asn: ripeData.asn, items: items, description: document.getElementById('nreg6-rdesc').value }, 'POST').then(function (j) {
                var bad = j.results.filter(function (r) { return !r.ok; });
                result.innerHTML = alertBox(bad.length ? 'warning' : 'success', 'Dodano agregatów: ' + j.added + ' z ' + j.results.length + '.'
                    + bad.map(function (r) { return '<br><span class="nreg-mono">' + esc(r.cidr) + '</span>: ' + esc(r.error); }).join(''));
                ripePanel.style.display = 'none'; ripeOut.innerHTML = '';
                return refreshAll();
            }).catch(function (e2) { result.innerHTML = alertBox('danger', esc(e2.message)); });
        });
    }

    // ---------------------------------------------------------------- przestrzenie, przełączniki, kliknięcia

    function loadSpaces() {
        return api('spaces').then(function (j) {
            spaceSel.innerHTML = j.spaces.map(function (s) {
                return '<option value="' + s.id + '">' + esc(s.name) + (s.vrf ? ' (VRF ' + esc(s.vrf) + ')' : '') + '</option>';
            }).join('');
            spaceSel.value = String(space);
        });
    }
    spaceSel.addEventListener('change', function () { space = parseInt(spaceSel.value, 10) || 1; clearDetail(); refreshAll(); });
    outsideBox.addEventListener('change', function () {
        showOutside = outsideBox.checked; localStorage.setItem('nreg6-outside', showOutside ? '1' : '0'); clearDetail(); refreshAll();
    });
    if (staleBox) {
        staleBox.addEventListener('change', function () {
            showStale = staleBox.checked; localStorage.setItem('nreg6-stale', showStale ? '1' : '0'); clearDetail(); refreshAll();
        });
    }

    document.getElementById('nreg6-search').addEventListener('submit', function (e) {
        e.preventDefault();
        var q = document.getElementById('nreg6-q').value.trim();
        if (!q) { return; }
        result.innerHTML = '';
        api('search', { q: q }).then(function (j) {
            if (j.kind === 'address') {
                if (j.hidden) { result.innerHTML = alertBox('info', 'Ten adres jest ukryty (poza agregatami albo na wyłączonym hoście). Zaznacz odpowiedni przełącznik, żeby zobaczyć go w drzewie.'); }
                expandPath(j.path, j.ip); openIp(j.ip); return;
            }
            if (j.kind === 'prefix') {
                if (!j.exact) { result.innerHTML = alertBox('info', 'Nie ma prefiksu ' + esc(j.cidr) + ' w drzewie' + (j.path.length ? '. Pokazuję najbliższy, który go zawiera.' : '.')); }
                expandPath(j.path); if (j.path.length) { openNode(j.path[j.path.length - 1]); } else { clearDetail(); }
                return;
            }
            clearDetail();
            if (!j.hits.length) { result.innerHTML = alertBox('warning', 'Nic nie znaleziono dla „' + esc(q) + '”.'); return; }
            result.innerHTML = alertBox('info', 'Znaleziono ' + j.hits.length + ':<br>' + j.hits.map(function (h) {
                var go = h.type === 'range' ? 'data-goto-range="' + h.id + '"' : (h.type === 'address' ? 'data-goto-ip="' : 'data-goto-node="') + (h.type === 'range' ? '' : esc(h.value) + '"');
                return '<a href="#" class="nreg-mono" ' + go + '>' + esc(h.value) + '</a> <span class="nreg-muted">' + esc(h.label || '')
                    + (h.hidden ? ' (ukryty w drzewie)' : '') + '</span>';
            }).join('<br>'));
        }).catch(function (e2) { result.innerHTML = alertBox('danger', esc(e2.message)); });
    });

    document.getElementById('nreg6-free').addEventListener('submit', function (e) {
        e.preventDefault();
        clearDetail();
        api('free', { within: document.getElementById('nreg6-within').value.trim(), len: document.getElementById('nreg6-len').value, count: 10 })
            .then(function (j) {
                result.innerHTML = j.blocks.length
                    ? alertBox('success', 'Pierwsze wolne /' + j.len + ' w <span class="nreg-mono">' + esc(j.within) + '</span>:<div class="chips" style="margin-top:4px">'
                        + j.blocks.map(function (b) { return can.canEdit ? '<a href="#" data-add="' + esc(b) + '" title="dodaj jako wpis ręczny">' + esc(b) + '</a>' : '<span class="nreg-mono">' + esc(b) + '</span> '; }).join('') + '</div>')
                    : alertBox('warning', 'Brak wolnych /' + j.len + ' w ' + esc(j.within) + '.');
            }).catch(function (e2) { result.innerHTML = alertBox('danger', esc(e2.message)); });
    });

    tab.addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('[data-copy]'))) {
            e.preventDefault();
            var icon = t.querySelector('i'), text = t.getAttribute('data-copy');
            copyText(text).then(function () { icon.className = 'fa fa-check'; }, function () { window.prompt('Skopiuj ręcznie (Ctrl+C):', text); })
                .then(function () { setTimeout(function () { icon.className = 'fa fa-clipboard'; }, 1500); });
        } else if ((t = e.target.closest('[data-toggle-node]'))) { clearDetail(); toggle(t.getAttribute('data-toggle-node')); }
        else if ((t = e.target.closest('[data-open-node]'))) {
            e.preventDefault();
            var row = treeEl.querySelector('.tn[data-cidr="' + t.getAttribute('data-open-node') + '"]');
            if (row) { select(row); }
            openNode(t.getAttribute('data-open-node'));
        } else if ((t = e.target.closest('[data-open-range]'))) {
            e.preventDefault();
            var rrow = t.closest('.tn');
            if (rrow) { select(rrow); }
            openRange(t.getAttribute('data-open-range'));
        } else if ((t = e.target.closest('[data-open-ip]'))) { select(t); openIp(t.getAttribute('data-open-ip')); }
        else if ((t = e.target.closest('[data-goto-ip]'))) {
            e.preventDefault();
            var gip = t.getAttribute('data-goto-ip');
            api('search', { q: gip }).then(function (j) {
                if (j.hidden) { result.innerHTML = alertBox('info', 'Ten adres jest ukryty w drzewie (poza agregatami albo na wyłączonym hoście).'); }
                expandPath(j.path, j.ip); openIp(j.ip);
            });
        } else if ((t = e.target.closest('[data-goto-range]'))) {
            e.preventDefault();
            var rid = t.getAttribute('data-goto-range');
            api('range', { id: rid }).then(function (j) { expandPath(j.path); openRange(rid); });
        }
        else if ((t = e.target.closest('[data-goto-node]'))) {
            e.preventDefault();
            var c = t.getAttribute('data-goto-node');
            api('search', { q: c }).then(function (j) { expandPath(j.path); openNode(j.path.length ? j.path[j.path.length - 1] : c); });
        } else if ((t = e.target.closest('[data-add]'))) {
            e.preventDefault();
            openForm(null, t.getAttribute('data-add'));
            if (form && t.classList.contains('tf') && form.elements.role.querySelector('option[value="used"]')) { form.elements.role.value = 'used'; }
        } else if ((t = e.target.closest('button[data-edit]'))) {
            var m = manualCache[t.getAttribute('data-edit')];
            if (m) { openForm(m); }
        } else if ((t = e.target.closest('button[data-del]'))) {
            deleteEntry(t.getAttribute('data-del'), t.getAttribute('data-label'));
        }
    });

    function start() {
        if (loaded) { return; }
        loaded = true;
        loadSpaces().then(refreshAll).catch(function (e) { treeEl.innerHTML = alertBox('danger', esc(e.message)); });
    }
    document.addEventListener('nreg:tab', function (e) { if (e.detail === 'ipam6') { start(); } });
    if (tab.classList.contains('active')) { start(); }
});
</script>
@endif
