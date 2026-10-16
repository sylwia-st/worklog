"""Generuje grafiki do motywu Osadnicy (public/img/osadnicy-*): postaci, budynki, drzewa i tekstury trawy i żwiru.

Własne rysunki w stylu The Settlers II (małe postaci bez obrysu, niebieskie koszule w kolorze gracza, żółtozielona trawa
z kwiatkami, żwirowe drogi, niebieskie chorągiewki) – nie grafiki z gry. Postaci rysowane z liter (kolory w COLORS),
każdy plik SVG to pasek klatek animacji; trawa, żwir i wnętrze okna to kafle PNG losowane z ziarna (zawsze takie same). Po zmianie:

    python3 docs/grafika/osadnicy.py

CSS w templates/base.html.twig (sekcja motywu Osadnicy) zakłada rozmiary z tego pliku (×2) – po zmianie popraw też tam.
"""

import random
import sys
import struct
import zlib
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent.parent / 'public' / 'img'

sys.dont_write_bytecode = True  # bez __pycache__ w repo
sys.path.insert(0, str(Path(__file__).resolve().parent))
import rzezby  # noqa: E402

COLORS = {
    'h': '#7a4418', 'H': '#4e2a0c', 'j': '#a0622a',  # włosy, cień, połysk
    's': '#e4ac7c', 'S': '#b87a4c', 'i': '#ecebe2', 'e': '#1e1610', 'f': '#6a3412',  # skóra, cień, białko, źrenica, wąsy
    'b': '#1a7ce4', 'B': '#1252c4', 'l': '#2aa0f2', 'E': '#40d4fa', 'D': '#0a2e94',  # koszula w kolorze gracza: światło z lewej
    'p': '#ecd6ac', 'P': '#c0a07a',  # jasne nogi
    'k': '#dcdad0', 'K': '#8e8a80',  # jasnoszare buty
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
    'z': '#e8b840', 'Z': '#a87818',  # złota gałka
}

# --- osadnik: 11×19, idzie w prawo, jak w grze: duża głowa (⅓ wzrostu) z oczami i wąsami, jaskrawa koszula ----------

HEAD = [  # włosy z połyskiem zachodzą na tył głowy; twarz z profilu: dwoje oczu z białkami, wąsy
    '...jjhhh...',
    '..jhhhhhhH.',
    '..hhhssssH.',
    '..hhsieieS.',
    '..hssssssS.',
    '...sSfffS..',
    '....SsssS..',
]
TORSO = [  # koszula: turkusowe światło na lewym barku, granatowy dół; dłonie po bokach
    '...EllbbB..',
    '..EElbbbBB.',
    '.sEllbbbBBs',
    '.sElbbbbBBs',
    '..lbbbbbBB.',
    '..DDDDDDDD.',
]
LEGS_A = [  # krok: bliższa noga z przodu
    '...pppPP...',
    '..ppp..PP..',
    '..pp....PP.',
    '.pP.....PP.',
    '.kkK....kkK',
]
LEGS_B = [  # krok: dalsza noga z przodu
    '...pPPPp...',
    '..PPP..pp..',
    '..PP....pp.',
    '.PP.....pp.',
    '.kkK....kkK',
]
LEGS_PASS = [  # nogi razem, ciało o piksel wyżej
    '...pppPP...',
    '...pp.PP...',
    '...pp.PP...',
    '...pp.PP...',
    '...pP.PP...',
    '..kkK.kkK..',
]
LEGS_STAND = [
    '...pppPP...',
    '...pp.PP...',
    '...pp.PP...',
    '...pP.PP...',
    '..kkK.kkK..',
]
W, H = 11, 19


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


NO_HANDS = put(TORSO, [(2, 1, '.'), (3, 1, '.'), (2, 10, '.'), (3, 10, '.')])


def swing(legs, front):
    """Tragarz bez towaru: ręce kołyszą się przy chodzie."""
    t = top(legs)
    f = man(legs, NO_HANDS)
    return put(f, [(t + 1, 10, 's'), (t + 4, 1, 's')] if front else [(t + 4, 10, 's'), (t + 1, 1, 's')])


walking_legs = [LEGS_A, LEGS_PASS, LEGS_B, LEGS_PASS]
carrier_empty = [swing(LEGS_A, True), swing(LEGS_PASS, False), swing(LEGS_B, False), swing(LEGS_PASS, True)]

