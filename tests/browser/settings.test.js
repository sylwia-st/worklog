// Test podglądu motywu w Ustawieniach (symulowana przeglądarka): kliknięty motyw od razu przemalowuje stronę.
const { JSDOM } = require('jsdom');
const fs = require('fs');

const PALETTES = {aurora: {bg: '#0a0b10', panel: '#12141b', ink: '#eceef4', muted: '#8d93a5', accent: '#8b7dff', 'accent-ink': '#0c0a1f'},
    ocean: {bg: '#050e12', panel: '#0b171c', ink: '#e8f3f5', muted: '#86a0a8', accent: '#2dd4bf', 'accent-ink': '#04201d'}};
const STATES = {dark: {ok: '#4ade80'}, light: {ok: '#2d794d'}};
const SERIES = {dark: ['#dark1'], light: ['#light1']};
const src = fs.readFileSync(require('path').join(__dirname, '../../templates/settings.html.twig'), 'utf8');
const script = [...src.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]).join('\n')
    .replace('{{ palettes|json_encode|raw }}', JSON.stringify(PALETTES))
    .replace('{{ states|json_encode|raw }}', JSON.stringify(STATES))
    .replace('{{ series|json_encode|raw }}', JSON.stringify(SERIES))
    .replace('{{ shades|json_encode|raw }}', JSON.stringify({dark: 'cień-ciemny', light: 'cień-jasny'}));

let fails = 0;
const check = (name, actual, expected) => {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) { fails++; console.log(`FAIL ${name}:\n  oczekiwano ${JSON.stringify(expected)}\n  jest       ${JSON.stringify(actual)}`); }
};

const dom = new JSDOM(`<!doctype html><body>
 <input type="radio" name="theme" value="aurora" checked><input type="radio" name="theme" value="ocean">
 <label class="theme-card" data-id="custom"><input type="radio" name="theme" value="custom"></label>
 <div id="field-theme_custom" hidden>
  <input type="color" data-var="bg" value="#0a0b10"><input type="color" data-var="panel" value="#12141b"><input type="color" data-var="ink" value="#eceef4">
  <input type="color" data-var="muted" value="#8d93a5"><input type="color" data-var="accent" value="#8b7dff"><input type="color" data-var="accent-2" value="#6aaeff">
  <input type="color" data-var="accent-ink" value="#0c0a1f">
  <p id="contrast-warn" hidden></p>
  <select id="theme-base"><option value="aurora"></option><option value="ocean"></option></select><button type="button" id="theme-load"></button>
 </div></body>`, {runScripts: 'outside-only'});
const w = dom.window, d = w.document;
w.IntersectionObserver = class { observe() {} };
w.eval(script);

const v = (name) => d.documentElement.style.getPropertyValue('--' + name);
const pick = (value) => { const r = d.querySelector(`input[value="${value}"]`); r.checked = true; r.dispatchEvent(new w.Event('change')); };

check('przed wyborem strona ma kolory z serwera', v('bg'), '');
pick('ocean');
check('Głębia: podgląd od razu', [v('bg'), v('accent')], ['#050e12', '#2dd4bf']);
pick('aurora');
check('powrót do Aurory', [v('bg'), v('accent')], ['#0a0b10', '#8b7dff']);

const field = d.getElementById('field-theme_custom'), warn = d.getElementById('contrast-warn');
const color = (name, value) => { const i = d.querySelector(`[data-var="${name}"]`); i.value = value; i.dispatchEvent(new w.Event('input')); };
check('pola kolorów schowane przy motywie wbudowanym', field.hidden, true);
pick('custom');
check('Własny: pola kolorów widoczne, podgląd z pól', [field.hidden, v('bg'), d.documentElement.style.getPropertyValue('color-scheme'), v('ok'), v('s1')], [false, '#0a0b10', 'dark', '#4ade80', '#dark1']);
color('bg', '#ffffff'); color('panel', '#f4f4f4');
check('Własny z jasnym tłem: jasne kontrolki, stany, tickety i cienie', [v('bg'), d.documentElement.style.getPropertyValue('color-scheme'), v('ok'), v('s1'), v('shade')], ['#ffffff', 'light', '#2d794d', '#light1', 'cień-jasny']);
check('słaby kontrast: jasny tekst na jasnym tle → ostrzeżenie', [warn.hidden, warn.textContent.startsWith('Słabo czytelne: Tekst na tle paneli')], [false, true]);
color('ink', '#111111'); color('muted', '#555555'); color('accent', '#2f5d50'); color('accent-ink', '#ffffff');
check('dobry kontrast → bez ostrzeżenia', warn.hidden, true);
check('miniatura kafelka Własny', d.querySelector('[data-id="custom"]').style.getPropertyValue('--t-bg'), '#ffffff');
d.getElementById('theme-base').value = 'ocean'; d.getElementById('theme-load').click();
check('Wczytaj kolory z motywu', [d.querySelector('[data-var="bg"]').value, v('bg'), v('accent')], ['#050e12', '#050e12', '#2dd4bf']);
pick('ocean');
color('bg', '#123456');
check('zmiana koloru przy innym motywie nie zmienia podglądu', v('bg'), '#050e12');

console.log(fails ? `\n${fails} błędów` : 'OK – testy podglądu motywu przeszły');
process.exit(fails ? 1 : 0);
