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

# --- budynki: jak pre-renderowane w grze – bryły w rzucie ukośnym (przód, prawy bok w głąb, dach od góry), liczone
# w potrójnej rozdzielczości i uśredniane (gładkie krawędzie), z fakturą tynku, desek i dachówki, cieniem pod okapem,
# ciemnym konturem i cieniem na trawie. Zapis: PNG z przezroczystością, w CSS w skali 1:1.
# Punkt (x, y, z): x w prawo, y w górę, z w głąb; na ekranie głąb idzie w prawo-w górę.

KX, KY = 0.55, 0.5  # przesunięcie ekranu na jednostkę głębi
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

    def __init__(self, w, h, ox, oy):
        self.w, self.h, self.ox, self.oy = w, h, ox, oy
        self.px = [[None] * (w * SS) for _ in range(h * SS)]

    def at(self, x, y, z):
        return (self.ox + x + z * KX) * SS, (self.oy - y - z * KY) * SS

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

    def box(self, x0, z0, x1, z1, y0, y1, front, side, top=None):
        """Prostopadłościan: prawy bok, przód, opcjonalnie wierzch (paint jak w poly)."""
        self.poly([(x1, y0, z0), (x1, y0, z1), (x1, y1, z1), (x1, y1, z0)], side)
        self.poly([(x0, y0, z0), (x1, y0, z0), (x1, y1, z0), (x0, y1, z0)], front)
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
        return final


SHADOW = (8, 30, 0, 0.38)