WARES = {
    'kloda': ['.mmmmmy', 'mMmmmMy', '.MMMMM.'],
    'kamien': ['..qqq..', '.qqqqQ.', 'qqqQQQQ'],
    'worek': ['..nnn..', '.nnnnN.', 'nnnnnNN'],
}


def carrying(ware):
    """Tragarz niesie towar przed sobą obiema rękami; towar podskakuje razem z krokiem."""
    frames = []
    for legs in walking_legs:
        t = top(legs)
        f = overlay(man(legs, NO_HANDS), ware, 4, t + 1)
        frames.append(put(f, [(t + 3, 4, 's'), (t + 3, 10, 's')]))
    return frames


T = top(LEGS_STAND)
# drwal: siekiera w górze → uderzenie w drzewo po prawej
woodcutter = [
    put(man(LEGS_STAND, NO_HANDS), [(T, 10, 's'), (T - 1, 10, 'm'), (T - 2, 10, 'm'), (T - 3, 10, 'm'), (T - 4, 9, 'a'), (T - 4, 10, 'a'), (T - 5, 9, 'A'), (T - 5, 10, 'a'), (T + 3, 1, 's')]),
    put(man(LEGS_STAND, NO_HANDS), [(T + 3, 10, 's'), (T + 3, 9, 'B'), (T + 4, 10, 'm'), (T + 5, 10, 'a'), (T + 6, 10, 'A'), (T + 3, 1, 's')]),
]
# budowniczy: młotek w górze → w dół
builder = [
    put(man(LEGS_STAND, NO_HANDS), [(T, 10, 's'), (T - 1, 10, 'm'), (T - 2, 10, 'm'), (T - 3, 9, 'A'), (T - 3, 10, 'A'), (T - 2, 9, 'A'), (T + 3, 1, 's')]),
    put(man(LEGS_STAND, NO_HANDS), [(T + 2, 10, 's'), (T + 3, 10, 'm'), (T + 4, 9, 'A'), (T + 4, 10, 'A'), (T + 3, 1, 's')]),
]
# osadnik machający ręką (puste listy)
waver = [
    put(man(LEGS_STAND, NO_HANDS), [(T, 10, 'B'), (T - 1, 10, 's'), (T - 2, 10, 's'), (T + 3, 1, 's')]),
    put(man(LEGS_STAND, NO_HANDS), [(T, 10, 'B'), (T - 1, 9, 's'), (T - 2, 9, 's'), (T + 3, 1, 's')]),
]

# --- otoczenie --------------------------------------------------------------------------------------------------

# chorągiewka (8×18): niebieska, na jasnym drzewcu ze złotą gałką, łopocze
FLAG_POLE = ['.zz.....', '.zZ.....'] + ['.a......', '.A......'] * 7 + ['.a......', 'AAa.....']
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

# --- budynki: jak pre-renderowane w grze – rzut izometryczny (budynek stoi narożnikiem do widza: widać lewą ścianę
# w pełnym świetle, prawą w półcieniu i dach od góry), liczone w potrójnej rozdzielczości i uśredniane (gładkie
# krawędzie), z fakturą tynku, desek i dachówki, ciemnym konturem i cieniem na trawie. PNG z przezroczystością, w CSS 1:1.
# Punkt (x, y, z): oś x biegnie na ekranie w prawo-w dół, z w lewo-w dół, y w górę. Widoczne ściany: x = x1 (prawa)
# i z = z1 (lewa).

SS = 3  # nadpróbkowanie


def _hash(x, y, seed):
    n = (x * 374761393 + y * 668265263 + seed * 2147483647) & 0xFFFFFFFF
    n = ((n ^ (n >> 13)) * 1274126177) & 0xFFFFFFFF
    return ((n ^ (n >> 16)) & 0xFFFF) / 0xFFFF


