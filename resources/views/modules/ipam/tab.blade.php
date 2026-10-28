{{-- Zakładka modułu adresacji: drzewo IPv4 wg masek, szczegóły sieci i adresu,
     wyszukiwanie, wolne bloki, wpisy ręczne, uwagi.

     Adres ?ip=10.0.0.1#ipam albo ?prefix=10.0.0.0/24#ipam rozwija drzewo do
     wskazanego miejsca. Linki między zakładkami muszą mieć pełny adres strony (can.page),
     bo LibreNMS ustawia <base href> na swój adres główny.

     Panel szczegółów po prawej pokazuje ostatnio otwarty element. Czyści się przy
     innych czynnościach (rozwijanie drzewa, dodawanie, wyszukiwanie, import z RIPE),
     a spóźniona odpowiedź wcześniejszego kliknięcia nie nadpisuje nowszej. --}}

<style>
    .nreg-ip-cols { display: flex; gap: 16px; align-items: flex-start; }
    .nreg-ip-left { flex: 1 1 58%; min-width: 0; }
    .nreg-ip-right { flex: 1 1 42%; min-width: 0; position: sticky; top: 60px; max-height: calc(100vh - 80px); overflow: auto; }
    .nreg-tn { display: flex; align-items: center; gap: 6px; padding: 1px 4px; font-size: 12px; white-space: nowrap; border-radius: 3px; }
    .nreg-tn:hover { background: rgba(128,128,128,.12); }
    .nreg-tn.sel { background: rgba(51,122,183,.18); }
    .nreg-tn .tg { width: 14px; text-align: center; cursor: pointer; opacity: .8; flex: 0 0 14px; }
    .nreg-tn .cidr { font-family: monospace; font-size: 13px; cursor: pointer; min-width: 145px; }
    .nreg-tn .lbl { overflow: hidden; text-overflow: ellipsis; flex: 1 1 auto; min-width: 40px; opacity: .85; }
    .nreg-tn .cnt { font-family: monospace; font-size: 11px; opacity: .8; min-width: 92px; text-align: right; }
    .nreg-bar { display: inline-block; width: 70px; height: 8px; background: rgba(128,128,128,.25); border-radius: 4px; overflow: hidden; flex: 0 0 70px; }
    .nreg-bar i { display: block; height: 100%; background: #5cb85c; }
    .nreg-bar i.w { background: #f0ad4e; }
    .nreg-bar i.c { background: #d9534f; }
    .nreg-bdg { font-size: 10px; padding: 1px 5px; border-radius: 3px; color: #fff; }
    .nreg-bdg.l { background: #337ab7; } .nreg-bdg.s { background: #999; } .nreg-bdg.m { background: #b57edc; }
    .nreg-bdg.r { background: #5bc0de; } .nreg-bdg.g { background: #f0ad4e; } .nreg-bdg.v { background: transparent; color: inherit; border: 1px solid rgba(128,128,128,.5); }
    .nreg-bdg.d { background: #d9534f; }
    .nreg-ta { display: flex; gap: 8px; font-size: 12px; padding: 1px 4px; white-space: nowrap; cursor: pointer; }
    .nreg-ta:hover { background: rgba(128,128,128,.12); }
    .nreg-ta .ip { font-family: monospace; font-size: 13px; min-width: 120px; }
    /* Wolne miejsce w drzewie: niebieskie, kursywą; kliknięcie = dodaj wpis ręczny. */
    .nreg-tf { display: flex; gap: 8px; align-items: center; font-size: 12px; padding: 1px 4px; white-space: nowrap; color: #337ab7; font-style: italic; }
    .nreg-tf .ip { font-family: monospace; font-size: 13px; min-width: 145px; }
    .nreg-tf.add { cursor: pointer; }
    .nreg-tf.add:hover { background: rgba(51,122,183,.12); }
    .nreg-tf .plus { font-style: normal; opacity: 0; }
    .nreg-tf.add:hover .plus { opacity: 1; }
    /* Zakres (pula a-b): wiersz drzewa z lewą kreską. */
    .nreg-tn.rng { border-left: 3px solid #b57edc; }
    .nreg-tn.rng .cidr { min-width: 235px; }
    .nreg-ta .who { overflow: hidden; text-overflow: ellipsis; opacity: .85; }
    .nreg-chips a { display: inline-block; font-family: monospace; font-size: 12px; margin: 0 4px 4px 0; padding: 1px 6px; border: 1px solid rgba(128,128,128,.45); border-radius: 3px; }
    .nreg-ip-right h4 { margin-top: 4px; }
    .nreg-copy { margin-left: 4px; font-size: 13px; opacity: .55; cursor: pointer; }
    .nreg-copy:hover { opacity: 1; text-decoration: none; }
    .nreg-ip-right dl dt { float: left; width: 110px; font-weight: normal; opacity: .75; }
    .nreg-ip-right dl dd { margin-left: 120px; }
    #nreg-ip-issues summary, #nreg-ip-manual summary { cursor: pointer; font-weight: 600; margin: 6px 0; }
    #nreg-ip-ripe { max-width: 1200px; }
    #nreg-ir-out table { font-size: 12px; margin-bottom: 6px; }
    #nreg-ir-out td { vertical-align: middle; }
    #nreg-ir-out input.nm { height: 24px; padding: 1px 5px; font-size: 12px; width: 180px; }
</style>

<div class="nreg-toolbar">
    <label style="margin:0">Przestrzeń:</label>
    <select class="form-control" id="nreg-ip-space" style="width:auto;height:30px;padding:4px 8px"></select>
    @if($nregBoot['canAdmin'])
        <button class="btn btn-default btn-xs" type="button" id="nreg-ip-newspace" title="Nowa przestrzeń adresowa (VRF, klient)"><i class="fa fa-plus"></i></button>
    @endif
    <form id="nreg-ip-search">
        <input class="form-control" id="nreg-ip-q" placeholder="adres, prefiks albo tekst" style="width: 220px;">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-search"></i> Szukaj</button>
    </form>
    <form id="nreg-ip-free">
        <span>Wolne</span>
        <input class="form-control nreg-num" type="number" min="8" max="32" value="29" id="nreg-ip-len" style="width:64px !important">
        <span>w</span>
        <input class="form-control nreg-mono" id="nreg-ip-within" placeholder="np. 45.10.136.0/22" style="width: 160px;">
        <button class="btn btn-default btn-sm" type="submit"><i class="fa fa-search"></i> Znajdź</button>
    </form>
    @if($nregBoot['canEdit'])
        <button class="btn btn-success btn-sm" id="nreg-ip-add" type="button"><i class="fa fa-plus"></i> Dodaj adresację</button>
        <button class="btn btn-default btn-sm" id="nreg-ip-ripebtn" type="button" title="Pobierz prefiksy numeru AS z RIPE i dodaj wybrane jako agregaty">
            <i class="fa fa-globe"></i> Pobierz z RIPE</button>
    @endif
    @if(($nregBoot['disabledMode'] ?? 'stale') === 'stale')
        <label class="nreg-sub" style="margin:0;font-weight:normal;opacity:1" title="Adresy z hostów wyłączonych w LibreNMS. Ukryte są w drzewie i szukaniu wolnych jak wolne; dodanie ich ręcznie i tak blokuje LibreNMS.">
            <input type="checkbox" id="nreg-ip-stale" style="vertical-align:-2px"> Pokaż adresy z wyłączonych hostów</label>
    @endif
    <span class="nreg-sub" id="nreg-ip-sync"></span>
</div>

@if($nregBoot['canEdit'])
    <form class="nreg-form" id="nreg-ip-form" style="display:none" autocomplete="off">
        <h4 id="nreg-if-title" style="margin-top:0">Dodaj adresację</h4>
        <input type="hidden" name="id">
        <input type="hidden" name="group" value="0">
        <div class="row">
            <div class="col-sm-4">
                <label class="req" for="nreg-if-target">Adres, zakres albo prefiks</label>
                <input class="form-control nreg-mono" id="nreg-if-target" name="target" placeholder="10.0.0.0/24, 10.0.0.5 albo 10.0.0.10-20">
                <select class="form-control input-sm" id="nreg-if-as" name="as" style="display:none;margin-top:4px">
                    <option value="addresses">osobne adresy (grupa)</option>
                    <option value="range">jeden zakres (pula DHCP, PPPoE, NAT…)</option>
                </select>
            </div>
            <div class="col-sm-3">
                <label for="nreg-if-role">Rola</label>
                <select class="form-control" id="nreg-if-role" name="role"></select>
            </div>
            <div class="col-sm-5">
                <label class="req" for="nreg-if-name">Nazwa</label>
                <input class="form-control" id="nreg-if-name" name="name" maxlength="64">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <label for="nreg-if-description">Opis</label>
                <textarea class="form-control" id="nreg-if-description" name="description" rows="2" maxlength="1000"></textarea>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-4">
                <label for="nreg-if-owner">Właściciel / klient</label>
                <input class="form-control" id="nreg-if-owner" name="owner" maxlength="128">
            </div>
            <div class="col-sm-2">
                <label for="nreg-if-vlan">VLAN</label>
                <input class="form-control" id="nreg-if-vlan" name="vlan" type="number" min="1" max="4094">
            </div>
            <div class="col-sm-2">
                <label for="nreg-if-valid">Ważne do</label>
                <input class="form-control" id="nreg-if-valid" name="valid_until" type="date" title="opcjonalnie; po terminie wpis zostaje, ale jest oznaczony jako przeterminowany">
            </div>
            <div class="col-sm-4">
                <label for="nreg-if-devices">Urządzenia / lokalizacja</label>
                <input class="form-control" id="nreg-if-devices" name="devices" maxlength="1000" list="nreg-if-devlist"
                       placeholder="wybierz host z LibreNMS albo wpisz">
                <datalist id="nreg-if-devlist"></datalist>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <label for="nreg-if-comment">Komentarz do zmiany (trafi do dziennika)</label>
                <input class="form-control" id="nreg-if-comment" name="comment" maxlength="1000">
            </div>
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-save"></i> Zapisz</button>
        <button class="btn btn-default btn-sm" type="button" id="nreg-if-cancel">Anuluj</button>
        <span class="text-danger" id="nreg-if-error" style="margin-left:10px"></span>
    </form>
@endif

@if($nregBoot['canEdit'])
    <div class="nreg-form" id="nreg-ip-ripe" style="display:none">
        <h4 style="margin-top:0">Agregaty z RIPE według numeru AS</h4>
        <form class="nreg-toolbar" id="nreg-ir-form" style="margin:0 0 6px" autocomplete="off">
            <label for="nreg-ir-asn" style="margin:0">Numer AS:</label>
            <input class="form-control nreg-mono" id="nreg-ir-asn" style="width: 140px;">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fa fa-download"></i> Pobierz z RIPE</button>
            <button class="btn btn-default btn-sm" type="button" id="nreg-ir-close">Zamknij</button>
            <span class="nreg-sub">Źródła: prefiksy ogłaszane w BGP (RIPEstat), obiekty route i przydziały organizacji AS (baza RIPE).</span>
        </form>
        <div id="nreg-ir-out"></div>
    </div>
@endif

<div id="nreg-ip-result"></div>
<details id="nreg-ip-issues"><summary>Uwagi</summary><div></div></details>
<details id="nreg-ip-manual"><summary>Wpisy ręczne</summary><div></div></details>

<div class="nreg-ip-cols">
    <div class="nreg-ip-left"><div id="nreg-ip-tree"><p class="nreg-muted">Ładowanie drzewa…</p></div></div>
    <div class="nreg-ip-right" id="nreg-ip-detail"><p class="nreg-muted">Kliknij sieć albo adres, żeby zobaczyć szczegóły.</p></div>
</div>

<script>
document.addEventListener('nreg:ready', function () {
    'use strict';
    var N = window.NReg, esc = N.esc, can = N.boot;
    var tab = document.getElementById('nreg-tab-ipam');
    var treeEl = document.getElementById('nreg-ip-tree'), detail = document.getElementById('nreg-ip-detail');
    var result = document.getElementById('nreg-ip-result'), form = document.getElementById('nreg-ip-form');
    var spaceSel = document.getElementById('nreg-ip-space');
    var space = 1, labels = {}, prefixRoles = {}, addressRoles = {}, rangeRoles = {}, manualCache = {}, selectedEl = null, loaded = false;
    // Numer ostatniego żądania szczegółów: odpowiedź starszego żądania jest pomijana.
    var detailSeq = 0, DETAIL_HINT = detail.innerHTML;
    var STATE_BDG = { l: 'LibreNMS', s: 'host wyłączony', m: 'ręczny', r: 'rezerwacja', g: 'zniknął' };
    // Adresy wyłączonych hostów domyślnie ukryte (traktowane jak wolne); wybór zapamiętany w przeglądarce.
    var staleBox = document.getElementById('nreg-ip-stale'), showStale = !!staleBox && localStorage.getItem('nreg-ip-stale') === '1';
    if (staleBox) { staleBox.checked = showStale; }

    Object.assign(N.fieldLabels, { ips: 'adresy', prefix: 'prefiks', range: 'zakres', role: 'rola', ip: 'adres', len: 'maska', 'if': 'interfejs',
        alias: 'opis portu', network: 'sieć', ctx: 'VRF', vlan: 'VLAN' });

    function alertBox(kind, html) { return '<div class="alert alert-' + kind + '">' + html + '</div>'; }
    // Ikona kopiowania adresacji do schowka (obsługa w handlerze kliknięć zakładki).
    function copyBtn(text) { return '<a href="#" class="nreg-copy" data-copy="' + esc(text) + '" title="kopiuj ' + esc(text) + '"><i class="fa fa-clipboard"></i></a>'; }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text); }
        // http bez TLS: schowek tylko przez zaznaczenie pola.
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        return ok ? Promise.resolve() : Promise.reject(new Error('copy'));
    }
    function api(path, params, method) { params = Object.assign({ space: space, stale: showStale ? 1 : 0 }, params || {}); return N.api('ipam/' + path, params, method); }

    // ---------------------------------------------------------------- drzewo

    function bar(util) {
        var cls = util >= 90 ? 'c' : (util >= 70 ? 'w' : '');
        return '<span class="nreg-bar" title="zajętość ' + util + '%"><i class="' + cls + '" style="width:' + Math.min(100, util) + '%"></i></span>';
    }

    function nodeBadges(n) {
        var h = '';
        if (n.virtual) { h += '<span class="nreg-bdg v">grupa /' + n.len + '</span>'; }
        if (n.sources.indexOf('implicit') >= 0 && !n.manual && !n.state) { h += '<span class="nreg-bdg v" title="ustawienie „Adresacja wewnętrzna”">wewnętrzna</span>'; }
        if (n.state) { h += '<span class="nreg-bdg ' + n.state + '">' + esc(STATE_BDG[n.state]) + '</span>'; }
        if (n.manual) {
            h += '<span class="nreg-bdg ' + (n.manual.role === 'reserved' ? 'r' : 'm') + '">' + esc(n.manual.role_label) + '</span>';
            if (n.manual.expiry === 'expired') { h += '<span class="nreg-bdg d" title="termin ważności ' + esc(n.manual.valid_until) + '">termin minął</span>'; }
        }
        n.vlans.forEach(function (v) { h += '<a class="nreg-bdg v" href="' + esc(can.page) + '?vlan=' + v + '#vlan" title="VLAN ' + v + '">VLAN ' + v + '</a>'; });
        return h;
    }

    function nodeRow(n, depth) {
        // Każdy prefiks da się rozwinąć: nawet pusty pokazuje swoje wolne miejsce. Zakres (n.range) też.
        var expandable = n.range || n.len < 32;
        var s = n.stats;
        return '<div class="nreg-tn' + (n.range ? ' rng' : '') + '" data-cidr="' + esc(n.cidr) + '" style="padding-left:' + (depth * 16 + 4) + 'px">'
            + '<span class="tg" data-toggle-node="' + esc(n.cidr) + '">' + (expandable ? '▸' : '·') + '</span>'
            + '<span class="cidr" data-open-node="' + esc(n.cidr) + '">' + esc(n.range ? n.first + ' – ' + n.last : n.cidr) + '</span>' + nodeBadges(n)
            + '<span class="lbl" title="' + esc(n.label || '') + '">' + esc(n.label || '') + '</span>'
            + bar(s.util) + '<span class="cnt" title="zajęte / pojemność, adresów: ' + s.addresses + '">' + s.used + '/' + s.capacity + '</span></div>'
            + '<div class="nreg-tc" data-children-of="' + esc(n.cidr) + '" style="display:none"></div>';
    }

    function addrRow(a, depth) {
        var who = a.rows.map(function (r) { return r.device + ' ' + r['if'] + (r.alias ? ' (' + r.alias + ')' : ''); });
        if (a.manual) { who.push((a.manual.name || a.manual.description) + ' [ręczny' + (a.manual.expiry === 'expired' ? ', termin minął' : '') + ']'); }
        return '<div class="nreg-ta" data-open-ip="' + esc(a.ip) + '" style="padding-left:' + (depth * 16 + 22) + 'px">'
            + '<span class="ip">' + esc(a.ip) + '</span><span class="nreg-bdg ' + a.state + '">' + esc(STATE_BDG[a.state] || a.state) + '</span>'
            + (a.dup ? '<span class="nreg-bdg d" title="ten sam adres na kilku urządzeniach">duplikat</span>' : '')
            + '<span class="who">' + esc(who.join(' · ')) + '</span></div>';
    }

    // Wolny prefiks (w agregacie i grupie) albo wolne adresy hostów (w sieci).
    function freeRow(f, depth) {
        var target, text, info;
        if (f.type === 'prefix') {
            target = f.cidr; text = f.cidr; info = 'wolny · ' + f.size + ' adr.';
        } else {
            target = f.count === 1 ? f.first : f.first + '-' + f.last;
            text = f.count === 1 ? f.first : f.first + ' – ' + f.last;
            info = 'wolne · ' + f.count + ' adr.';
        }
        // Do formularza tylko to, co da się dodać jednym wpisem (zakres adresów do 1024).
        var addable = can.canEdit && (f.type === 'prefix' || f.count <= 1024);
        return '<div class="nreg-tf' + (addable ? ' add' : '') + '"' + (addable ? ' data-add="' + esc(target) + '" title="kliknij, aby dodać wpis ręczny"' : '')
            + ' style="padding-left:' + (depth * 16 + 22) + 'px"><span class="ip">' + esc(text) + '</span><span>' + esc(info) + '</span>'
            + (addable ? '<span class="plus"><i class="fa fa-plus"></i> dodaj</span>' : '') + '</div>';
    }

    function depthOf(el) { return parseInt(el.getAttribute('data-depth') || '0', 10); }

    function loadChildren(cidr, container, depth) {
        return api('tree', { node: cidr || '' }).then(function (j) {
            labels = j.labels;
            if (!cidr) { renderSync(j.sync, j.v6_count); }
            // Dzieci, adresy i wolne miejsce po kolei według adresu początkowego.
            var items = j.children.map(function (n) { return { n: n.n, html: nodeRow(n, depth) }; })
                .concat(j.addresses.map(function (a) { return { n: a.n, html: addrRow(a, depth) }; }))
                .concat((j.free || []).map(function (f) { return { n: f.n, html: freeRow(f, depth) }; }));
            items.sort(function (x, y) { return x.n - y.n; });
            var h = items.map(function (i) { return i.html; }).join('');
            if (j.addresses_total > j.addresses.length) {
                h += '<div class="nreg-muted" style="padding-left:' + (depth * 16 + 22) + 'px;font-size:11px">… i ' + (j.addresses_total - j.addresses.length) + ' więcej</div>';
            }
            container.innerHTML = h || '<p class="nreg-muted" style="padding-left:' + (depth * 16 + 4) + 'px">Brak adresacji w tej przestrzeni.</p>';
            container.querySelectorAll('.nreg-tc').forEach(function (c) { c.setAttribute('data-depth', depth + 1); });
            container.setAttribute('data-loaded', '1');
        });
    }

    function toggle(cidr, open) {
        var row = treeEl.querySelector('.nreg-tn[data-cidr="' + cidr + '"]');
        var box = treeEl.querySelector('.nreg-tc[data-children-of="' + cidr + '"]');
        if (!row || !box) { return Promise.resolve(); }
        var tg = row.querySelector('.tg');
        if (tg.textContent === '·') { return Promise.resolve(); }
        var show = open === undefined ? box.style.display === 'none' : open;
        box.style.display = show ? '' : 'none';
        tg.textContent = show ? '▾' : '▸';
        if (show && !box.getAttribute('data-loaded')) {
            box.innerHTML = '<p class="nreg-muted" style="font-size:11px;padding-left:20px">Ładowanie…</p>';
            return loadChildren(cidr, box, depthOf(box));
        }
        return Promise.resolve();
    }

    function loadRoot() {
        treeEl.innerHTML = '<p class="nreg-muted">Ładowanie drzewa…</p>';
        treeEl.setAttribute('data-depth', '0');
        return loadChildren('', treeEl, 0).catch(function (e) { treeEl.innerHTML = alertBox('danger', esc(e.message)); });
    }

    // Rozwija drzewo wzdłuż ścieżki i zaznacza ostatni węzeł.
    function expandPath(path, ip) {
        var p = Promise.resolve();
        path.forEach(function (cidr) { p = p.then(function () { return toggle(cidr, true); }); });
        return p.then(function () {
            var el = ip ? treeEl.querySelector('.nreg-ta[data-open-ip="' + ip + '"]') : null;
            el = el || (path.length ? treeEl.querySelector('.nreg-tn[data-cidr="' + path[path.length - 1] + '"]') : null);
            if (el) { select(el); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });
    }

    function select(el) {
        if (selectedEl) { selectedEl.classList.remove('sel'); }
        selectedEl = el; el.classList.add('sel');
    }

    // Czyści panel szczegółów i zaznaczenie w drzewie (np. przy dodawaniu albo rozwijaniu drzewa).
    function clearDetail() {
        detailSeq++;
        if (selectedEl) { selectedEl.classList.remove('sel'); selectedEl = null; }
        detail.innerHTML = DETAIL_HINT;
    }

    // Start ładowania szczegółów; zwraca funkcję, która wstawia HTML tylko wtedy,
    // gdy w międzyczasie nie otwarto niczego innego.
    function detailLoader(text) {
        var seq = ++detailSeq;
        detail.innerHTML = '<p class="nreg-muted">' + esc(text) + '</p>';
        return function (html) { if (seq === detailSeq) { detail.innerHTML = html; } return seq === detailSeq; };
    }

    function renderSync(sync, v6) {
        var el = document.getElementById('nreg-ip-sync');
        el.innerHTML = sync ? 'Dane z LibreNMS z ' + esc(sync.at) + (sync.ok ? '' : ' <span class="text-danger">(błąd: ' + esc(sync.error) + ')</span>')
            + (v6 ? ' &middot; IPv6: ' + v6 + ' adresów (bez drzewa)' : '')
            : '<span class="text-warning">Brak synchronizacji modułu adresacji.</span>';
    }

    // ---------------------------------------------------------------- szczegóły

    function lnmsTable(rows, withIp) {
        if (!rows.length) { return ''; }
        return '<table class="table table-condensed" style="font-size:12px"><thead><tr><th>Urządzenie</th><th>Interfejs</th><th>Opis portu</th>'
            + (withIp ? '<th>Adres</th>' : '<th>Maska</th>') + '<th>Stan</th></tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr><td><a href="' + esc(can.deviceUrl + '/' + r.device_id) + '">' + esc(r.device) + '</a></td><td>' + esc(r['if'])
                    + (r.vlan ? ' <a href="' + esc(can.page) + '?vlan=' + r.vlan + '#vlan">VLAN ' + r.vlan + '</a>' : '') + '</td><td>' + esc(r.alias || '') + '</td>'
                    + '<td class="nreg-mono">' + esc(withIp ? r.ip : '/' + r.len) + '</td><td><span class="nreg-bdg ' + r.state + '">' + esc(STATE_BDG[r.state]) + '</span>'
                    + (r.gone_at ? ' <small>' + esc(r.gone_at) + '</small>' : '') + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    function manualBlock(m) {
        manualCache[m.id] = m;
        var h = '<div class="nreg-manual"><strong>Wpis ręczny</strong> <span class="nreg-bdg ' + (m.role === 'reserved' ? 'r' : 'm') + '">' + esc(m.role_label) + '</span> '
            + (m.adopted_at ? '<span class="label label-primary">przejęty przez LibreNMS ' + esc(m.adopted_at) + '</span>' : '')
            + '<dl style="margin-top:6px">' + (m.kind === 'range' ? '<dt>Zakres</dt><dd class="nreg-mono">' + esc(m.first + ' – ' + m.last) + copyBtn(m.first + '-' + m.last) + ' (' + m.size + ' adr.)</dd>' : '')
            + (m.name ? '<dt>Nazwa</dt><dd>' + esc(m.name) + '</dd>' : '')
            + (m.description ? '<dt>Opis</dt><dd>' + esc(m.description) + '</dd>' : '') + (m.owner ? '<dt>Właściciel</dt><dd>' + esc(m.owner) + '</dd>' : '')
            + (m.vlan ? '<dt>VLAN</dt><dd><a href="' + esc(can.page) + '?vlan=' + m.vlan + '#vlan">' + m.vlan + '</a></dd>' : '')
            + (m.valid_until ? '<dt>Ważne do</dt><dd>' + N.expiryHtml(m) + '</dd>' : '')
            + (m.devices ? '<dt>Urządzenia</dt><dd>' + esc(m.devices) + '</dd>' : '')
            + '<dt>Dodał</dt><dd>' + esc(m.created_by || '?') + ', ' + esc(m.created_at) + '</dd></dl><div style="margin-top:6px">';
        if (can.canEdit) { h += '<button class="btn btn-default btn-xs" type="button" data-edit="' + m.id + '" data-group="0"><i class="fa fa-pencil"></i> Edytuj</button> '; }
        if (can.canDelete) { h += '<button class="btn btn-danger btn-xs" type="button" data-del="' + m.id + '" data-group="0" data-label="' + esc(m.ip || m.cidr) + '"><i class="fa fa-trash"></i> Usuń</button>'; }
        return h + '</div></div>';
    }

    function openRange(id) {
        var put = detailLoader('Ładowanie zakresu…');
        api('range', { id: id }).then(function (j) {
            var r = j.range, s = r.stats;
            var h = '<h4 class="nreg-mono">' + esc(r.first + ' – ' + r.last) + copyBtn(r.first + '-' + r.last) + ' ' + nodeBadges(r) + '</h4>'
                + '<dl><dt>Rozmiar</dt><dd>' + s.size + ' adr.</dd>'
                + '<dt>Zajęte</dt><dd>' + s.used + ' z ' + s.capacity + ' (' + s.util + '%) ' + bar(s.util)
                + ' <small class="nreg-muted">adresy z LibreNMS i wpisów ręcznych</small></dd>'
                + (j.container ? '<dt>W prefiksie</dt><dd><a href="#" class="nreg-mono" data-open-node="' + esc(j.container) + '">' + esc(j.container) + '</a></dd>' : '')
                + '</dl>';
            if (j.problem) { h += alertBox('warning', esc(j.problem)); }
            h += manualBlock(r.manual);
            if (j.addresses.length) {
                h += '<h5>Adresy w zakresie (' + j.addresses_total + ')</h5><div class="nreg-chips">' + j.addresses.map(function (a) {
                    return '<a href="#" data-goto-ip="' + esc(a.ip) + '" title="' + esc(STATE_BDG[a.state] || '') + '">' + esc(a.ip) + '</a>';
                }).join('') + (j.addresses_total > j.addresses.length ? ' …' : '') + '</div>';
            }
            h += '<h5>Historia</h5>' + (j.history.length ? N.logTable(j.history, false) : '<p class="nreg-muted">Brak zmian.</p>');
            put(h);
        }).catch(function (e) { put(alertBox('danger', esc(e.message))); });
    }

    function openNode(cidr) {
        if (cidr.indexOf('range:') === 0) { openRange(cidr.substring(6)); return; }
        var put = detailLoader('Ładowanie ' + cidr + '…');
        api('node', { cidr: cidr }).then(function (j) {
            var n = j.node, s = n.stats;
            var h = '<h4 class="nreg-mono">' + esc(n.cidr) + copyBtn(n.cidr) + ' ' + nodeBadges(n) + '</h4>'
                + '<dl><dt>Zakres</dt><dd class="nreg-mono">' + esc(n.first) + ' – ' + esc(n.last) + copyBtn(n.first + '-' + n.last) + '</dd>'
                + '<dt>Rozmiar</dt><dd>' + s.size + (n.subnet && n.len <= 30 ? ' (hostów: ' + s.capacity + ')' : '') + '</dd>'
                + '<dt>Zajęte</dt><dd>' + s.used + ' z ' + s.capacity + ' (' + s.util + '%) ' + bar(s.util) + '</dd>'
                + '<dt>Adresów</dt><dd>' + s.addresses + '</dd>'
                + (n.label ? '<dt>Opis</dt><dd>' + esc(n.label) + '</dd>' : '') + '</dl>';
            if (n.virtual) { h += '<p class="nreg-muted" style="font-size:12px">To poziom grupowania /' + n.len + ' - nie ma tu prawdziwego prefiksu.</p>'; }
            if (n.lnms.length) { h += '<h5>Na interfejsach (LibreNMS)</h5>' + lnmsTable(n.lnms, true); }
            if (n.manual) { h += manualBlock(n.manual); }
            else if (can.canEdit) {
                h += '<p><button class="btn btn-success btn-xs" type="button" data-add="' + esc(n.cidr) + '"><i class="fa fa-plus"></i> Opisz ten prefiks (wpis ręczny)</button></p>';
            }
            if (n.subnet) {
                h += '<h5>Wolne adresy hostów (' + j.free_ranges_total + ' przedziałów)</h5><div class="nreg-chips">'
                    + j.free_ranges.map(function (r) {
                        var t = r.first === r.last ? r.first : r.first + ' – ' + r.last;
                        return '<a href="#" data-add="' + esc(r.first === r.last ? r.first : r.first + '-' + r.last) + '" title="dodaj jako wpis ręczny">' + esc(t) + ' (' + r.count + ')</a>';
                    }).join('') + '</div>';
            } else if (j.free_blocks.length) {
                h += '<h5>Wolne bloki (największe)</h5><div class="nreg-chips">'
                    + j.free_blocks.map(function (b) { return '<a href="#" data-add="' + esc(b.cidr) + '" title="dodaj jako wpis ręczny">' + esc(b.cidr) + '</a>'; }).join('') + '</div>';
            }
            h += '<h5>Historia</h5>' + (j.history.length ? N.logTable(j.history, false) : '<p class="nreg-muted">Brak zmian.</p>');
            if (put(h)) { document.getElementById('nreg-ip-within').value = n.cidr; }
        }).catch(function (e) { put(alertBox('danger', esc(e.message))); });
    }

    function openIp(ip) {
        var put = detailLoader('Ładowanie ' + ip + '…');
        api('search', { q: ip }).then(function (j) {
            var a = j.address;
            var nets = j.path.filter(function (c) { return c.indexOf('range:') !== 0; }), rng = j.path.filter(function (c) { return c.indexOf('range:') === 0; })[0];
            var h = '<h4 class="nreg-mono">' + esc(ip) + copyBtn(ip) + (a ? ' <span class="nreg-bdg ' + a.state + '">' + esc(STATE_BDG[a.state]) + '</span>' : '')
                + (a && a.dup ? ' <span class="nreg-bdg d">duplikat</span>' : '') + '</h4>'
                + (nets.length ? '<p style="font-size:12px">Sieć: <a href="#" data-open-node="' + esc(nets[nets.length - 1]) + '" class="nreg-mono">' + esc(nets[nets.length - 1]) + '</a>'
                    + (rng ? ' &middot; zakres: <a href="#" data-open-node="' + esc(rng) + '">' + esc(a && a.range ? a.range.label + ' (' + a.range.role_label + ')' : 'pokaż') + '</a>' : '') + '</p>' : '');
            if (!a) {
                h += '<p>Adres jest wolny w tej przestrzeni.</p>';
                if (can.canEdit) { h += '<p><button class="btn btn-success btn-xs" type="button" data-add="' + esc(ip) + '"><i class="fa fa-plus"></i> Dodaj jako wpis ręczny</button></p>'; }
            } else {
                if (a.rows.length) { h += '<h5>LibreNMS</h5>' + lnmsTable(a.rows, false); }
                if (a.dup) { h += '<div class="alert alert-warning" style="padding:6px 10px">Ten adres jest na kilku urządzeniach jednocześnie.</div>'; }
                if (a.manual) { h += manualBlock(a.manual); }
                if (a.range && !a.rows.length && !a.manual) { h += '<p>Adres jest wolny w zakresie ' + esc(a.range.label) + '.</p>'; }
            }
            h += '<h5>Historia</h5>' + (j.history.length ? N.logTable(j.history, false) : '<p class="nreg-muted">Brak zmian.</p>');
            put(h);
        }).catch(function (e) { put(alertBox('danger', esc(e.message))); });
    }

    // ---------------------------------------------------------------- wyszukiwanie i wolne

    document.getElementById('nreg-ip-search').addEventListener('submit', function (e) {
        e.preventDefault();
        var q = document.getElementById('nreg-ip-q').value.trim();
        if (!q) { return; }
        result.innerHTML = '';
        api('search', { q: q }).then(function (j) {
            if (j.kind === 'address') { expandPath(j.path, j.ip); openIp(j.ip); return; }
            if (j.kind === 'prefix') {
                if (!j.exact) { result.innerHTML = alertBox('info', 'Nie ma prefiksu ' + esc(j.cidr) + ' w drzewie. Pokazuję najbliższą sieć, która go zawiera.'); }
                expandPath(j.path); if (j.path.length) { openNode(j.path[j.path.length - 1]); }
                return;
            }
            clearDetail();
            if (!j.hits.length) { result.innerHTML = alertBox('warning', 'Nic nie znaleziono dla „' + esc(q) + '”.'); return; }
            result.innerHTML = alertBox('info', 'Znaleziono ' + j.hits.length + ':<br>' + j.hits.map(function (h) {
                var go = h.type === 'range' ? 'data-goto-range="' + h.id + '" data-first="' + esc(h.first) + '"'
                    : (h.type === 'address' ? 'data-goto-ip="' : 'data-goto-node="') + esc(h.value) + '"';
                return '<a href="#" class="nreg-mono" ' + go + '>' + esc(h.value) + '</a> <span class="nreg-muted">' + esc(h.label || '') + '</span>';
            }).join('<br>'));
        }).catch(function (e2) { result.innerHTML = alertBox('danger', esc(e2.message)); });
    });

    document.getElementById('nreg-ip-free').addEventListener('submit', function (e) {
        e.preventDefault();
        clearDetail();
        api('free', { within: document.getElementById('nreg-ip-within').value.trim(), len: document.getElementById('nreg-ip-len').value, count: 10 })
            .then(function (j) {
                result.innerHTML = j.blocks.length
                    ? alertBox('success', 'Wolne /' + j.len + ' w ' + esc(j.within) + ': <span class="nreg-chips">' + j.blocks.map(function (b) {
                        return '<a href="#" data-add="' + esc(b) + '" title="dodaj jako wpis ręczny">' + esc(b) + '</a>';
                    }).join('') + '</span>')
                    : alertBox('warning', 'Brak wolnych /' + j.len + ' w ' + esc(j.within) + '.');
            }).catch(function (e2) { result.innerHTML = alertBox('danger', esc(e2.message)); });
    });

    // ---------------------------------------------------------------- uwagi i wpisy ręczne

    function loadIssues() {
        var box = document.querySelector('#nreg-ip-issues > div');
        return api('issues').then(function (j) {
            var n = j.duplicates.length + j.suspicious.length + j.reserved_conflicts.length + (j.expiry || []).length + (j.range_conflicts || []).length;
            document.querySelector('#nreg-ip-issues > summary').textContent = 'Uwagi (' + n + ')' + (j.orphans_total ? ', adresy poza sieciami: ' + j.orphans_total : '');
            var h = '';
            if ((j.expiry || []).length) {
                h += '<h5>Wpisy ręczne po terminie albo z bliskim terminem</h5><p class="nreg-muted" style="font-size:12px">Wpis po terminie nadal zajmuje adresy.'
                    + ' Przedłuż termin albo usuń wpis.</p>' + j.expiry.map(function (x) {
                        var go = gotoAttr(x);
                        return '<div><a href="#" class="nreg-mono" ' + go + '>' + esc(x.label) + '</a>' + (x.count > 1 ? ' <span class="nreg-muted">(' + x.count + ')</span>' : '')
                            + ' ' + N.expiryHtml(x) + ' ' + esc(x.role_label) + ' · ' + esc(x.name || x.description) + (x.owner ? ' <span class="nreg-muted">(' + esc(x.owner) + ')</span>' : '') + '</div>';
                    }).join('');
            }
            if (j.reserved_conflicts.length) {
                h += '<h5>Rezerwacje z adresami z LibreNMS</h5>' + j.reserved_conflicts.map(function (c) {
                    var go = c.range_id ? 'data-goto-range="' + c.range_id + '" data-first="' + esc(c.first) + '"' : 'data-goto-node="' + esc(c.cidr) + '"';
                    return '<div><a href="#" ' + go + ' class="nreg-mono">' + esc(c.cidr) + '</a>: ' + esc(c.ips) + ' (' + c.count + ')</div>';
                }).join('');
            }
            if ((j.range_conflicts || []).length) {
                h += '<h5>Zakresy poza siecią albo przecięte przez sieć</h5><p class="nreg-muted" style="font-size:12px">Zakres powinien leżeć w całości w jednej sieci'
                    + ' albo prefiksie. Zwykle znaczy to, że w LibreNMS pojawiła się nowa sieć albo usunięto prefiks ręczny.</p>'
                    + j.range_conflicts.map(function (c) {
                        return '<div><a href="#" class="nreg-mono" data-goto-range="' + c.id + '" data-first="' + esc(c.first) + '">' + esc(c.label) + '</a>: ' + esc(c.problem) + '</div>';
                    }).join('');
            }
            if (j.duplicates.length) {
                h += '<h5>Ten sam adres na kilku urządzeniach (' + j.duplicates.length + ')</h5><p class="nreg-muted" style="font-size:12px">Bywa celowe (CGNAT, adresy domyślne, anycast).</p>'
                    + j.duplicates.map(function (d) { return '<div><a href="#" data-goto-ip="' + esc(d.ip) + '" class="nreg-mono">' + esc(d.ip) + '</a>: ' + esc(d.devices.join(', ')) + '</div>'; }).join('');
            }
            if (j.suspicious.length) {
                h += '<h5>Podejrzane maski (krótsze niż /8)</h5>' + j.suspicious.map(function (s) {
                    return '<div class="nreg-mono">' + esc(s.ip) + '/' + s.len + ' <span class="nreg-muted">' + esc(s.device + ' ' + s['if']) + '</span></div>';
                }).join('');
            }
            if (j.orphans_total) {
                h += '<h5>Adresy bez sieci (' + j.orphans_total + ')</h5><p class="nreg-muted" style="font-size:12px">Adresy, które nie należą do żadnej sieci z LibreNMS'
                    + ' ani do sieci ręcznej, zwykle z maską /32 (loopbacki, aliasy). W drzewie leżą w korzeniu albo w agregacie, poza sieciami.'
                    + ' Żeby je opisać, obejmij je prefiksem ręcznym (np. agregatem).</p><div class="nreg-chips">'
                    + j.orphans.map(function (o) { return '<a href="#" data-goto-ip="' + esc(o.ip) + '" title="' + esc(o.device + ' ' + o['if']) + '">' + esc(o.ip) + '/' + o.len + '</a>'; }).join('') + '</div>';
            }
            if (j.v6_count) { h += '<p class="nreg-muted" style="font-size:12px">IPv6: ' + j.v6_count + ' adresów zapisanych, drzewo IPv6 w kolejnej wersji.</p>'; }
            box.innerHTML = h || '<p class="nreg-muted">Brak uwag.</p>';
        }).catch(function (e) { box.innerHTML = alertBox('danger', esc(e.message)); });
    }

    function loadManual() {
        var box = document.querySelector('#nreg-ip-manual > div');
        return api('manual_list').then(function (j) {
            prefixRoles = j.prefix_roles; addressRoles = j.address_roles; rangeRoles = j.range_roles;
            manualCache = {};
            j.rows.forEach(function (m) { manualCache[m.id] = m; });
            document.querySelector('#nreg-ip-manual > summary').textContent = 'Wpisy ręczne (' + j.rows.length + ')';
            if (!j.rows.length) { box.innerHTML = '<p class="nreg-muted">Brak wpisów ręcznych.</p>'; return; }
            box.innerHTML = '<table class="table table-condensed table-hover" style="font-size:12px"><thead><tr><th>Adres / prefiks</th><th>Rola</th><th>Ważne do</th><th>Nazwa</th>'
                + '<th>Opis</th><th>Właściciel</th><th>VLAN</th><th>Dodał</th><th></th></tr></thead><tbody>' + j.rows.map(function (m) {
                    var many = m.count > 1, go = gotoAttr(m);
                    var btn = '';
                    if (can.canEdit) { btn += '<button class="btn btn-default btn-xs" type="button" data-edit="' + m.id + '" data-group="' + (many ? 1 : 0) + '"><i class="fa fa-pencil"></i> Edytuj' + (many ? ' grupę (' + m.count + ')' : '') + '</button> '; }
                    // Ciągła grupa adresów (etykieta bez przecinka) może stać się jednym zakresem.
                    if (can.canEdit && many && m.kind === 'address' && m.label.indexOf(',') < 0 && !m.adopted_at) {
                        btn += '<button class="btn btn-default btn-xs" type="button" data-torange="' + m.id + '" data-label="' + esc(m.label) + '" title="zamień grupę adresów na jeden wpis-zakres (pulę)"><i class="fa fa-compress"></i> Na zakres</button> ';
                    }
                    if (can.canDelete) { btn += '<button class="btn btn-danger btn-xs" type="button" data-del="' + m.id + '" data-group="' + (many ? 1 : 0) + '" data-label="' + esc(m.label) + '"><i class="fa fa-trash"></i> Usuń' + (many ? ' grupę' : '') + '</button>'; }
                    return '<tr><td><a href="#" class="nreg-mono" ' + go + '>' + esc(m.label) + '</a>' + (many ? ' <span class="nreg-muted">(' + m.count + ')</span>' : '')
                        + (m.kind === 'range' ? ' <span class="nreg-muted">(zakres, ' + m.size + ' adr.)</span>' : '') + '</td>'
                        + '<td>' + esc(m.role_label) + (m.adopted_at ? ' <span class="label label-primary">przejęty</span>' : '') + (m.current ? ' <small class="nreg-muted">' + esc(m.current) + '</small>' : '') + '</td>'
                        + '<td>' + (m.adopted_at ? '' : N.expiryHtml(m)) + '</td><td>' + esc(m.name || '') + '</td><td>' + esc(m.description) + '</td><td>' + esc(m.owner || '') + '</td>'
                        + '<td>' + (m.vlan ? '<a href="' + esc(can.page) + '?vlan=' + m.vlan + '#vlan">' + m.vlan + '</a>' : '') + '</td>'
                        + '<td style="font-size:11px">' + esc(m.created_by || '?') + '<br>' + esc(m.created_at) + '</td><td style="white-space:nowrap">' + btn + '</td></tr>';
                }).join('') + '</tbody></table>';
        }).catch(function (e) { box.innerHTML = alertBox('danger', esc(e.message)); });
    }

    // ---------------------------------------------------------------- formularz

    function isPrefix(text) { var m = /\/(\d{1,2})\s*$/.exec(text.trim()); return !!m && parseInt(m[1], 10) < 32 && !/[,\s;-]/.test(text.trim().replace(/\/\d+$/, '')); }

    // Liczba adresów w pojedynczym zakresie "a-b" / "a-n" albo 0, gdy to nie jest pojedynczy zakres.
    function rangeSize(text) {
        var m = /^\s*(\d{1,3}(?:\.\d{1,3}){3})\s*[-–]\s*(\d{1,3}(?:\.\d{1,3}){3}|\d{1,3})\s*$/.exec(text);
        if (!m) { return 0; }
        var n = function (ip) { return ip.split('.').reduce(function (acc, o) { return acc * 256 + parseInt(o, 10); }, 0); };
        var a = n(m[1]), b = m[2].indexOf('.') >= 0 ? n(m[2]) : a - (a % 256) + parseInt(m[2], 10);
        return Math.abs(b - a) + 1;
    }

    // Pole "jako": tylko przy pojedynczym zakresie; powyżej 1024 adresów zostaje sam zakres.
    function updateAs() {
        var sel = form.elements.as, size = rangeSize(form.elements.target.value);
        sel.style.display = size > 1 && !form.elements.id.value ? '' : 'none';
        sel.querySelector('option[value="addresses"]').disabled = size > 1024;
        if (size > 1024) { sel.value = 'range'; }
        if (size <= 1) { sel.value = 'addresses'; }
    }

    function formKind() {
        if (isPrefix(form.elements.target.value)) { return 'prefix'; }
        return form.elements.as.style.display !== 'none' && form.elements.as.value === 'range' ? 'range' : 'address';
    }

    function fillRoles(kind, current) {
        var sel = form.elements.role, roles = kind === 'prefix' ? prefixRoles : (kind === 'range' ? rangeRoles : addressRoles);
        sel.innerHTML = Object.keys(roles).map(function (k) { return '<option value="' + k + '">' + esc(roles[k]) + '</option>'; }).join('');
        if (current && roles[current]) { sel.value = current; }
    }

    function openForm(entry, target, group) {
        if (!form) { return; }
        if (!entry) { clearDetail(); }
        form.reset();
        document.getElementById('nreg-if-error').textContent = '';
        form.elements.id.value = entry ? entry.id : '';
        form.elements.group.value = group ? '1' : '0';
        var t = form.elements.target;
        t.value = entry ? (group ? entry.label : (entry.ip || entry.cidr)) : (target || '');
        t.disabled = !!entry;
        updateAs();
        fillRoles(entry ? entry.kind : formKind(), entry ? entry.role : null);
        document.getElementById('nreg-if-title').textContent = entry ? (group ? 'Edycja grupy ' + entry.label
            : (entry.kind === 'range' ? 'Edycja zakresu ' : 'Edycja wpisu ') + (entry.ip || entry.cidr)) : 'Dodaj adresację (wpis ręczny)';
        if (entry) { ['name', 'description', 'owner', 'vlan', 'devices', 'valid_until'].forEach(function (f) { form.elements[f].value = entry[f] || ''; }); }
        form.style.display = '';
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        (entry ? form.elements.name : t).focus();
        loadDevices();
    }

    // Podpowiedzi hostów z LibreNMS do pola „Urządzenia / lokalizacja” (raz na wczytanie strony).
    var devicesLoaded = false;
    function loadDevices() {
        if (devicesLoaded) { return; }
        devicesLoaded = true;
        api('devices').then(function (j) {
            document.getElementById('nreg-if-devlist').innerHTML = j.devices.map(function (d) {
                var info = [d.hostname !== d.name ? d.hostname : '', d.location || '', d.disabled ? 'wyłączony' : ''].filter(Boolean).join(' · ');
                return '<option value="' + esc(d.name) + '"' + (info ? ' label="' + esc(info) + '"' : '') + '></option>';
            }).join('');
        }).catch(function () { devicesLoaded = false; });
    }

    function refreshAll() {
        return Promise.all([loadRoot(), loadManual(), loadIssues()]);
    }

    if (form) {
        form.elements.target.addEventListener('input', function () { updateAs(); fillRoles(formKind(), form.elements.role.value); });
        form.elements.as.addEventListener('change', function () { fillRoles(formKind(), form.elements.role.value); });
        document.getElementById('nreg-if-cancel').addEventListener('click', function () { form.style.display = 'none'; });
        document.getElementById('nreg-ip-add').addEventListener('click', function () { openForm(null, document.getElementById('nreg-ip-within').value); });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var err = document.getElementById('nreg-if-error'), btn = form.querySelector('button[type="submit"]'), data = {};
            ['role', 'name', 'description', 'owner', 'vlan', 'devices', 'valid_until', 'comment'].forEach(function (f) { data[f] = form.elements[f].value; });
            var id = form.elements.id.value, path;
            if (id) { data.id = id; data.group = form.elements.group.value === '1'; path = 'manual_update'; }
            else { data.target = form.elements.target.value; data.as = formKind() === 'range' ? 'range' : ''; path = 'manual_create'; }
            btn.disabled = true; err.textContent = '';
            api(path, data, 'POST').then(function (j) {
                form.style.display = 'none';
                result.innerHTML = alertBox('success', id ? 'Zapisano zmiany.' : 'Dodano ' + (j.kind === 'prefix' ? 'prefiks ' : (j.kind === 'range' ? 'zakres ' : j.count + ' adres(ów) jako jedną pozycję: '))
                    + '<span class="nreg-mono">' + esc(j.label) + '</span>.');
                clearDetail();
                return refreshAll();
            }).catch(function (e2) { err.textContent = e2.message; }).then(function () { btn.disabled = false; });
        });
    }

    function toRange(id, label) {
        if (!window.confirm('Zamienić grupę adresów ' + label + ' na jeden wpis-zakres? Opis, właściciel i termin przejdą na zakres. Zmiana trafi do dziennika.')) { return; }
        api('manual_to_range', { id: id }, 'POST').then(function (j) {
            result.innerHTML = alertBox('success', 'Grupa ' + esc(label) + ' jest teraz zakresem <span class="nreg-mono">' + esc(j.entry.cidr) + '</span> (' + esc(j.entry.role_label) + ').');
            clearDetail();
            return refreshAll();
        }).catch(function (e) { result.innerHTML = alertBox('danger', esc(e.message)); });
    }

    // Link do wpisu ręcznego: prefiks, zakres albo adres.
    function gotoAttr(m) {
        if (m.kind === 'prefix') { return 'data-goto-node="' + esc(m.cidr) + '"'; }
        if (m.kind === 'range') { return 'data-goto-range="' + m.id + '" data-first="' + esc(m.first) + '"'; }
        return 'data-goto-ip="' + esc(m.ip) + '"';
    }

    function deleteEntry(id, label, group) {
        if (!window.confirm('Usunąć wpis ręczny ' + label + (group ? ' (całą grupę)' : '') + '? Zostanie w dzienniku zmian.')) { return; }
        var comment = window.prompt('Komentarz do usunięcia (opcjonalnie, trafi do dziennika):', '');
        if (comment === null) { return; }
        api('manual_delete', { id: id, comment: comment, group: group }, 'POST').then(function (j) {
            result.innerHTML = alertBox('success', 'Usunięto wpis ręczny <span class="nreg-mono">' + esc(j.deleted) + '</span>.');
            clearDetail();
            return refreshAll();
        }).catch(function (e) { result.innerHTML = alertBox('danger', esc(e.message)); });
    }

    // ---------------------------------------------------------------- agregaty z RIPE

    var ripePanel = document.getElementById('nreg-ip-ripe'), ripeOut = document.getElementById('nreg-ir-out'), ripeData = null;

    function ripeState(p) {
        if (p.unsupported) { return '<span class="nreg-muted">IPv6 - drzewo jeszcze nie obsługuje</span>'; }
        var out = [];
        if (p.exists) { out.push('<span class="label label-default">już jest: ' + esc(p.exists) + '</span>'); }
        if (p.in_aggregate) { out.push('w agregacie <span class="nreg-mono">' + esc(p.in_aggregate) + '</span>'); }
        if (p.parent) { out.push('w szerszym z listy <span class="nreg-mono">' + esc(p.parent) + '</span>'); }
        if (p.lnms) { out.push(p.lnms + ' sieci w LibreNMS'); }
        return out.join('<br>') || '<span class="nreg-muted">brak w rejestrze</span>';
    }

    function ripeRender(j) {
        ripeData = j;
        var h = '<p><strong>AS' + j.asn + '</strong>' + (j.holder ? ' &middot; ' + esc(j.holder) : '')
            + (j.org ? ' &middot; organizacja ' + esc(j.org_name || '') + ' <span class="nreg-mono">' + esc(j.org) + '</span>' : '') + '</p>';
        if (j.warnings.length) { h += alertBox('warning', j.warnings.map(esc).join('<br>')); }
        if (!j.prefixes.length) { ripeOut.innerHTML = h + '<p>RIPE nie zna prefiksów tego AS.</p>'; return; }
        h += '<p class="nreg-sub" style="opacity:1">Zaznaczone są prefiksy, których nie ma w rejestrze i które nie leżą w agregacie ani w szerszym prefiksie z listy. '
            + 'Sprawdź kolumnę „Przydział w RIPE”: AS może ogłaszać też adresację klientów albo innych firm.</p>'
            + '<table class="table table-condensed table-hover"><thead><tr><th><input type="checkbox" id="nreg-ir-all" title="zaznacz wszystkie"></th>'
            + '<th>Prefiks</th><th>Nazwa agregatu</th><th>Źródło</th><th>Przydział w RIPE</th><th>W rejestrze</th></tr></thead><tbody>';
        j.prefixes.forEach(function (p, i) {
            var off = p.unsupported || !!p.exists, inet = p.inetnum;
            h += '<tr' + (off ? ' class="nreg-muted"' : '') + '><td><input type="checkbox" data-ripe="' + i + '"' + (p.checked ? ' checked' : '') + (off ? ' disabled' : '') + '></td>'
                + '<td class="nreg-mono">' + esc(p.cidr) + '</td>'
                + '<td>' + (off ? '' : '<input class="form-control nm" data-ripe-name="' + i + '" maxlength="64" value="' + esc(p.name || '') + '">') + '</td>'
                + '<td>' + esc(p.sources_label.join(', ')) + '</td>'
                + '<td>' + (inet ? '<span class="nreg-mono">' + esc(inet.range) + '</span> ' + esc(inet.netname || '') + '<br><small>' + esc(inet.status || '')
                    + (inet.org ? ' &middot; ' + esc(inet.org) : '') + '</small>' : '<span class="nreg-muted">' + (p.family === 4 ? 'brak danych' : '') + '</span>') + '</td>'
                + '<td>' + ripeState(p) + '</td></tr>';
        });
        h += '</tbody></table><div class="row"><div class="col-sm-6"><label for="nreg-ir-desc">Opis agregatów</label>'
            + '<input class="form-control" id="nreg-ir-desc" maxlength="1000" value="' + esc('Przydział AS' + j.asn + (j.holder ? ' ' + j.holder : '') + ' (z RIPE)') + '"></div>'
            + '<div class="col-sm-6"><label for="nreg-ir-comment">Komentarz do zmiany (trafi do dziennika)</label>'
            + '<input class="form-control" id="nreg-ir-comment" maxlength="1000" placeholder="opcjonalnie"></div></div>'
            + '<button class="btn btn-success btn-sm" type="button" id="nreg-ir-import" style="margin-top:6px"><i class="fa fa-plus"></i> Dodaj zaznaczone jako agregaty</button>'
            + ' <span id="nreg-ir-count" class="nreg-sub"></span>';
        ripeOut.innerHTML = h;
        ripeCount();
    }

    function ripeChecked() {
        return Array.prototype.slice.call(ripeOut.querySelectorAll('input[data-ripe]:checked')).map(function (c) {
            var i = c.getAttribute('data-ripe'), nm = ripeOut.querySelector('input[data-ripe-name="' + i + '"]');
            return { cidr: ripeData.prefixes[i].cidr, name: nm ? nm.value : '' };
        });
    }

    function ripeCount() {
        var el = document.getElementById('nreg-ir-count');
        if (el) { el.textContent = 'zaznaczono: ' + ripeChecked().length; }
    }

    if (ripePanel) {
        document.getElementById('nreg-ip-ripebtn').addEventListener('click', function () {
            clearDetail();
            if (form) { form.style.display = 'none'; }
            ripePanel.style.display = '';
            var asn = document.getElementById('nreg-ir-asn');
            if (!asn.value) { asn.value = localStorage.getItem('nreg-ripe-asn') || ''; }
            asn.focus();
        });
        document.getElementById('nreg-ir-close').addEventListener('click', function () { ripePanel.style.display = 'none'; });
        document.getElementById('nreg-ir-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var asn = document.getElementById('nreg-ir-asn').value.trim(), btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            ripeOut.innerHTML = '<p class="nreg-muted">Pobieram dane z RIPE (do kilkunastu sekund)…</p>';
            api('ripe_lookup', { asn: asn }).then(function (j) {
                localStorage.setItem('nreg-ripe-asn', asn);
                ripeRender(j);
            }).catch(function (e2) { ripeOut.innerHTML = alertBox('danger', esc(e2.message)); }).then(function () { btn.disabled = false; });
        });
        ripeOut.addEventListener('change', function (e) {
            if (e.target.id === 'nreg-ir-all') {
                ripeOut.querySelectorAll('input[data-ripe]:not(:disabled)').forEach(function (c) { c.checked = e.target.checked; });
            }
            ripeCount();
        });
        ripeOut.addEventListener('click', function (e) {
            if (!e.target.closest('#nreg-ir-import')) { return; }
            var items = ripeChecked(), btn = document.getElementById('nreg-ir-import');
            if (!items.length) { window.alert('Zaznacz co najmniej jeden prefiks.'); return; }
            if (!window.confirm('Dodać ' + items.length + ' agregat(ów)? Każdy trafi do dziennika zmian.')) { return; }
            btn.disabled = true;
            api('ripe_import', { asn: ripeData.asn, items: items, description: document.getElementById('nreg-ir-desc').value,
                comment: document.getElementById('nreg-ir-comment').value }, 'POST').then(function (j) {
                var bad = j.results.filter(function (r) { return !r.ok; });
                result.innerHTML = alertBox(bad.length ? 'warning' : 'success', 'Dodano agregatów: ' + j.added + ' z ' + j.results.length + '.'
                    + bad.map(function (r) { return '<br><span class="nreg-mono">' + esc(r.cidr) + '</span>: ' + esc(r.error); }).join(''));
                ripePanel.style.display = 'none';
                ripeOut.innerHTML = '';
                return refreshAll();
            }).catch(function (e2) { result.innerHTML = alertBox('danger', esc(e2.message)); btn.disabled = false; });
        });
    }

    // ---------------------------------------------------------------- przestrzenie

    function loadSpaces() {
        return N.api('ipam/spaces').then(function (j) {
            spaceSel.innerHTML = j.spaces.map(function (s) {
                return '<option value="' + s.id + '">' + esc(s.name) + (s.vrf ? ' (VRF ' + esc(s.vrf) + ')' : '') + '</option>';
            }).join('');
            spaceSel.value = String(space);
        });
    }
    spaceSel.addEventListener('change', function () { space = parseInt(spaceSel.value, 10) || 1; clearDetail(); refreshAll(); });
    if (staleBox) {
        staleBox.addEventListener('change', function () {
            showStale = staleBox.checked;
            localStorage.setItem('nreg-ip-stale', showStale ? '1' : '0');
            clearDetail(); refreshAll();
        });
    }
    var newSpace = document.getElementById('nreg-ip-newspace');
    if (newSpace) {
        newSpace.addEventListener('click', function () {
            var name = window.prompt('Nazwa nowej przestrzeni adresowej (np. VRF albo klient):', '');
            if (!name) { return; }
            var vrf = window.prompt('Nazwa VRF w LibreNMS (context_name) przypisywana do tej przestrzeni - opcjonalnie:', '') || '';
            N.api('ipam/space_create', { name: name, vrf: vrf }, 'POST').then(function (j) {
                space = j.space.id; return loadSpaces().then(refreshAll);
            }).catch(function (e) { result.innerHTML = alertBox('danger', esc(e.message)); });
        });
    }

    // ---------------------------------------------------------------- kliknięcia

    tab.addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('[data-copy]'))) {
            e.preventDefault();
            var icon = t.querySelector('i'), text = t.getAttribute('data-copy');
            copyText(text).then(function () {
                icon.className = 'fa fa-check'; t.title = 'skopiowano';
            }, function () {
                window.prompt('Skopiuj ręcznie (Ctrl+C):', text);
            }).then(function () { setTimeout(function () { icon.className = 'fa fa-clipboard'; t.title = 'kopiuj ' + text; }, 1500); });
            return;
        }
        if ((t = e.target.closest('[data-toggle-node]'))) { clearDetail(); toggle(t.getAttribute('data-toggle-node')); }
        else if ((t = e.target.closest('[data-open-node]'))) {
            e.preventDefault();
            var row = treeEl.querySelector('.nreg-tn[data-cidr="' + t.getAttribute('data-open-node') + '"]');
            if (row) { select(row); }
            openNode(t.getAttribute('data-open-node'));
        } else if ((t = e.target.closest('[data-open-ip]'))) { select(t); openIp(t.getAttribute('data-open-ip')); }
        else if ((t = e.target.closest('[data-goto-ip]'))) {
            e.preventDefault();
            var ip = t.getAttribute('data-goto-ip');
            api('search', { q: ip }).then(function (j) { expandPath(j.path, ip); openIp(ip); });
        } else if ((t = e.target.closest('[data-goto-range]'))) {
            e.preventDefault();
            var rid = t.getAttribute('data-goto-range');
            // Wyszukanie pierwszego adresu daje ścieżkę przez zakres.
            api('search', { q: t.getAttribute('data-first') }).then(function (j) { expandPath(j.path); openRange(rid); });
        } else if ((t = e.target.closest('[data-goto-node]'))) {
            e.preventDefault();
            var c = t.getAttribute('data-goto-node');
            api('search', { q: c }).then(function (j) { expandPath(j.path); openNode(j.path.length ? j.path[j.path.length - 1] : c); });
        } else if ((t = e.target.closest('[data-add]'))) {
            e.preventDefault();
            openForm(null, t.getAttribute('data-add'));
            // Z wolnego miejsca w drzewie najczęściej dodaje się sieć, a nie agregat.
            if (form && t.classList.contains('nreg-tf') && form.elements.role.querySelector('option[value="used"]')) { form.elements.role.value = 'used'; }
        }
        else if ((t = e.target.closest('button[data-edit]'))) {
            var m = manualCache[t.getAttribute('data-edit')];
            if (m) { openForm(m, null, t.getAttribute('data-group') === '1'); }
        } else if ((t = e.target.closest('button[data-torange]'))) {
            toRange(t.getAttribute('data-torange'), t.getAttribute('data-label'));
        } else if ((t = e.target.closest('button[data-del]'))) {
            deleteEntry(t.getAttribute('data-del'), t.getAttribute('data-label'), t.getAttribute('data-group') === '1');
        }
    });

    // Drzewo ładujemy przy pierwszym otwarciu zakładki (albo od razu, gdy jest aktywna).
    function start() {
        if (loaded) { return; }
        loaded = true;
        loadSpaces().then(function () { return Promise.all([loadRoot(), loadManual(), loadIssues()]); }).then(function () {
            var q = new URLSearchParams(location.search);
            var ip = q.get('ip'), pfx = q.get('prefix');
            if (ip) { api('search', { q: ip }).then(function (j) { expandPath(j.path, ip); openIp(ip); }); }
            else if (pfx) { api('search', { q: pfx }).then(function (j) { expandPath(j.path); if (j.path.length) { openNode(j.path[j.path.length - 1]); } }); }
        });
    }
    document.addEventListener('nreg:tab', function (e) { if (e.detail === 'ipam') { start(); } });
    if (tab.classList.contains('active')) { start(); }
});
</script>
