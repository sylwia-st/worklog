// Test podglądu motywu w Ustawieniach (symulowana przeglądarka): wybór z listy, kolory własne, „Wczytaj”.
const { JSDOM } = require('jsdom');
const fs = require('fs');

const PALETTES = {light: {bg: '#f6f5f2', ink: '#1d1d1b'}, dark: {bg: '#161716', ink: '#ecebe7'}, night: {bg: '#0f1724', ink: '#e6edf5'}};
const src = fs.readFileSync(require('path').join(__dirname, '../../templates/settings.html.twig'), 'utf8');
const script = [...src.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]).join('\n')
    .replace('{{ palettes|json_encode|raw }}', JSON.stringify(PALETTES))
    .replace(/\{\{ constant\('App\\\\Domain\\\\Theme::SERIES_(LIGHT|DARK)'\)\|json_encode\|raw \}\}/g, (_, m) => JSON.stringify(m === 'LIGHT' ? ['#light1'] : ['#dark1']));

let fails = 0;
const check = (name, actual, expected) => {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) { fails++; console.log(`FAIL ${name}:\n  oczekiwano ${JSON.stringify(expected)}\n  jest       ${JSON.stringify(actual)}`); }
};

const dom = new JSDOM(`<!doctype html><body>
 <select id="theme"><option value="auto">A</option><option value="light">J</option><option value="night">N</option><option value="custom">W</option></select>
 <div id="field-theme_custom" hidden>
  <input type="color" data-var="bg" value="#ffffff"><input type="color" data-var="ink" value="#000000">
  <select id="theme-base"><option value="light"></option><option value="night"></option></select><button type="button" id="theme-load"></button>
 </div></body>`, {runScripts: 'outside-only'});
const w = dom.window, d = w.document;
w.matchMedia = () => ({matches: true});   // system w trybie ciemnym
w.IntersectionObserver = class { observe() {} };
w.eval(script);

const v = (name) => d.documentElement.style.getPropertyValue('--' + name);
const pick = (value) => { d.getElementById('theme').value = value; d.getElementById('theme').dispatchEvent(new w.Event('change')); };
const field = d.getElementById('field-theme_custom'), bg = d.querySelector('[data-var="bg"]');

pick('night');
check('Nocny: podgląd od razu, bez pól kolorów', [v('bg'), v('ink'), field.hidden], ['#0f1724', '#e6edf5', true]);
check('Nocny: ciemne kolory ticketów', v('s1'), '#dark1');
pick('auto');
check('Automatyczny przy ciemnym systemie = ciemny', v('bg'), '#161716');
pick('light');
check('Jasny: jasne kolory ticketów', v('s1'), '#light1');
pick('custom');
check('Własny: kolory z pól, pola widoczne', [v('bg'), v('ink'), field.hidden], ['#ffffff', '#000000', false]);
bg.value = '#202020'; bg.dispatchEvent(new w.Event('input'));
check('Własny: zmiana koloru widoczna od razu', v('bg'), '#202020');
d.getElementById('theme-base').value = 'night'; d.getElementById('theme-load').click();
check('Wczytaj kolory z motywu', [bg.value, d.querySelector('[data-var="ink"]').value, v('bg')], ['#0f1724', '#e6edf5', '#0f1724']);
pick('light');
bg.value = '#123456'; bg.dispatchEvent(new w.Event('input'));
check('zmiana koloru przy innym motywie nie zmienia podglądu', v('bg'), '#f6f5f2');

console.log(fails ? `\n${fails} błędów` : 'OK – testy podglądu motywu przeszły');
process.exit(fails ? 1 : 0);