def vnoise(x, y, cell, seed):
    """Gładki szum wartości (plamy) w pikselach obrazu wynikowego."""
    gx, gy = x / cell, y / cell
    x0, y0 = int(gx // 1), int(gy // 1)
    fx, fy = gx - x0, gy - y0
    fx, fy = fx * fx * (3 - 2 * fx), fy * fy * (3 - 2 * fy)
    top = _hash(x0, y0, seed) * (1 - fx) + _hash(x0 + 1, y0, seed) * fx
    bot = _hash(x0, y0 + 1, seed) * (1 - fx) + _hash(x0 + 1, y0 + 1, seed) * fx
    return top * (1 - fy) + bot * fy


def shade(color, k):
    return tuple(max(0, min(255, round(c * k))) for c in color)


def mix(a, b, t):
    return tuple(round(x + (y - x) * t) for x, y in zip(a, b))


class Scene:
    """Płótno w nadpróbkowaniu; poly(punkty 3D, paint) – paint(u, v, px, py): u, v w płaszczyźnie ściany (0..1),
    px, py – piksel obrazu wynikowego (do szumu). Kolor (r, g, b) albo (r, g, b, alfa)."""

    def __init__(self, w, h, ox, oy, k=1.6):
        self.w, self.h, self.ox, self.oy, self.k = w, h, ox, oy, k
        self.px = [[None] * (w * SS) for _ in range(h * SS)]

    def at(self, x, y, z):
        return (self.ox + (x - z) * self.k) * SS, (self.oy + ((x + z) * 0.5 - y) * self.k) * SS

    def poly(self, pts3d, paint):
        pts = [self.at(*p) for p in pts3d]
        a, b, d = pts[0], pts[1], pts[-1]
        ux, uy, vx, vy = b[0] - a[0], b[1] - a[1], d[0] - a[0], d[1] - a[1]
        det = (ux * vy - uy * vx) or 1e-9
        for y in range(max(0, int(min(q[1] for q in pts))), min(self.h * SS, int(max(q[1] for q in pts)) + 1)):
            cy = y + 0.5
            xs = []
            for i in range(len(pts)):
                (x1, y1), (x2, y2) = pts[i], pts[i - 1]
                if (y1 > cy) != (y2 > cy):
                    xs.append(x1 + (cy - y1) * (x2 - x1) / (y2 - y1))
            xs.sort()
            for j in range(0, len(xs) - 1, 2):
                for x in range(max(0, int(xs[j] + 0.5)), min(self.w * SS, int(xs[j + 1] + 0.5))):
                    rx, ry = x + 0.5 - a[0], cy - a[1]
                    color = paint((rx * vy - ry * vx) / det, (ux * ry - uy * rx) / det, x // SS, y // SS)
                    if color:
                        self.px[y][x] = color

    def box(self, x0, x1, z0, z1, y0, y1, left, right, top=None):
        """Prostopadłościan: prawa ściana (x = x1), lewa (z = z1), opcjonalnie wierzch (paint jak w poly)."""
        self.poly([(x1, y0, z1), (x1, y0, z0), (x1, y1, z0), (x1, y1, z1)], right)
        self.poly([(x0, y0, z1), (x1, y0, z1), (x1, y1, z1), (x0, y1, z1)], left)
        if top:
            self.poly([(x0, y1, z0), (x1, y1, z0), (x1, y1, z1), (x0, y1, z1)], top)

    def image(self, outline=0.55):
        """Uśrednia nadpróbkowanie do RGBA, przyciemnia piksele na brzegu sylwetki (kontur jak w grze)."""
        out = []
        for y in range(self.h):
            row = []
            for x in range(self.w):
                r = g = b = a = 0.0
                for sy in range(SS):
                    for sx in range(SS):
                        c = self.px[y * SS + sy][x * SS + sx]
                        if c:
                            al = c[3] if len(c) == 4 else 1.0
                            r, g, b, a = r + c[0] * al, g + c[1] * al, b + c[2] * al, a + al
                row.append((r / a, g / a, b / a, a / SS / SS) if a else None)
            out.append(row)
        final = []
        for y in range(self.h):
            row = []
            for x in range(self.w):
                c = out[y][x]
                if not c:
                    row.append((0, 0, 0, 0))
                    continue
                solid_c = c[3] > 0.6
                edge = solid_c and any(not (0 <= x + dx < self.w and 0 <= y + dy < self.h) or not out[y + dy][x + dx]
                                       or out[y + dy][x + dx][3] < 0.6 for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)))
                k = outline if edge else 1.0
                row.append((round(c[0] * k), round(c[1] * k), round(c[2] * k), round(c[3] * 255)))
            final.append(row)
        # przycięcie do zawartości (płótno rysujemy z zapasem)
        ys = [y for y in range(self.h) if any(px[3] for px in final[y])]
        xs = [x for x in range(self.w) if any(final[y][x][3] for y in ys)]
        assert ys[0] > 0 and xs[0] > 0 and ys[-1] < self.h - 1 and xs[-1] < self.w - 1, 'budynek nie mieści się na płótnie'
        return [row[xs[0]:xs[-1] + 1] for row in final[ys[0]:ys[-1] + 1]]


