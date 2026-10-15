"""Generuje pixel-art osadników do motywu Osadnicy (public/img/osadnicy-*.svg).

Każdy plik to pasek klatek animacji (klatka obok klatki), rysowany z liter – kolory w COLORS.
Własne rysunki w duchu The Settlers II (nie grafiki z gry). Po zmianie uruchom:

    python3 docs/grafika/osadnicy.py

CSS w templates/base.html.twig (sekcja motywu Osadnicy) zakłada rozmiary klatek z SPRITES – po zmianie rozmiaru popraw też tam.
"""

from pathlib import Path

OUT = Path(__file__).resolve().parent.parent.parent / 'public' / 'img'

COLORS = {
    'k': '#1c1309',  # obrys
    'h': '#6b3a17', 'H': '#4a280f',  # włosy
    's': '#f2c294', 'd': '#c98a5c',  # skóra, cień
    'b': '#5b3a17',  # pasek
    'f': '#4a2c12',  # sandały
    'w': '#d9a752', 'W': '#a5762c',  # tunika tragarza
    'c': '#2c5bc4', 'C': '#1d3f8f',  # kolor gracza (lamówka, chorągiewka)
    'g': '#6a86a8', 'G': '#4a6280',  # tunika drwala
    'r': '#b8352a', 'R': '#842116',  # tunika budowniczego
    'a': '#d5d8dc', 'A': '#8a8f96',  # ostrze, metal
    'm': '#8a5a2a', 'M': '#5e3b18',  # drewno
    'y': '#e0b26c',  # słoje na przekroju
    'q': '#b3afa5', 'Q': '#77736b',  # kamień
    'n': '#e2c78a', 'N': '#b39555',  # worek
    'e': '#3f8a2a', 'E': '#2a6a1c', 'x': '#6fbf45',  # liście
}

# --- części postaci (szerokość 16, postać patrzy w prawo) ---------------------------------------------------------

HEAD = [
    '......kkkk......',
    '.....khhhhkk....',
    '....khhhhhhhk...',
    '....khHhhssssk..',
    '....khHhssksk...',
    '....khhsssssk...',
    '.....kssssdk....',
    '......kddk......',
]


def torso(t, T, band='', arm=()):
    """Tunika (t – kolor, T – cień), opcjonalna lamówka w kolorze gracza i ręka: lista (wiersz, kolumna, znak)."""
    rows = [
        f'.....k{t*4}k.....',
        f'....k{t*6}k....',
        f'....k{T}{t*5}k....',
        '....kbbbbbbk....',
        f'....k{T}{t*5}k....',
        f'....k{T}{t*4}{T}k....',
    ]
    if band:
        rows[5] = f'....k{band*6}k....'
    rows = [list(r) for r in rows]
    for r, col, ch in arm:
        rows[r][col] = ch
    return [''.join(r) for r in rows]


HEM = '.....kkkkkk.....'

LEGS_STRIDE = [  # krok: dalsza noga z tyłu, bliższa z przodu
    '.....kdk.ksk....',
    '....kdk...ksk...',
    '....kdk...ksk...',
    '...kffk...kffk..',
    '...kkk.....kkk..',
]
LEGS_STRIDE_2 = [  # krok z drugą nogą z przodu
    '.....ksk.kdk....',
    '....ksk...kdk...',
    '....ksk...kdk...',
    '...kffk...kffk..',
    '...kkk.....kkk..',
]
LEGS_PASS = [  # nogi razem (ciało o piksel wyżej)
    '......kssk......',
    '......ksdk......',
    '......ksdk......',
    '......ksdk......',
    '......kfffk.....',
    '......kkkkk.....',
]
LEGS_STAND = [
    '......ksdk......',
    '......ksdk......',
    '......ksdk......',
    '......kfffk.....',
    '......kkkkk.....',
]


def figure(height, body, legs, lift=0):
    """Składa postać w klatce o wysokości height: głowa + tułów + rąbek + nogi, dosunięta do dołu (lift – o ile wyżej)."""
    rows = HEAD + body + [HEM] + legs
    pad = height - len(rows) - lift
    return ['.' * 16] * pad + rows + ['.' * 16] * lift


def overlay(base, top, dx=0, dy=0):
    """Nakłada rysunek top na base (kropka = przezroczyste)."""
    out = [list(r) for r in base]
    for y, row in enumerate(top):
        for x, ch in enumerate(row):
            if ch != '.' and 0 <= y + dy < len(out) and 0 <= x + dx < len(out[0]):
                out[y + dy][x + dx] = ch
    return [''.join(r) for r in out]


