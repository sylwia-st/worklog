"""Generuje grafiki do motywu Osadnicy (public/img/osadnicy-*): postaci, budynki, drzewa i tekstury trawy i żwiru.

Własne rysunki w stylu The Settlers II (małe postaci bez obrysu, niebieskie koszule w kolorze gracza, żółtozielona trawa
z kwiatkami, żwirowe drogi, niebieskie chorągiewki) – nie grafiki z gry. Postaci rysowane z liter (kolory w COLORS),
każdy plik SVG to pasek klatek animacji; trawa i żwir to kafle PNG losowane z ziarna (zawsze takie same). Po zmianie:

    python3 docs/grafika/osadnicy.py

CSS w templates/base.html.twig (sekcja motywu Osadnicy) zakłada rozmiary z tego pliku (×2) – po zmianie popraw też tam.
"""

import random
import struct
import zlib
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent.parent / 'public' / 'img'

COLORS = {
    'h': '#6b3e1c', 'H': '#4a2a12',  # włosy
    's': '#ecb98e', 'S': '#c48a62', 'e': '#2a1a0e',  # skóra, cień, oczy
    'b': '#2a78d8', 'B': '#1a4c9a', 'l': '#5ab4f0',  # koszula w kolorze gracza
    'p': '#efe6d4', 'P': '#b8ad98',  # jasne nogi
    'k': '#4a3420',  # buty
    'm': '#9a6a34', 'M': '#6a4420', 'y': '#e0b26c',  # drewno
    'q': '#c4c0b4', 'Q': '#86827a',  # kamień
    'n': '#e8d8a8', 'N': '#b8a474',  # worek
    'a': '#dfe3e8', 'A': '#8a9098',  # metal
    'g': '#3f7a1c', 'G': '#2a5a12', 'x': '#6aa32a', 'X': '#9cc83e',  # liście
    'w': '#a8743c', 'W': '#7a5028', 'v': '#c99256',  # ściana z desek
    'r': '#8a5a30', 'R': '#5e3c1c', 'o': '#b07a44',  # dach z desek
    'd': '#2e1e10',  # drzwi, okna
    't': '#c0452e', 'T': '#8a2c1c', 'u': '#de6a48',  # dachówka
    'c': '#ece4d0', 'C': '#b9b09c',  # biały mur
    'z': '#e8b840',  # złota gałka
}

# --- osadnik: 9×17, idzie w prawo, widziany z góry pod kątem -----------------------------------------------------

HEAD = [  # duża twarz z wąsem, brązowe włosy
    '..hhhhh..',
    '.hhhhhhH.',
    '.hsssssH.',
    '.hesssesH',
    '..sSeeSs.',
    '..sssss..',
]
TORSO = [  # niebieska koszula, u dołu granat
    '.lbbbbbB.',
    'lbbbbbbBB',
    'lbbbbbbBB',
    'sbbbbbbBs',
    '.BBBBBBB.',
]
LEGS_A = [  # krok: bliższa noga z przodu
    '..ppppP..',
    '.ppp..PP.',
    '.pp...PP.',
    '.pP....PP',
    'kk.....kk',
]
LEGS_B = [  # krok: dalsza noga z przodu
    '..pPPPp..',
    '.PPP..pp.',
    '.PP...pp.',
    '.PP....pp',
    'kk.....kk',
]
LEGS_PASS = [  # nogi razem, ciało o piksel wyżej
    '..ppppP..',
    '..pp.PP..',
    '..pp.PP..',
    '..pp.PP..',
    '..pP.PP..',
    '..kk.kk..',
]
LEGS_STAND = [
    '..ppppP..',
    '..pp.PP..',
    '..pp.PP..',
    '..pP.PP..',
    '..kk.kk..',
]
W, H = 9, 17


def man(legs, torso=TORSO):
    rows = HEAD + torso + legs
    return ['.' * W] * (H - len(rows)) + rows