SHADOW = (8, 30, 0, 0.38)
LIT, HALF = 1.0, 0.74  # lewa ściana w świetle, prawa w półcieniu


def ground_shadow(sc, x0, x1, z0, z1, length):
    """Cień na trawie w prawo: rzut podstawy przesunięty w stronę +x, −z (światło z lewej)."""
    sc.poly([(x0, 0, z0), (x1, 0, z0), (x1, 0, z1), (x1 + length, 0, z1 - length), (x1 + length, 0, z0 - length), (x0 + length, 0, z0 - length)],
            lambda u, v, px, py: SHADOW)


def stucco(base, seed, dirt=0.0):
    """Tynk: duże plamy + drobne ziarno, u dołu lekko przybrudzony (dirt)."""
    def f(u, v, px, py, wall_v=None):
        k = 0.9 + vnoise(px, py, 6, seed) * 0.14 + (_hash(px, py, seed + 1) - 0.5) * 0.08
        if dirt:
            k -= dirt * max(0, 0.35 - v) / 0.35
        return shade(base, k)
    return f


def planks(base, seed, width=3):
    """Pionowe deski (co width pikseli obrazu): ciemna szczelina, słoje wzdłuż deski, każda deska trochę inna."""
    def f(u, v, px, py):
        board = px // width
        if px % width == 0:
            return shade(base, 0.5)
        k = 0.86 + _hash(board, 0, seed) * 0.24 + (vnoise(px * 4, py * 0.5, 3, seed + board) - 0.5) * 0.16
        return shade(base, k)
    return f


def tiled(base, seed, rows, cols, edge=None):
    """Dachówka mnichówka-mniszka od góry: rzędy równoległe do okapu, w rzędzie przesunięte fale, góra dachówki
    jaśniejsza, spód z cieniem na następnym rzędzie, losowy odcień każdej dachówki; edge – ciemniejsza krawędź."""
    def f(u, v, px, py):
        if edge and (u < edge or u > 1 - edge):
            return shade(base, 0.58)
        r = v * rows
        row, fr = int(r), r % 1
        c = u * cols + (0.5 if row % 2 else 0)
        col, fc = int(c), c % 1
        k = 0.8 + _hash(col, row, seed) * 0.28
        k *= 0.72 + 0.4 * (1 - abs(fc - 0.5) * 2) ** 0.6  # wypukła dachówka: środek jaśniejszy
        if fr < 0.18:
            k *= 0.55  # cień rzędu powyżej
        elif fr > 0.85:
            k *= 1.1
        return shade(base, k + (_hash(px, py, seed) - 0.5) * 0.06)
    return f


ROOF = (238, 150, 52)
WALL = (222, 208, 178)
BASE_RED = (196, 96, 70)


def red_base(seed):
    def f(u, t, px, py):
        if t > 0.9:
            return (140, 60, 40)  # listwa nad cokołem
        return shade(BASE_RED, 0.86 + vnoise(px, py, 4, seed) * 0.2 + (_hash(px, py, seed) - 0.5) * 0.08)
    return f


def block(sc, x0, x1, z0, z1, h, left, right, base=None, base_h=0.0, eave=0.0):
    """Bryła: prawa ściana w półcieniu, lewa w świetle; cokół (base) do wysokości base_h, cień pod okapem."""
    def wrap(paint, k):
        def f(u, v, px, py):
            y = v * h
            c = base(u, y / base_h, px, py) if base and y < base_h else paint(u, v, px, py)
            if eave and y > h - eave:
                c = shade(c, 0.72)
            return shade(c, k)
        return f
    sc.poly([(x1, 0, z1), (x1, 0, z0), (x1, h, z0), (x1, h, z1)], wrap(right, HALF))
    sc.poly([(x0, 0, z1), (x1, 0, z1), (x1, h, z1), (x0, h, z1)], wrap(left, LIT))


