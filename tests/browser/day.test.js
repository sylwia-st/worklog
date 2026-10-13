// Test zachowania strony dnia w symulowanej przeglądarce: autozapis, awaria serwera, szkic, stoper.
const { JSDOM } = require('jsdom');
const fs = require('fs');
const T = require('path').join(__dirname, '../../templates/');

const paths = {
    day_autosave: '/day/2026-10-20/autosave', day_panels: '/day/2026-10-20/panels',
    timer_state: '/timer', timer_start: '/timer/start', timer_stop: '/timer/stop',
};
function scripts(file) {
    const src = fs.readFileSync(T + file, 'utf8');
    return [...src.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]
        .replace(/\{\{ path\('day', \{date: '0000-00-00'\}\)\|replace[^}]*\}\}/g, '/day/')
        .replace(/\{\{ path\('day', \{date: '0000-00-00'\}\) \}\}/g, '/day/0000-00-00')
        .replace(/\{\{ path\('(\w+)'.*?\) \}\}/g, (_, n) => paths[n] || '/' + n)
        .replace(/\{\{ date\|date\('Y-m-d'\) \}\}/g, '2026-10-20')
        .replace(/\{\{ lunch \}\}/g, '12:00–13:00'));
}
const row = (i, r) => `<tr data-key="${i}"><td class="st"><span class="dot ${r.status || 'none'}"></span></td>
 <td class="issue"><input type="hidden" name="entries[${i}][id]" value="${r.id}" class="f-id"><input type="hidden" name="entries[${i}][orig]" value="${r.time}" class="f-orig">
 <input type="text" name="entries[${i}][issue]" value="${r.issue}" class="f-issue"></td>
 <td class="tm"><button type="button" class="tbtn">▶</button></td>
 <td class="time"><input type="text" name="entries[${i}][time]" value="${r.time}" class="f-time"><div class="live"></div></td>
 <td><textarea name="entries[${i}][desc]" class="f-desc">${r.desc}</textarea><div class="rowerr"></div></td>
 <td class="del"><input type="checkbox" name="entries[${i}][delete]" value="1" class="f-del"></td></tr>`;