def put(frame, pixels):
    """pixels: (wiersz, kolumna, znak) – liczone od góry klatki."""
    out = [list(r) for r in frame]
    for r, c, ch in pixels:
        if 0 <= r < len(out) and 0 <= c < len(out[0]):
            out[r][c] = ch
    return [''.join(r) for r in out]


def overlay(frame, art, dx, dy):
    return put(frame, [(dy + y, dx + x, ch) for y, row in enumerate(art) for x, ch in enumerate(row) if ch != '.'])


def top(legs):
    """Pierwszy wiersz tułowia w klatce z danymi nogami."""
    return H - len(TORSO) - len(legs)


def swing(legs, front):
    """Tragarz bez towaru: ręce kołyszą się przy chodzie."""
    t = top(legs)
    f = man(legs, put(TORSO, [(3, 0, 'b'), (3, 8, 'B')]))  # bez dłoni przy bokach
    return put(f, [(t + 2, 8, 's'), (t + 4, 0, 's')] if front else [(t + 4, 8, 's'), (t + 2, 0, 's')])


walking_legs = [LEGS_A, LEGS_PASS, LEGS_B, LEGS_PASS]
carrier_empty = [swing(LEGS_A, True), swing(LEGS_PASS, False), swing(LEGS_B, False), swing(LEGS_PASS, True)]

WARES = {
    'kloda': ['.mmmmy', 'mMmmMy', '.MMMM.'],
    'kamien': ['..qq..', '.qqqQ.', 'qqQQQQ'],
    'worek': ['..nn..', '.nnnN.', 'nnnnNN'],
}


def carrying(ware):
    """Tragarz niesie towar przed sobą obiema rękami; towar podskakuje razem z krokiem."""
    frames = []
    for legs in walking_legs:
        t = top(legs)
        f = overlay(man(legs), ware, 3, t)
        frames.append(put(f, [(t + 2, 3, 's'), (t + 2, 8, 's')]))
    return frames


T = top(LEGS_STAND)
# drwal: siekiera w górze → uderzenie w drzewo po prawej
woodcutter = [
    put(man(LEGS_STAND), [(T, 8, 's'), (T - 1, 8, 'm'), (T - 2, 8, 'm'), (T - 3, 8, 'm'), (T - 4, 7, 'a'), (T - 4, 8, 'a'), (T - 5, 7, 'A'), (T - 5, 8, 'a')]),
    put(man(LEGS_STAND), [(T + 3, 8, 's'), (T + 3, 7, 'B'), (T + 4, 8, 'm'), (T + 5, 8, 'a'), (T + 6, 8, 'A')]),
]
# budowniczy: młotek w górze → w dół
builder = [
    put(man(LEGS_STAND), [(T, 8, 's'), (T - 1, 8, 'm'), (T - 2, 8, 'm'), (T - 3, 7, 'A'), (T - 3, 8, 'A'), (T - 2, 7, 'A')]),
    put(man(LEGS_STAND), [(T + 2, 8, 's'), (T + 3, 8, 'm'), (T + 4, 7, 'A'), (T + 4, 8, 'A')]),
]
# osadnik machający ręką (puste listy)
waver = [
    put(man(LEGS_STAND), [(T + 3, 8, 'B'), (T, 8, 'B'), (T - 1, 8, 's'), (T - 2, 8, 's')]),
    put(man(LEGS_STAND), [(T + 3, 8, 'B'), (T, 8, 'B'), (T - 1, 7, 's'), (T - 2, 7, 's')]),
]

# --- otoczenie --------------------------------------------------------------------------------------------------

# chorągiewka (8×18): niebieska, zwisa z drzewca z gałką, łopocze
FLAG_POLE = ['.MM.....', '.zM.....'] + ['.M......'] * 15 + ['MMm.....']
flag = [
    overlay(FLAG_POLE, ['bbbl..', 'blbbb.', 'bblbbB', 'bbbbBB', 'bBbBB.', 'BBB...'], 2, 2),
    overlay(FLAG_POLE, ['bbl...', 'blbbb.', 'bbblbB', 'bbbbBB', '.bBBBB', '..BB..'], 2, 2),
]