def hole_left(sc, x0, x1, y0, y1, z, color=(34, 26, 22), arch=False):
    """Ciemny otwór (okno, drzwi, brama) w lewej ścianie; arch – zaokrąglona góra."""
    def f(u, v, px, py):
        if arch:
            t = (u - 0.5) * 2
            if v > 1 - 0.35 * (1 - (1 - min(1, t * t)) ** 0.5):
                return None
        return shade(color, 0.8 + 0.35 * u)
    sc.poly([(x0, y0, z), (x1, y0, z), (x1, y1, z), (x0, y1, z)], f)


def hole_right(sc, z0, z1, y0, y1, x, color=(30, 24, 20)):
    sc.poly([(x, y0, z1), (x, y0, z0), (x, y1, z0), (x, y1, z1)], lambda u, v, px, py: shade(color, 0.8 + 0.3 * v))


def roof_x(sc, x0, x1, z0, z1, h, rise, tiles_paint, gable_paint, over=1.0):
    """Dach dwuspadowy, kalenica wzdłuż x: tylna połać, szczyt w prawej ścianie, przednia połać (w świetle),
    deska na skraju i gąsiory na kalenicy."""
    zm = (z0 + z1) / 2
    sc.poly([(x0 - over, h, z0 - over), (x1 + over, h, z0 - over), (x1 + over, h + rise, zm), (x0 - over, h + rise, zm)],
            lambda u, v, px, py: shade(tiles_paint(u, v, px, py), 0.6))
    sc.poly([(x1, h, z1), (x1, h, z0), (x1, h + rise, zm)], lambda u, v, px, py: shade(gable_paint(u, v, px, py), HALF))
    sc.poly([(x1 + over, h - 0.7, z1 + over), (x1 + over, h, z1 + over), (x1 + over, h + rise, zm), (x1 + over, h + rise - 0.7, zm)],
            lambda u, v, px, py: (110, 56, 26))
    sc.poly([(x0 - over, h, z1 + over), (x1 + over, h, z1 + over), (x1 + over, h + rise, zm), (x0 - over, h + rise, zm)], tiles_paint)
    sc.poly([(x0 - over, h + rise - 0.4, zm + 0.5), (x1 + over, h + rise - 0.4, zm + 0.5), (x1 + over, h + rise + 0.5, zm), (x0 - over, h + rise + 0.5, zm)],
            lambda u, v, px, py: shade((206, 112, 44), 0.8 + 0.3 * v - ((u * 24) % 1 < 0.15) * 0.25))


def roof_z(sc, x0, x1, z0, z1, h, rise, tiles_paint, gable_paint, over=1.0):
    """Dach dwuspadowy, kalenica wzdłuż z: szczyt w lewej ścianie (w świetle), widoczna prawa połać (półcień)."""
    xm = (x0 + x1) / 2
    sc.poly([(x0 - over, h, z1 + over), (x0 - over, h, z0 - over), (xm, h + rise, z0 - over), (xm, h + rise, z1 + over)],
            lambda u, v, px, py: shade(tiles_paint(u, v, px, py), 0.6))
    sc.poly([(x0, h, z1), (x1, h, z1), (xm, h + rise, z1)], gable_paint)
    sc.poly([(x0 - over, h - 0.7, z1 + over), (x0 - over, h, z1 + over), (xm, h + rise, z1 + over), (xm, h + rise - 0.7, z1 + over)],
            lambda u, v, px, py: (120, 62, 28))
    sc.poly([(x1 + over, h - 0.7, z1 + over), (x1 + over, h, z1 + over), (xm, h + rise, z1 + over), (xm, h + rise - 0.7, z1 + over)],
            lambda u, v, px, py: (96, 48, 22))
    sc.poly([(x1 + over, h, z1 + over), (x1 + over, h, z0 - over), (xm, h + rise, z0 - over), (xm, h + rise, z1 + over)],
            lambda u, v, px, py: shade(tiles_paint(u, v, px, py), 0.82))


