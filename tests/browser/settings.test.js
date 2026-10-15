// Test podglądu motywu w Ustawieniach (symulowana przeglądarka): kliknięty motyw od razu przemalowuje stronę.
const { JSDOM } = require('jsdom');
const fs = require('fs');

const PALETTES = {aurora: {bg: '#0a0b10', accent: '#8b7dff'}, ocean: {bg: '#050e12', accent: '#2dd4bf'}};
const src = fs.readFileSync(require('path').join(__dirname, '../../templates/settings.html.twig'), 'utf8');
const script = [...src.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]).join('\n')
    .replace('{{ palettes|json_encode|raw }}', JSON.stringify(PALETTES));

let fails = 0;
const check = (name, actual, expected) => {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) { fails++; console.log(`FAIL ${name}:\n  oczekiwano ${JSON.stringify(expected)}\n  jest       ${JSON.stringify(actual)}`); }
};

const dom = new JSDOM(`<!doctype html><body>
 <input type="radio" name="theme" value="aurora" checked><input type="radio" name="theme" value="ocean"></body>`, {runScripts: 'outside-only'});
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

console.log(fails ? `\n${fails} błędów` : 'OK – testy podglądu motywu przeszły');
process.exit(fails ? 1 : 0);