# drzewo liściaste (16×22) – bez obrysu, cień z prawej i u dołu
tree = [[
    '......xxx.......',
    '....xxXXxgg.....',
    '...xXXXxxggg....',
    '..xxXXxxgggGg...',
    '.xxXxxxggggGGg..',
    '.xXXxxgggggGGg..',
    'xxXxxggggggGGGg.',
    'xxxxggxggggGGGg.',
    'gxxgggxxgggGGGG.',
    'ggxggggggGGGGGg.',
    '.gggggggGGGGGG..',
    '.GggggGGGGGGGG..',
    '..GGgGGGGGGGG...',
    '....GGMmGGG.....',
    '......Mm........',
    '......Mm........',
    '......Mm........',
    '......Mm........',
    '.....MMmm.......',
    '................',
    '................',
    '................',
]]

# świerk (10×22)
pine = [[
    '....g.....',
    '....gg....',
    '...xgg....',
    '...xggG...',
    '..xggGG...',
    '...xgG....',
    '..xxggG...',
    '.xxgggGG..',
    '..xggGG...',
    '.xxgggGGG.',
    'xxggggGGGG',
    '..xggGGG..',
    '.xgggGGGG.',
    'xxggggGGGG',
    'gggggGGGGG',
    '...MmM....',
    '....Mm....',
    '....Mm....',
    '..........',
    '..........',
    '..........',
    '..........',
]]

# chata drwala (24×16): ściany z desek, dach z desek, drzwi
hut = [[
    '........................',
    '.......oooooooooo.......',
    '......oorrrrrrrrrR......',
    '.....oorrrrrrrrrrRR.....',
    '....oorrrrrrrrrrrrRR....',
    '...oorrrrrrrrrrrrrrRR...',
    '..oorrrrrrrrrrrrrrrrRR..',
    '.ooRRRRRRRRRRRRRRRRRRRR.',
    '..vwWvwWvwWvwWvwWvwWWW..',
    '..vwWvwWvwWvwWvwWvwWWW..',
    '..vwWvwWvddddwWvwWvWWW..',
    '..vwWvwWvddddwWvwWvWWW..',
    '..vwWvwWvddddwWvwWvWWW..',
    '..vwWvwWvddddwWvwWvWWW..',
    '..WWWWWWWddddWWWWWWWWW..',
    '........................',
]]

# dom z czerwoną dachówką (24×16): biały mur, ciemne okna
house = [[
    '..........uuuuu.........',
    '........uuttttttt.......',
    '......uutttttttttT......',
    '....uuttttttttttttTT....',
    '..uutttttttttttttttTT...',
    '.uTTTTTTTTTTTTTTTTTTTT..',
    '..cccccccccccccccccCCC..',
    '..ccddcccddcccddcccCCC..',
    '..ccddcccddcccddcccCCC..',
    '..cccccccccccccccccCCC..',
    '..ccccccddddcccccccCCC..',
    '..ccccccddddcccccccCCC..',
    '..ccccccddddcccccccCCC..',
    '..CCCCCCddddCCCCCCCCCC..',
    '........................',
    '........................',
]]

# plac budowy (24×16): deski i kamienie, zaczęta rama
site = [[
    '........................',
    '...M..........M.........',
    '...Mm.........Mm........',
    '...MmmmmmmmmmmMm........',
    '...Mm.........Mm........',
    '...Mm.........Mm........',
    '...Mm.........Mm........',
    '...MmmmmmmmmmmMm........',
    '...Mm.........Mm........',
    '...Mm.........Mm..yMmMm.',
    '.qqQ..........Mm.yMmmMm.',
    'qqqQQ.qqQ.....Mm.MMMMMM.',
    '.QQQQ.QQQ...............',
    '........................',
    '........................',
    '........................',
]]