def house():
    """Dom jak w grze: piętrowa bryła z kremowego tynku na grubym ceglanym cokole, pomarańczowa dachówka, małe
    ciemne okienka w dwóch rzędach, łukowe drzwi, komin; z prawej parterowa przybudówka z ciemną bramą."""
    sc = Scene(120, 110, 50, 60)
    ground_shadow(sc, 0, 28, 0, 14, 7)
    # piętrowa bryła
    block(sc, 0, 16, 0, 14, 15, stucco(WALL, 31, 0.12), stucco(WALL, 32), red_base(33), 4, 1.2)
    for wx in (2.5, 7, 11.5):
        hole_left(sc, wx, wx + 2, 10, 12.6, 14)
    hole_left(sc, 2.5, 4.5, 5.5, 8, 14)
    hole_left(sc, 7.5, 11, 0, 7, 14, (40, 28, 20), arch=True)  # drzwi
    hole_right(sc, 4, 6, 10, 12.6, 16)
    roof_x(sc, 0, 16, 0, 14, 15, 7, tiled(ROOF, 38, 7, 12, edge=0.01), stucco(WALL, 39))
    brick = lambda u, v, px, py: shade((176, 80, 54), 0.85 + _hash(int(u * 3), int(v * 8), 40) * 0.22 - (((v * 8) % 1) < 0.15) * 0.3)
    sc.box(10, 12, 3, 5, 17, 24, brick, lambda u, v, px, py: shade(brick(u, v, px, py), HALF), lambda u, v, px, py: (54, 30, 22))  # komin
    # przybudówka z bramą
    block(sc, 16, 28, 3, 14, 8.5, stucco(WALL, 41, 0.12), stucco(WALL, 42), red_base(43), 3.5, 1)
    hole_left(sc, 19, 25, 0, 6.8, 14, (22, 16, 14), arch=True)
    hole_right(sc, 6, 8, 4.5, 6.8, 28)
    roof_x(sc, 16, 28, 3, 14, 8.5, 4, tiled(ROOF, 44, 4, 9, edge=0.012), stucco(WALL, 45), over=0.8)
    return sc.image()


def hut():
    """Chata drwala jak w grze: zwarta bryła z brązowych desek na kamiennej podmurówce, dach z ciemnego gontu,
    drzwi w lewej ścianie, okienko w prawej, obok sterta bali."""
    sc = Scene(120, 110, 50, 60)
    wood = (150, 92, 56)
    ground_shadow(sc, 0, 14, 0, 11, 6)
    stone = lambda u, t, px, py: shade((150, 142, 128), 0.8 + _hash(int(u * 9 + (t > 0.5) * 0.5), int(t * 2), 51) * 0.3
                                       - ((u * 9 + (t > 0.5) * 0.5) % 1 < 0.1) * 0.35)
    block(sc, 0, 14, 0, 11, 9, planks(wood, 52), planks(wood, 53), stone, 2.2, 1)
    hole_left(sc, 7.5, 11, 0, 6.5, 11, (30, 20, 14))
    hole_left(sc, 2.5, 4.5, 4, 6.2, 11)
    hole_right(sc, 4, 6, 4, 6.2, 14)
    roof_x(sc, 0, 14, 0, 11, 9, 7, tiled((128, 76, 44), 54, 6, 10, edge=0.012), planks((132, 80, 48), 55), over=1.2)
    # sterta bali przed prawą ścianą: walce leżące wzdłuż z, widać ich czoła
    for i, (lx, ly) in enumerate(((16.5, 1.2), (19, 1.2), (21.5, 1.2), (17.8, 3.4), (20.3, 3.4), (19, 5.6))):
        sc.poly([(lx - 1.2, ly + 1.1, 2), (lx + 1.2, ly + 1.1, 2), (lx + 1.2, ly + 1.1, 9), (lx - 1.2, ly + 1.1, 9)],
                lambda u, v, px, py: shade((126, 80, 46), 0.8 + 0.3 * u))
        def end(u, v, px, py, i=i):
            r = ((u - 0.5) ** 2 + (v - 0.5) ** 2) ** 0.5 * 2
            if r > 1:
                return None
            return (98, 60, 34) if r > 0.8 else shade((216, 172, 112), 0.86 + 0.14 * ((r * 4) % 1 > 0.5) + _hash(i, 0, 56) * 0.1)
        sc.poly([(lx - 1.2, ly - 1.1, 9), (lx + 1.2, ly - 1.1, 9), (lx + 1.2, ly + 1.1, 9), (lx - 1.2, ly + 1.1, 9)], end)
    return sc.image()