def ground_shadow(sc, x0, x1, d, h, length):
    """Cień na trawie w prawo-w tył: rzut bryły przy świetle z lewej góry."""
    sc.poly([(x1, 0, -0.5), (x1 + length, 0, h * 0.25), (x1 + length, 0, d + h * 0.35), (x0 + 4, 0, d + h * 0.35), (x0 + 4, 0, d)],
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


def opening(sc, x0, x1, y0, y1, z, arch=True, inner=(34, 24, 18), frame=(150, 120, 90)):
    """Ciemny otwór drzwi/bramy w przedniej ścianie, z łukiem i jasną krawędzią od światła."""
    cx, r = (x0 + x1) / 2, (x1 - x0) / 2
    def f(u, v, px, py):
        x, y = x0 + u * (x1 - x0), y0 + v * (y1 - y0)
        top = y1 - r + (r * r - (x - cx) ** 2) ** 0.5 * 0.9 if arch else y1
        if y > top:
            return None
        if y > top - 0.9 or x < x0 + 0.8:
            return frame
        k = 0.7 + 0.5 * (x - x0) / (x1 - x0)
        return shade(inner, k)
    sc.poly([(x0, y0, z), (x1, y0, z), (x1, y1, z), (x0, y1, z)], f)


def window(sc, x0, y0, w, h, z, side=False, d=0.0):
    """Okienko z drewnianą kratką i kamiennym parapetem; side – w prawym boku (x0 to głębia)."""
    def f(u, v, px, py):
        if v < 0.14:
            return (200, 190, 170)
        if u < 0.12 or u > 0.88 or v > 0.9:
            return (110, 70, 40)
        if abs(u - 0.5) < 0.07 or abs(v - 0.55) < 0.06:
            return (130, 86, 50)
        return (30, 26, 24)
    if side:
        sc.poly([(d, y0, x0), (d, y0, x0 + w), (d, y0 + h, x0 + w), (d, y0 + h, x0)], f)
    else:
        sc.poly([(x0, y0, z), (x0 + w, y0, z), (x0 + w, y0 + h, z), (x0, y0 + h, z)], f)


def walls(sc, x0, x1, z0, z1, h, front, side, base=None, base_h=0.0, eave=0.0):
    """Przednia i prawa ściana z cokołem (base) i cieniem pod okapem (eave – grubość cienia)."""
    def wrap(paint, span, dark):
        def f(u, v, px, py):
            y = v * h
            c = base(u, y / base_h if base_h else 0, px, py) if base and y < base_h else paint(u, v, px, py)
            if eave and y > h - eave:
                c = shade(c, 0.7)
            return shade(c, dark)
        return f
    sc.poly([(x1, 0, z0), (x1, 0, z1), (x1, h, z1), (x1, h, z0)], wrap(side, z1 - z0, 0.72))
    sc.poly([(x0, 0, z0), (x1, 0, z0), (x1, h, z0), (x0, h, z0)], wrap(front, x1 - x0, 1.0))


def gable(sc, x0, x1, z0, z1, h, rise, tiles_paint, gable_paint, over=1.6):
    """Dach dwuspadowy z kalenicą wzdłuż x: trójkąt szczytu z prawej, tylna połać (widać jej skraj), przednia połać,
    deska szczytowa i gąsiory na kalenicy."""
    zm = (z0 + z1) / 2
    sc.poly([(x1, h, z0), (x1, h, z1), (x1, h + rise, zm)], gable_paint)
    sc.poly([(x0 - over, h - 0.6, z1 + over), (x1 + over, h - 0.6, z1 + over), (x1 + over, h + rise, zm), (x0 - over, h + rise, zm)],
            lambda u, v, px, py: shade(tiles_paint(u, 1 - v, px, py), 0.62))
    sc.poly([(x1 + over, h - 0.6, z0 - over), (x1 + over + 0.9, h - 0.6, z0 - over), (x1 + over + 0.9, h + rise, zm), (x1 + over, h + rise, zm)],
            lambda u, v, px, py: (96, 52, 26))
    sc.poly([(x0 - over, h - 0.8, z0 - over), (x1 + over, h - 0.8, z0 - over), (x1 + over, h + rise, zm), (x0 - over, h + rise, zm)], tiles_paint)
    sc.poly([(x0 - over, h + rise - 0.5, zm - 0.6), (x1 + over, h + rise - 0.5, zm - 0.6), (x1 + over, h + rise + 0.9, zm), (x0 - over, h + rise + 0.9, zm)],
            lambda u, v, px, py: shade((200, 110, 50), 0.8 + 0.3 * v + ((u * 30) % 1 < 0.15) * -0.25))


def lean_to(sc, x0, x1, z0, z1, h_low, h_high, tiles_paint, over=1.2):
    """Daszek pulpitowy dobudówki: opada ku przodowi."""
    sc.poly([(x0 - over, h_low, z0 - over), (x1, h_low, z0 - over), (x1, h_high, z1), (x0 - over, h_high, z1)], tiles_paint)


ROOF = (222, 128, 52)
WALL = (232, 222, 200)
BASE_RED = (176, 82, 56)


def red_base(seed):
    def f(u, t, px, py):
        if t > 0.86:
            return (120, 52, 34)  # listwa nad cokołem
        return shade(BASE_RED, 0.86 + vnoise(px, py, 4, seed) * 0.2 + (_hash(px, py, seed) - 0.5) * 0.08)
    return f


def house():
    """Dom jak w grze: główna bryła z białego tynku na czerwonym cokole, pomarańczowa dachówka, łukowe drzwi,
    kratkowane okienka, ceglany komin; z lewej niższa dobudówka z daszkiem pulpitowym i ciemną bramą."""
    sc = Scene(92, 66, 20, 61)
    w, d, h = 40, 22, 17
    ground_shadow(sc, -16, w, d, h + 14, 12)
    # dobudówka z lewej (cofnięta)
    walls(sc, -16, 0, 6, 20, 10, stucco(WALL, 31, 0.15), stucco(WALL, 32), red_base(33), 2.4, 1.2)
    opening(sc, -12, -4, 0, 7.6, 6)
    lean_to(sc, -16, 0, 6, 20, 9.6, 14, tiled(ROOF, 34, 5, 7))
    # główna bryła
    walls(sc, 0, w, 0, d, h, stucco(WALL, 35, 0.15), stucco(WALL, 36), red_base(37), 3.2, 1.6)
    opening(sc, 16, 23, 0, 10.5, 0)
    window(sc, 5, 8.5, 4.5, 5, 0)
    window(sc, 30, 8.5, 4.5, 5, 0)
    window(sc, 7, 8.5, 4, 5, 0, side=True, d=w)
    window(sc, 14, 8.5, 4, 5, 0, side=True, d=w)
    gable(sc, 0, w, 0, d, h, 16, tiled(ROOF, 38, 7, 16, edge=0.012), stucco(WALL, 39))
    # komin
    brick = lambda u, v, px, py: shade((168, 72, 48), 0.85 + _hash(int(u * 3), int(v * 9), 40) * 0.25 - (((v * 9) % 1) < 0.15) * 0.3)
    sc.box(28, 13, 32, 16, 24, 36, brick, lambda u, v, px, py: shade(brick(u, v, px, py), 0.7), lambda u, v, px, py: (60, 30, 20))
    return sc.image()


def hut():
    """Chata drwala jak w grze: ściany z czerwonobrązowych desek na ceglanym cokole, dach z ciemnego gontu,
    ciemne drzwi i okienko, obok sterta bali."""
    sc = Scene(70, 54, 6, 50)
    w, d, h = 28, 16, 12
    ground_shadow(sc, 0, w, d, h + 10, 9)
    wood = (164, 104, 62)
    walls(sc, 0, w, 0, d, h, planks(wood, 51), planks(wood, 52), red_base(53), 2.2, 1.4)
    opening(sc, 16, 22, 0, 8.4, 0, arch=False, inner=(28, 18, 12), frame=(96, 56, 34))
    window(sc, 5, 5.5, 4, 3.6, 0)
    window(sc, 6, 5.5, 3.6, 3.6, 0, side=True, d=w)
    gable(sc, 0, w, 0, d, h, 12, tiled((132, 78, 46), 54, 6, 14, edge=0.015), planks((128, 70, 44), 55), over=1.4)
    # sterta bali z prawej z przodu: czoła bali jako elipsy ze słojami
    for i, (bx, by) in enumerate(((w + 6, 1.6), (w + 9.2, 1.6), (w + 12.4, 1.6), (w + 7.6, 4.4), (w + 10.8, 4.4), (w + 9.2, 7.2))):
        def log_end(u, v, px, py, i=i):
            r = ((u - 0.5) ** 2 + (v - 0.5) ** 2) ** 0.5 * 2
            if r > 1:
                return None
            if r > 0.82:
                return (96, 60, 34)
            return shade((214, 170, 110), 0.85 + 0.15 * ((r * 4) % 1 > 0.5) + _hash(i, 0, 56) * 0.1)
        sc.poly([(bx - 1.6, by + 1.4, 1), (bx + 1.6, by + 1.4, 1), (bx + 1.6, by + 1.4, -5), (bx - 1.6, by + 1.4, -5)],
                lambda u, v, px, py: shade((120, 74, 42), 0.8 + 0.3 * u))  # wierzch bala
        sc.poly([(bx - 1.6, by - 1.6, -5), (bx + 1.6, by - 1.6, -5), (bx + 1.6, by + 1.6, -5), (bx - 1.6, by + 1.6, -5)], log_end)
    return sc.image()


def site():
    """Plac budowy jak w grze: fundament z kamiennych bloków, zaczęte mury z tynku na cokole (nierówna góra),
    rusztowanie z bali z belkami, z boku stos desek i kamieni."""
    sc = Scene(76, 56, 6, 50)
    w, d, f = 32, 18, 2.2
    ground_shadow(sc, 0, w, d, 12, 8)
    stone = lambda u, v, px, py, span=1: shade((176, 170, 158), 0.8 + _hash(int(u * 12 + (int(v * 2) % 2) * 0.5), int(v * 2), 61) * 0.3
                                               - ((u * 12 + (int(v * 2) % 2) * 0.5) % 1 < 0.08) * 0.35 - ((v * 2) % 1 < 0.12) * 0.3)
    post = lambda u, v, px, py: shade((150, 100, 56), 0.75 + 0.45 * (1 - u))
    beam = lambda u, v, px, py: shade((160, 108, 60), 0.7 + 0.4 * v)
    # tylne słupy i belka
    for x in (0.5, w - 2):
        sc.box(x, d - 2, x + 1.5, d - 0.5, f, 22, post, lambda u, v, px, py: shade((110, 72, 40), 1))
    sc.box(0.5, d - 2, w - 0.5, d - 0.5, 20.5, 22, beam, beam)
    # fundament
    sc.box(-1, -1, w + 1, d + 1, 0, f, stone, lambda u, v, px, py: shade(stone(u, v, px, py), 0.72),
           lambda u, v, px, py: shade((190, 184, 172), 0.9 + _hash(px, py, 62) * 0.15))
    # zaczęte mury: z tyłu wyższe (z prawej), z przodu do połowy, góra postrzępiona
    rag = lambda u: 0.55 + 0.3 * ((int(u * 9) * 7) % 5) / 4
    def wall_paint(seed):
        st = stucco(WALL, seed)
        rb = red_base(seed + 1)
        def p(u, v, px, py):
            if v > rag(u):
                return None
            return rb(u, v / 0.3, px, py) if v < 0.3 else st(u, v, px, py)
        return p
    sc.poly([(w, f, 0), (w, f, d), (w, f + 9, d), (w, f + 9, 0)], lambda u, v, px, py: (lambda c: shade(c, 0.72) if c else None)(wall_paint(63)(u, v, px, py)))
    sc.poly([(0, f, 0), (w, f, 0), (w, f + 9, 0), (0, f + 9, 0)], wall_paint(65))
    # przednie słupy, belki, zastrzał
    for x in (0.5, w - 2):
        sc.box(x, -0.5, x + 1.5, 1, f, 22, post, lambda u, v, px, py: shade((110, 72, 40), 1))
    sc.box(0.5, -0.5, w - 0.5, 1, 20.5, 22, beam, beam)
    sc.poly([(w - 1, 20.5, 1), (w, 20.5, 1), (w, 20.5, d - 0.5), (w - 1, 20.5, d - 0.5)], beam)
    sc.poly([(2, f + 1, -0.6), (3.4, f + 1, -0.6), (13, 20.5, -0.6), (11.6, 20.5, -0.6)], post)
    # deski i kamienie obok
    for i in range(4):
        y = i * 1.1
        sc.box(w + 4, -8 + i * 0.3, w + 16, -5 + i * 0.3, y, y + 1.1,
               lambda u, v, px, py: shade((210, 160, 100), 0.8 + 0.25 * v - (u < 0.03) * 0.3), lambda u, v, px, py: (150, 104, 60),
               lambda u, v, px, py: shade((222, 176, 116), 0.9 + _hash(int(u * 20), 0, 66 + i) * 0.15))
    for bx, bz, s_ in ((w + 5, 4, 3.4), (w + 9, 6, 2.8), (w + 7, 9, 3)):
        sc.box(bx, bz, bx + s_, bz + s_ * 0.8, 0, s_ * 0.7, lambda u, v, px, py: shade((178, 172, 160), 0.85 + _hash(px, py, 67) * 0.2),
               lambda u, v, px, py: (120, 116, 108), lambda u, v, px, py: shade((200, 196, 184), 0.9 + _hash(px, py, 68) * 0.15))
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
    # droga jak w grze: szara kostka z brązowawymi grudkami ziemi
    gravel = texture(32, ['#5e625e', '#6e746e', '#828a82', '#8e8478', '#9ea29e', '#b4b8b4'], seed=3, streak=2)
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