SPRITES = {
    'tragarz-kloda': carrying(WARES['kloda']),
    'tragarz-kamien': carrying(WARES['kamien']),
    'tragarz-worek': carrying(WARES['worek']),
    'tragarz-pusty': carrier_empty,
    'drwal': woodcutter,
    'budowniczy': builder,
    'machajacy': waver,
    'choragiewka': flag,
    'drzewo': tree,
    'swierk': pine,
    'chata': hut,
    'dom': house,
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


# --- tekstury: kafle PNG (bezszwowe), w CSS powiększane ×2 z image-rendering: pixelated --------------------------

def png(path, pixels):
    h, w = len(pixels), len(pixels[0])
    raw = b''.join(b'\x00' + bytes(c for px in row for c in px) for row in pixels)

    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))

    path.write_bytes(b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
                     + chunk(b'IDAT', zlib.compress(raw, 9)) + chunk(b'IEND', b''))


def rgb(hex_):
    return tuple(int(hex_[i:i + 2], 16) for i in (1, 3, 5))


def smooth_noise(size, cell, rnd):
    """Bezszwowy szum o dużych plamach: losowa siatka co `cell` pikseli, interpolowana."""
    n = size // cell
    grid = [[rnd.random() for _ in range(n)] for _ in range(n)]
    out = []
    for y in range(size):
        row = []
        for x in range(size):
            gx, gy = x / cell, y / cell
            x0, y0 = int(gx) % n, int(gy) % n
            x1, y1 = (x0 + 1) % n, (y0 + 1) % n
            fx, fy = gx - int(gx), gy - int(gy)
            fx, fy = fx * fx * (3 - 2 * fx), fy * fy * (3 - 2 * fy)
            upper = grid[y0][x0] * (1 - fx) + grid[y0][x1] * fx
            lower = grid[y1][x0] * (1 - fx) + grid[y1][x1] * fx
            row.append(upper * (1 - fy) + lower * fy)
        out.append(row)
    return out


def texture(size, shades, seed, streak=3, flowers=(), flower_rate=0.0):
    """Ziarnista tekstura z poziomymi smużkami (jak trawa w grze): odcień z plam + losowy rozrzut, kropki kwiatów."""
    rnd = random.Random(seed)
    blobs = smooth_noise(size, 16, rnd)
    pal = [rgb(c) for c in shades]
    px = [[None] * size for _ in range(size)]
    for y in range(size):
        x = 0
        while x < size:
            level = blobs[y][x] * 0.6 + rnd.random() * 0.55 - 0.08
            idx = max(0, min(len(pal) - 1, int(level * len(pal))))
            run = rnd.randint(1, streak)  # smużka kilku pikseli w tym samym odcieniu
            for k in range(run):
                px[y][(x + k) % size] = pal[idx]
            x += run
    for _ in range(int(size * size * flower_rate)):
        x, y = rnd.randrange(size), rnd.randrange(size)
        px[y][x] = rgb(rnd.choice(flowers))
    return px


if __name__ == '__main__':
    OUT.mkdir(parents=True, exist_ok=True)
    for old in OUT.glob('osadnicy-*'):
        old.unlink()
    for name, frames in SPRITES.items():
        (OUT / f'osadnicy-{name}.svg').write_text(svg(frames))
        print(f'osadnicy-{name}.svg  {len(frames[0][0])}×{len(frames[0])} × {len(frames)} klatek')
    grass = texture(64, ['#3a7a1a', '#4a8c22', '#5a9e2a', '#6aae32', '#7cbc3a', '#94c84a'], seed=7,
                    flowers=['#f4e47a', '#fbf3d6', '#f0a050', '#e8c8e0'], flower_rate=0.006)
    png(OUT / 'osadnicy-trawa.png', grass)
    gravel = texture(32, ['#7e6e60', '#9a8a78', '#b0a090', '#c4b4a2', '#d6c8b6'], seed=3, streak=2)
    png(OUT / 'osadnicy-zwir.png', gravel)
    print('osadnicy-trawa.png 64×64, osadnicy-zwir.png 32×32')