def site():
    """Plac budowy jak w grze: płyta fundamentu z kamiennych bloków, rusztowanie z bali, zaczęte mury, deski i kamienie."""
    sc = Scene(120, 110, 50, 60)
    f = 1.4
    ground_shadow(sc, 0, 16, 0, 12, 4)
    stone = lambda u, v, px, py: shade((182, 176, 162), 0.82 + _hash(int(u * 8), int(v * 6), 61) * 0.25
                                       - ((u * 8) % 1 < 0.08 or (v * 6) % 1 < 0.08) * 0.3)
    post = lambda u, v, px, py: shade((150, 100, 56), 0.75 + 0.45 * (1 - u))
    beam = lambda u, v, px, py: shade((168, 116, 66), 0.75 + 0.35 * v)
    for x, z in ((0, 0), (15, 0)):  # tylne słupy
        sc.box(x, x + 1, z, z + 1, f, 15, post, post)
    sc.box(0, 16, 0, 1, 14, 15, beam, beam, beam)
    sc.box(-0.5, 16.5, -0.5, 12.5, 0, f, lambda u, v, px, py: shade(stone(u, v, px, py), LIT), lambda u, v, px, py: shade(stone(u, v, px, py), HALF),
           lambda u, v, px, py: shade((196, 190, 176), 0.9 + _hash(px, py, 62) * 0.12))
    rag = lambda u: 0.45 + 0.35 * ((int(u * 7) * 5) % 4) / 3
    def wall(seed, k):
        st, rb = stucco(WALL, seed), red_base(seed + 1)
        return lambda u, v, px, py: None if v > rag(u) else shade(rb(u, v / 0.35, px, py) if v < 0.35 else st(u, v, px, py), k)
    sc.poly([(16, f, 12), (16, f, 0), (16, f + 7, 0), (16, f + 7, 12)], wall(63, HALF))
    sc.poly([(0, f, 12), (16, f, 12), (16, f + 7, 12), (0, f + 7, 12)], wall(65, LIT))
    for x, z in ((0, 11), (15, 11)):  # przednie słupy i belki
        sc.box(x, x + 1, z, z + 1, f, 15, post, post)
    sc.box(0, 16, 11, 12, 14, 15, beam, beam, beam)
    sc.box(15, 16, 0, 12, 14, 15, beam, beam, beam)
    for i in range(3):  # deski
        y = i * 0.9
        sc.box(19, 21.5, 2 + i * 0.2, 11 + i * 0.2, y, y + 0.9, lambda u, v, px, py: (150, 104, 60),
               lambda u, v, px, py: shade((206, 156, 96), 0.85 + 0.2 * v), lambda u, v, px, py: shade((224, 178, 118), 0.9 + _hash(int(v * 20), 0, 66 + i) * 0.15))
    for bx, bz, s_ in ((18.5, 12.5, 2.4), (21, 13.5, 2), (19.5, 15, 2.2)):  # kamienie
        sc.box(bx, bx + s_, bz, bz + s_, 0, s_ * 0.7, lambda u, v, px, py: shade((180, 174, 162), 0.9 + _hash(px, py, 67) * 0.15),
               lambda u, v, px, py: (124, 120, 112), lambda u, v, px, py: shade((204, 200, 188), 0.9 + _hash(px, py, 68) * 0.12))
    return sc.image()


BUILDINGS = {'dom': house, 'chata': hut, 'budowa': site}


# rzeźby w rogach ekranu – osobny plik docs/grafika/rzezby.py (render 3D)

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
                if ch in ('.', None):
                    x += 1
                    continue
                run = x
                while run < w and row[run] == ch:
                    run += 1
                fill = 'fill="#0a2a00" fill-opacity=".35"' if ch == 'shadow' else f'fill="{COLORS.get(ch, ch)}"'
                rects.append(f'<rect x="{i * w + x}" y="{y}" width="{run - x}" height="1" {fill}/>')
                x = run
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w * len(frames)} {h}" width="{w * len(frames)}" height="{h}" '
            f'shape-rendering="crispEdges">{"".join(rects)}</svg>\n')