function page(rows, storage) {
    const html = `<!doctype html><body>
 <div id="timerbar" hidden><b id="tb-issue"></b><span id="tb-date"></span><span id="tb-time"></span><button id="tb-stop"></button><a id="tb-link"></a></div>
 <div id="timerstale" hidden><strong id="ts-msg"></strong><input id="ts-at"><button id="ts-stop-at"></button><button id="ts-stop-now"></button><button id="ts-keep"></button></div>
 <div id="draft-banner" hidden><span id="draft-msg"></span><button id="draft-restore"></button><button id="draft-discard"></button></div>
 <form id="dayform"><input id="start" name="start" value="08:00"><input id="end" name="end" value="16:00"><label id="break-label">Dodatkowa przerwa</label><input id="break" name="break" value="">
  <input type="checkbox" id="lunch_worked" name="lunch_worked" value="1" data-lunch="1h" data-help-on="POMOC-WŁ" data-help-off="POMOC-WYŁ"><p id="lunch-help">POMOC-WYŁ</p>
  <table><tbody id="rows">${rows.map((r, i) => row(i, r)).join('')}</tbody></table>
  <button type="button" id="addrow"></button><button type="submit" name="action" value="sync" id="syncbtn"></button><span id="savestate"></span></form>
 <div id="panels"></div>
 <template id="rowtpl">${row('__i__', {id: 0, issue: '', time: '', desc: ''})}</template></body>`;
    const dom = new JSDOM(html, {runScripts: 'outside-only', url: 'http://127.0.0.1:8765/day/2026-10-20'});
    const w = dom.window;
    if (storage) for (const [k, v] of Object.entries(storage)) w.localStorage.setItem(k, v);
    w.calls = []; w.fail = false; w.delay = 0; w.nextId = 100; w.timer = {running: false};
    // wysłanie formularza (to, które przeglądarka faktycznie wykonałaby – bez preventDefault)
    w.submits = [];
    // nasłuch na window (faza bąbelkowania) – działa po obsłudze zdarzenia przez stronę
    w.addEventListener('submit', (ev) => {
        if (!ev.defaultPrevented) w.submits.push(Object.fromEntries(new w.FormData(ev.target, ev.submitter).entries()));
        ev.preventDefault(); // jsdom i tak nie nawiguje
    });
    w.fetch = async (url, opts = {}) => {
        const body = opts.body instanceof w.FormData ? Object.fromEntries(opts.body.entries()) : (opts.body ? JSON.parse(opts.body) : null);
        w.calls.push({url, method: opts.method || 'GET', body});
        if (w.delay && url.includes('autosave')) await new Promise(r => setTimeout(r, w.delay));
        if (w.fail && url.includes('autosave')) throw new Error('network');
        let json = {};
        if (url.includes('autosave')) {
            const ids = {}, minutes = {};
            for (const [k, v] of Object.entries(body)) {
                const m = k.match(/^entries\[(\w+)\]\[issue\]$/);
                if (m && v) { ids[m[1]] = Number(body[`entries[${m[1]}][id]`]) || w.nextId++; minutes[m[1]] = 60; }
            }
            json = {ok: true, savedAt: '10:00:00', ids, minutes, statuses: {}, rowErrors: {}, dayErrors: []};
        } else if (url.includes('panels')) {
            return {ok: true, text: async () => '<p>panels</p>'};
        } else if (url.includes('/timer/start')) {
            w.timer = {running: true, entryId: body.entryId, issue: 'ABC-1', date: '2026-10-20', baseSeconds: 3600, elapsedSeconds: 0, stale: null, startedAt: 'x'};
            json = w.timer;
        } else if (url.includes('/timer/stop')) {
            const id = w.timer.entryId; w.timer = {running: false}; json = {running: false, stopped: {entryId: id, minutes: 75, date: '2026-10-20'}};
        } else if (url.includes('/timer')) { json = w.timer; }
        return {ok: true, status: 200, json: async () => json, text: async () => JSON.stringify(json)};
    };
    w.navigator.sendBeacon = (url, fd) => { w.calls.push({url, method: 'BEACON', body: Object.fromEntries(fd.entries())}); return true; };
    w.alert = (m) => { w.lastAlert = m; };
    for (const s of [...scripts('base.html.twig'), ...scripts('day/show.html.twig')]) w.eval(s);
    return w;
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
let fails = 0;
const check = (name, actual, expected) => {
    const a = JSON.stringify(actual), e = JSON.stringify(expected);
    if (a !== e) { fails++; console.log(`FAIL ${name}\n  oczekiwano ${e}\n  jest       ${a}`); }
};
const fire = (w, el, type) => el.dispatchEvent(new w.Event(type, {bubbles: true}));

(async () => {
    // 1. wpisanie nowego wiersza → autozapis po ~1 s, id wraca z serwera, szkic usunięty
    let w = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(50);
    w.document.getElementById('addrow').click();
    const newRow = w.document.querySelector('[data-key="1"]');
    newRow.querySelector('.f-issue').value = 'ABC-2';
    fire(w, newRow.querySelector('.f-issue'), 'input');
    check('szkic zapisany od razu', !!w.localStorage.getItem('worklog-draft-2026-10-20'), true);
    await sleep(1300);
    const saves = w.calls.filter(c => c.url.includes('autosave'));
    check('jeden autozapis', saves.length, 1);
    check('autozapis wysłał nowy ticket', saves[0]?.body['entries[1][issue]'], 'ABC-2');
    check('id nowego wiersza ustawione', newRow.querySelector('.f-id').value, '100');
    check('szkic usunięty po zapisie', w.localStorage.getItem('worklog-draft-2026-10-20'), null);
    check('status zapisano', w.document.getElementById('savestate').textContent.startsWith('✓ zapisano'), true);
    check('panele odświeżone', w.document.getElementById('panels').innerHTML, '<p>panels</p>');

    // 2. serwer nie odpowiada → szkic zostaje, komunikat ostrzegawczy
    w.fail = true;
    const r0 = w.document.querySelector('[data-key="0"]');
    r0.querySelector('.f-desc').value = 'ważna zmiana';
    fire(w, r0.querySelector('.f-desc'), 'input');
    await sleep(1300);
    const draft = w.localStorage.getItem('worklog-draft-2026-10-20');
    check('szkic po awarii zostaje', !!draft && draft.includes('ważna zmiana'), true);
    check('komunikat o awarii', w.document.getElementById('savestate').textContent.startsWith('⚠'), true);

    // 3. zamknięcie karty z niezapisanymi zmianami → próba wysłania (beacon)
    fire(w, w, 'pagehide');
    check('beacon przy zamknięciu', w.calls.some(c => c.method === 'BEACON'), true);

    // 4. ponowne otwarcie strony: w bazie nie ma zmiany → baner, przywrócenie, ponowny zapis
    const w2 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}, {id: 100, issue: 'ABC-2', time: '1h', desc: ''}], {'worklog-draft-2026-10-20': draft});
    await sleep(50);
    check('baner o niezapisanych zmianach', w2.document.getElementById('draft-banner').hidden, false);
    w2.document.getElementById('draft-restore').click();
    check('przywrócono opis', w2.document.querySelector('[data-key="0"] .f-desc').value, 'ważna zmiana');
    check('przywrócono drugi wiersz z id', w2.document.querySelector('[data-key="1"] .f-id').value, '100');
    await sleep(1300);
    const s2 = w2.calls.filter(c => c.url.includes('autosave'));
    check('po przywróceniu zapis do bazy', s2.length === 1 && s2[0].body['entries[0][desc]'] === 'ważna zmiana', true);
    check('szkic usunięty po udanym zapisie', w2.localStorage.getItem('worklog-draft-2026-10-20'), null);

    // 5. szkic identyczny z bazą → bez banera
    const w3 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(30);
    const same = w3.localStorage.getItem('worklog-draft-2026-10-20');
    check('brak szkicu na czystej stronie', same, null);

    // 6. stoper: ▶ startuje dla id wiersza, ⏸ zatrzymuje i wpisuje zmierzony czas
    const w4 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(50);
    w4.document.getElementById('addrow').click();
    w4.document.getElementById('addrow').click();
    const tr = w4.document.querySelector('[data-key="0"]');
    tr.querySelector('.tbtn').click();
    await sleep(100);
    const start = w4.calls.find(c => c.url.includes('/timer/start'));
    check('start stopera z id wpisu', start?.body, {entryId: 7});
    check('wiersz oznaczony jako działający', tr.classList.contains('running'), true);
    check('pole czasu tylko do odczytu', tr.querySelector('.f-time').readOnly, true);
    check('pasek stopera widoczny', w4.document.getElementById('timerbar').hidden, false);
    await sleep(1100);
    check('licznik na żywo', tr.querySelector('.live').textContent.startsWith('⏱ 1:00:0'), true);
    tr.querySelector('.tbtn').click();
    await sleep(100);
    check('po zatrzymaniu czas z bazy', [tr.querySelector('.f-time').value, tr.querySelector('.f-orig').value], ['1h 15m', '1h 15m']);
    check('po zatrzymaniu znowu ▶', [tr.classList.contains('running'), tr.querySelector('.f-time').readOnly], [false, false]);

    // 7. ▶ na nowym, niezapisanym wierszu → najpierw zapis, potem start z nowym id
    const nr = w4.document.querySelector('[data-key="1"]');
    nr.querySelector('.f-issue').value = 'XYZ-5';
    fire(w4, nr.querySelector('.f-issue'), 'input');
    nr.querySelector('.tbtn').click();
    await sleep(200);
    const starts = w4.calls.filter(c => c.url.includes('/timer/start'));
    check('start na nowym wierszu po zapisie', starts.at(-1)?.body, {entryId: 100});

    // 8. ▶ bez numeru ticketu → komunikat, brak startu
    const er = w4.document.querySelector('[data-key="2"]');
    const before = w4.calls.length;
    er.querySelector('.tbtn').click();
    await sleep(50);
    check('bez ticketu – ostrzeżenie', [w4.lastAlert, w4.calls.slice(before).some(c => c.url.includes('/timer/start'))], ['Najpierw wpisz numer ticketu.', false]);

    // 9. zaznaczenie ✕ nie usuwa w autozapisie (wysyła pole, serwer je pomija) i nie wywołuje fałszywego banera
    const w5 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(30);
    const del = w5.document.querySelector('[data-key="0"] .f-del');
    del.checked = true; fire(w5, del, 'change');
    check('✕ sam nie uruchamia autozapisu przez change', w5.calls.filter(c => c.url.includes('autosave')).length, 0);

    // 10. „Zapisz i wyślij” zaraz po wpisaniu nowego wiersza → bez równoległego autozapisu i beacona (inaczej wiersz zapisałby się dwa razy)
    const w6 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(30);
    w6.document.getElementById('addrow').click();
    const n6 = w6.document.querySelector('[data-key="1"]');
    n6.querySelector('.f-issue').value = 'NEW-1';
    fire(w6, n6.querySelector('.f-issue'), 'input');
    w6.document.getElementById('dayform').requestSubmit(w6.document.getElementById('syncbtn'));
    await sleep(100);
    fire(w6, w6, 'pagehide');
    await sleep(1300);
    check('submit od razu – brak autozapisu', w6.calls.filter(c => c.url.includes('autosave')).length, 0);
    check('submit od razu – brak beacona', w6.calls.some(c => c.method === 'BEACON'), false);
    check('submit od razu – formularz wysłany z akcją', w6.submits.map(s => [s.action, s['entries[1][issue]'], s['entries[1][id]']]), [['sync', 'NEW-1', '0']]);

    // 11. submit w trakcie autozapisu → czeka na jego koniec i wysyła formularz już z id nowego wiersza
    const w7 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(30);
    w7.delay = 500;
    w7.document.getElementById('addrow').click();
    const n7 = w7.document.querySelector('[data-key="1"]');
    n7.querySelector('.f-issue').value = 'NEW-2';
    fire(w7, n7.querySelector('.f-issue'), 'input');
    await sleep(1000); // autozapis wystartował (po 0,9 s) i jeszcze trwa
    w7.document.getElementById('dayform').requestSubmit(w7.document.getElementById('syncbtn'));
    await sleep(50);
    check('submit w trakcie autozapisu – jeszcze czeka', w7.submits.length, 0);
    await sleep(700);
    fire(w7, w7, 'pagehide');
    check('submit w trakcie autozapisu – jeden autozapis', w7.calls.filter(c => c.url.includes('autosave')).length, 1);
    check('submit w trakcie autozapisu – formularz z id', w7.submits.map(s => [s.action, s['entries[1][id]']]), [['sync', '100']]);
    check('submit w trakcie autozapisu – brak beacona', w7.calls.some(c => c.method === 'BEACON'), false);

    // 12. wszystko zapisane → zamknięcie karty nie wysyła beacona
    const w8 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(30);
    const d8 = w8.document.querySelector('[data-key="0"] .f-desc');
    d8.value = 'b'; fire(w8, d8, 'input');
    await sleep(1300);
    fire(w8, w8, 'pagehide');
    check('po udanym zapisie brak beacona', w8.calls.some(c => c.method === 'BEACON'), false);

    // 13. praca w przerwie obiadowej: podpowiedzi (w wybranej formie) przychodzą z atrybutów data-help-*
    const w9 = page([{id: 7, issue: 'ABC-1', time: '1h', desc: 'a'}]);
    await sleep(30);
    const lw9 = w9.document.getElementById('lunch_worked');
    lw9.checked = true; fire(w9, lw9, 'change');
    check('obiad zaznaczony', [w9.document.getElementById('lunch-help').textContent, w9.document.getElementById('break').value, w9.document.getElementById('break-label').textContent], ['POMOC-WŁ', '1h', 'Przerwa wzięta (łącznie)']);
    lw9.checked = false; fire(w9, lw9, 'change');
    check('obiad odznaczony', [w9.document.getElementById('lunch-help').textContent, w9.document.getElementById('break').value], ['POMOC-WYŁ', '']);

    console.log(fails ? `\n${fails} błędów` : 'OK – wszystkie testy przeglądarkowe przeszły');
    process.exit(fails ? 1 : 0);
})();