H = 24  # wysokość klatki postaci

# ręce (wiersz, kolumna w tułowiu): rękaw, potem skóra; kołyszą się przy chodzie
def arm(rows, sleeve):
    return [(r, c, sleeve if i == 0 else 's') for i, (r, c) in enumerate(rows)]


def arm_mid(t):
    return arm([(1, 7), (2, 7), (3, 7), (4, 7)], t)


def arm_fwd(t):
    return arm([(1, 8), (2, 9), (3, 9), (4, 10)], t)


def arm_back(t):
    return arm([(1, 6), (2, 5), (3, 5), (4, 4)], t)


# tragarz bez towaru (z powrotem); z towarem – niżej, po towarach
carrier_empty = [
    figure(H, torso('w', 'W', 'c', arm_back('W')), LEGS_STRIDE),
    figure(H, torso('w', 'W', 'c', arm_mid('W')), LEGS_PASS),
    figure(H, torso('w', 'W', 'c', arm_fwd('W')), LEGS_STRIDE_2),
    figure(H, torso('w', 'W', 'c', arm_mid('W')), LEGS_PASS),
]

# postać stojąca (głowa w wierszach 4–11, tułów 12–17) z ręką i narzędziem dorysowanymi w całej klatce
STAND_PAD = H - (len(HEAD) + 6 + 1 + len(LEGS_STAND))


def standing(t, T, band=''):
    return figure(H, torso(t, T, band), LEGS_STAND)


def draw(frame, pixels):
    out = [list(r) for r in frame]
    for r, c, ch in pixels:
        out[r][c] = ch
    return [''.join(r) for r in out]


# drwal: siekiera uniesiona nad ramieniem → uderzenie przed sobą (w drzewo po prawej)
woodcutter = [
    draw(standing('g', 'G'), [
        (12, 10, 'G'), (11, 11, 's'), (11, 12, 's'), (10, 13, 's'), (9, 14, 's'),  # ręka w górę
        (8, 14, 'm'), (7, 14, 'm'), (6, 14, 'M'), (5, 14, 'm'), (4, 14, 'm'),  # trzonek
        (3, 12, 'k'), (3, 13, 'a'), (4, 12, 'A'), (4, 13, 'a'), (5, 12, 'k'), (5, 13, 'k'), (2, 13, 'k'), (3, 14, 'k'),  # ostrze
    ]),
    draw(standing('g', 'G'), [
        (13, 10, 'G'), (13, 11, 's'), (14, 12, 's'),  # ręka przed siebie
        (14, 13, 'm'), (14, 14, 'M'),  # trzonek
        (13, 15, 'a'), (14, 15, 'a'), (15, 15, 'A'), (12, 15, 'k'), (16, 15, 'k'),  # ostrze
    ]),
]

# budowniczy: młotek w górze → w dół
builder = [
    draw(standing('r', 'R'), [
        (12, 10, 'R'), (11, 11, 's'), (11, 12, 's'), (10, 13, 's'), (9, 14, 's'),  # ręka w górę
        (8, 14, 'm'), (7, 14, 'm'), (6, 13, 'A'), (6, 14, 'A'), (6, 15, 'A'), (5, 13, 'A'), (5, 14, 'A'), (5, 15, 'A'), (4, 13, 'k'), (4, 14, 'k'), (4, 15, 'k'),  # młotek
    ]),
    draw(standing('r', 'R'), [
        (13, 10, 'R'), (13, 11, 's'), (14, 12, 's'),  # ręka w dół
        (14, 13, 'm'), (14, 14, 'm'), (13, 15, 'A'), (14, 15, 'A'), (15, 15, 'A'), (12, 15, 'k'), (16, 15, 'k'),  # młotek
    ]),
]

# osadnik machający ręką (puste listy)
waver = [
    draw(standing('w', 'W', 'c'), [(12, 10, 'W'), (11, 11, 's'), (11, 12, 's'), (10, 13, 's'), (9, 14, 's'), (8, 14, 's'), (8, 15, 's')]),
    draw(standing('w', 'W', 'c'), [(12, 10, 'W'), (11, 11, 's'), (11, 12, 's'), (10, 13, 's'), (9, 13, 's'), (8, 13, 's'), (7, 13, 's')]),
]

# towary niesione przez tragarzy (8×6)
log = [[
    '........',
    '.kkkkkk.',
    'kymmmmMk',
    'kyMmmmMk',
    '.kkkkkk.',
    '........',
]]
stone = [[
    '........',
    '..kkkk..',
    '.kqqqQk.',
    'kqqqQQQk',
    '.kkkkkk.',
    '........',
]]
sack = [[
    '...kk...',
    '..knnk..',
    '.knnnNk.',
    'knnnnNNk',
    '.kkkkkk.',
    '........',
]]