# --- tekstury: kafle PNG (bezszwowe), w CSS powiększane ×2 z image-rendering: pixelated --------------------------

def png(path, pixels):
    """Zapis PNG: piksele (r, g, b) albo (r, g, b, a)."""
    h, w = len(pixels), len(pixels[0])
    rgba = len(pixels[0][0]) == 4
    raw = b''.join(b'\x00' + bytes(c for px in row for c in px) for row in pixels)

    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))

    path.write_bytes(b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6 if rgba else 2, 0, 0, 0))
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


def brush(size, seed):
    """Wnętrze okna z gry: prawie czarne tło, po nim zielone pociągnięcia pędzla ukośnie w górę w prawo (~27°).
    Szum rozmyty wzdłuż kierunku (2, −1) z zawijaniem – wydłużone smugi, kafel bezszwowy."""
    rnd = random.Random(seed)
    base = smooth_noise(size, 4, rnd)
    streak = [[sum(base[(y - k) % size][(x + 2 * k) % size] for k in range(-10, 11)) / 21 for x in range(size)]
              for y in range(size)]
    flat = sorted(v for row in streak for v in row)
    lo, cut, hi = flat[0], flat[int(len(flat) * 0.5)], flat[-1]  # połowa tła czarna, połowa w smugach
    dark = [rgb(c) for c in ['#0e1c16', '#11211a', '#142619']]  # łagodniej niż w grze: bez czystej czerni
    green = [rgb(c) for c in ['#1a3220', '#1f3a25', '#244229', '#2a4a2e', '#305234', '#375a3a']]
    px = [[None] * size for _ in range(size)]
    for y in range(size):
        for x in range(size):
            v = streak[y][x] + (rnd.random() - 0.5) * (hi - lo) * 0.08
            if v < cut:
                px[y][x] = dark[0] if rnd.random() < 0.8 else rnd.choice(dark[1:])
            else:
                idx = int((v - cut) / (hi - cut) * len(green) + rnd.random() * 1.5)
                px[y][x] = green[max(0, min(len(green) - 1, idx))]
    return px

if __name__ == '__main__':
    OUT.mkdir(parents=True, exist_ok=True)
    for old in OUT.glob('osadnicy-*'):
        old.unlink()
    for name, frames in SPRITES.items():
        (OUT / f'osadnicy-{name}.svg').write_text(svg(frames))
        print(f'osadnicy-{name}.svg  {len(frames[0][0])}×{len(frames[0])} × {len(frames)} klatek')
    # odcienie zmierzone na zrzutach z gry: żółtozielona, drobno ziarnista, kwiatki rzadkie i bledsze
    grass = texture(64, ['#2c5904', '#346504', '#417108', '#49790c', '#598610', '#659214', '#719e1c', '#7da620',
                         '#8ab228'], seed=7, streak=2, flowers=['#e8a8c0', '#d8b058', '#b088d0', '#e0d890'],
                    flower_rate=0.003)
    png(OUT / 'osadnicy-trawa.png', grass)
    # zwykła droga z gry: ubita ziemia, jasna różowawo-beżowa z brązowymi grudkami (szara kostka to dopiero droga dla osłów)
    gravel = texture(32, ['#84644c', '#90807a', '#9c9080', '#b49c88', '#b0a48c', '#c8b4a4', '#d2bea8'], seed=3, streak=2)
    png(OUT / 'osadnicy-zwir.png', gravel)
    png(OUT / 'osadnicy-okno.png', brush(64, seed=5))
    for name, draw in BUILDINGS.items():
        img = draw()
        png(OUT / f'osadnicy-{name}.png', img)
        print(f'osadnicy-{name}.png  {len(img[0])}×{len(img)}')
    for name, (scene, ppu) in rzezby.FIGURES.items():
        for suffix, mirror in (('', False), ('-prawy', True)):  # prawy róg: lustrzane odbicie, światło dalej z lewej
            img = rzezby.render(scene(), ppu, mirror)
            png(OUT / f'osadnicy-{name}{suffix}.png', img)
        print(f'osadnicy-{name}.png  {len(img[0])}×{len(img)}')
    print('osadnicy-trawa.png 64×64, osadnicy-zwir.png 32×32, osadnicy-okno.png 64×64')