# chorągiewka na skrzyżowaniu dróg (8×14, łopocze)
flag = [
    [
        '.mcccc..',
        '.mcccCc.',
        '.mccCcc.',
        '.mcccc..',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.M......',
        'kMk.....',
        '........',
    ],
    [
        '.mccc...',
        '.mccCcc.',
        '.mcCccc.',
        '.mcccc..',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.m......',
        '.M......',
        'kMk.....',
        '........',
    ],
]

# drzewo liściaste (16×24)
tree = [[
    '.....kkkkk......',
    '...kkexxeekk....',
    '..kexxxeeeeek...',
    '.kexxeeeeeEeek..',
    '.kexeeeeeeEEek..',
    'kexxeeeeeeeEEek.',
    'keeeeeeeEeeEEek.',
    'keeeeeeEEeeeEek.',
    'keeEeeeeeeeeEEk.',
    '.keEEeeeeeeEEk..',
    '.kkeEEeeeEEEek..',
    '..kkEEEEEEEkk...',
    '....kkmMkkk.....',
    '......mMk.......',
    '......mMk.......',
    '......mMk.......',
    '......mMk.......',
    '......mMk.......',
    '.....kmMMk......',
    '....kmmMMMk.....',
    '................',
    '................',
    '................',
    '................',
]]

# plac budowy: rusztowanie z desek (24×20)
site = [[
    '........................',
    '...........kk...........',
    '..........kmMk..........',
    '.........kmk.Mk.........',
    '........kmk...Mk........',
    '.......kmk.....Mk.......',
    '......kmk.......Mk......',
    '.....kmmmmmmmmmmmMk.....',
    '.....km..........Mk.....',
    '.....km..........Mk.....',
    '.....kmmmmmmmmmmmMk.....',
    '.....km..........Mk.....',
    '.....km..........Mk.....',
    '.....km..........Mk.....',
    '..kkkkkkkkkkkkkkkkkkkk..',
    '.kyMmmyMmmyMmmyMmmyMmk..',
    '.kkkkkkkkkkkkkkkkkkkkk..',
    '.kqqQ.kqQQk..kyMmmMk....',
    'kqqqQQkqqQQk.kkkkkkk....',
    'kkkkkkkkkkkk............',
]]



def carrying(ware):
    """Tragarz niesie towar przed sobą obiema rękami; towar podskakuje razem z krokiem."""
    frames = []
    for legs in (LEGS_STRIDE, LEGS_PASS, LEGS_STRIDE_2, LEGS_PASS):
        frame = figure(H, torso('w', 'W', 'c', arm_fwd('W')), legs)
        top = H - (len(HEAD) + 6 + 1 + len(legs)) + len(HEAD)  # pierwszy wiersz tułowia
        frames.append(overlay(frame, ware[0], 8, top + 1))
    return frames


SPRITES = {
    'tragarz-kloda': carrying(log),
    'tragarz-kamien': carrying(stone),
    'tragarz-worek': carrying(sack),
    'tragarz-pusty': carrier_empty,
    'drwal': woodcutter,
    'budowniczy': builder,
    'machajacy': waver,
    'choragiewka': flag,
    'drzewo': tree,
    'budowa': site,
}


def svg(frames):
    h, w = len(frames[0]), len(frames[0][0])
    rects = []
    for i, frame in enumerate(frames):
        assert len(frame) == h and all(len(r) == w for r in frame), f'klatka {i}: zły rozmiar'
        for y, row in enumerate(frame):
            x = 0
            while x < w:
                ch = row[x]
                if ch == '.':
                    x += 1
                    continue
                run = x
                while run < w and row[run] == ch:
                    run += 1
                rects.append(f'<rect x="{i * w + x}" y="{y}" width="{run - x}" height="1" fill="{COLORS[ch]}"/>')
                x = run
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w * len(frames)} {h}" width="{w * len(frames)}" height="{h}" '
            f'shape-rendering="crispEdges">{"".join(rects)}</svg>\n')


if __name__ == '__main__':
    OUT.mkdir(parents=True, exist_ok=True)
    for name, frames in SPRITES.items():
        (OUT / f'osadnicy-{name}.svg').write_text(svg(frames))
        print(f'osadnicy-{name}.svg  {len(frames[0][0])}×{len(frames[0])} × {len(frames)} klatek')
