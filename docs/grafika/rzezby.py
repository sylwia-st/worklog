"""Marmurowe rzeźby w rogach ramy motywu Osadnicy (public/img/osadnicy-posag*.png, osadnicy-naroznik*.png).

Jak posągi na ramie mapy w The Settlers II, ale własne: postać zbudowana w 3D z gładko zlanych brył (czaszka, klatka
piersiowa, miednica, łopatki, mięśnie ramion, dłonie z palcami; szata z fałdami jako odkształcenie powierzchni),
renderowana metodą śledzenia promieni po funkcji odległości (SDF) – rzut prostokątny, światło z lewej góry, okluzja
otoczenia, połysk marmuru, ciemny kontur jak w grze. Postacie widać od tyłu, jak w grze. Jednostka: wysokość głowy
(kanon 8 głów, stopy na y = 0). Wywołuje to docs/grafika/osadnicy.py; czyste Python, render trwa kilkadziesiąt sekund.
"""

import math

SS = 2  # nadpróbkowanie


# --- bryły (odległość punktu od powierzchni; ujemna w środku) --------------------------------------------------------

def sphere(c, r):
    cx, cy, cz = c
    return lambda x, y, z: math.sqrt((x - cx) ** 2 + (y - cy) ** 2 + (z - cz) ** 2) - r


def ellipsoid(c, rad):
    cx, cy, cz = c
    rx, ry, rz = rad

    def f(x, y, z):
        X, Y, Z = (x - cx) / rx, (y - cy) / ry, (z - cz) / rz
        k0 = math.sqrt(X * X + Y * Y + Z * Z)
        k1 = math.sqrt((X / rx) ** 2 + (Y / ry) ** 2 + (Z / rz) ** 2)
        return k0 * (k0 - 1) / k1 if k1 > 1e-9 else -min(rad)
    return f


def limb(a, b, r1, r2=None):
    """Odcinek o promieniu zmieniającym się od r1 do r2 (ramię, przedramię, palec)."""
    ax, ay, az = a
    dx, dy, dz = b[0] - ax, b[1] - ay, b[2] - az
    l2 = dx * dx + dy * dy + dz * dz
    r2 = r1 if r2 is None else r2

    def f(x, y, z):
        px, py, pz = x - ax, y - ay, z - az
        h = max(0.0, min(1.0, (px * dx + py * dy + pz * dz) / l2))
        return math.sqrt((px - dx * h) ** 2 + (py - dy * h) ** 2 + (pz - dz * h) ** 2) - (r1 + (r2 - r1) * h)
    return f


def rounded_box(c, half, r):
    cx, cy, cz = c
    hx, hy, hz = half

    def f(x, y, z):
        qx, qy, qz = abs(x - cx) - hx + r, abs(y - cy) - hy + r, abs(z - cz) - hz + r
        return math.sqrt(max(qx, 0) ** 2 + max(qy, 0) ** 2 + max(qz, 0) ** 2) + min(max(qx, qy, qz), 0) - r
    return f


def ring(c, rx, rz, r):
    """Pierścień poziomy (eliptyczny) – zwinięty brzeg szaty, wylew amfory."""
    cx, cy, cz = c

    def f(x, y, z):
        q = math.sqrt(((x - cx) / rx) ** 2 + ((z - cz) / rz) ** 2) * min(rx, rz) - min(rx, rz)
        return math.sqrt(q * q + (y - cy) ** 2) - r
    return f


def smin(a, b, k):
    h = max(k - abs(a - b), 0.0) / k
    return min(a, b) - h * h * k * 0.25


def blend(parts, k):
    """Gładkie zlanie brył (mięśnie przechodzą w siebie bez szwu)."""
    def f(x, y, z):
        d = parts[0](x, y, z)
        for p in parts[1:]:
            d = smin(d, p(x, y, z), k)
        return d
    return f


def union(*parts):
    return lambda x, y, z: min(p(x, y, z) for p in parts)


def bounded(f, lo, hi, pad=0.3):
    """Przyspieszenie: poza prostopadłościanem otaczającym zwraca odległość do niego."""
    (x0, y0, z0), (x1, y1, z1) = lo, hi

    def g(x, y, z):
        dx = max(x0 - x, 0, x - x1)
        dy = max(y0 - y, 0, y - y1)
        dz = max(z0 - z, 0, z - z1)
        out = math.sqrt(dx * dx + dy * dy + dz * dz)
        return out + pad if out > pad else f(x, y, z)
    return g


# --- części ciała (widok od tyłu: plecy w stronę +z, czyli do widza) ----------------------------------------------

def head(c, tilt=0.0):
    """Głowa od tyłu: czaszka, uszy, włosy w falach (odkształcenie), kok na karku."""
    cx, cy, cz = c
    skull = ellipsoid(c, (0.37, 0.47, 0.43))
    bun = ellipsoid((cx + 0.02, cy - 0.22, cz + 0.36), (0.2, 0.15, 0.13))
    ears = union(ellipsoid((cx - 0.36, cy - 0.02, cz), (0.05, 0.11, 0.08)), ellipsoid((cx + 0.36, cy - 0.02, cz), (0.05, 0.11, 0.08)))
    base = blend([skull, bun, ears], 0.06)

    def f(x, y, z):
        d = base(x, y, z)
        if d < 0.08 and y > cy - 0.2:  # fale włosów biegnące od czoła ku kokowi
            d -= 0.012 * math.sin((y - cy) * 26 + math.atan2(x - cx, z - cz) * 3)
        return d
    return f


def arm(shoulder, elbow, wrist, r=(0.21, 0.15, 0.11), hand_dir=None, fist=False):
    """Ręka: mięsień naramienny, ramię z bicepsem/tricepsem, łokieć, przedramię zgrubiałe przy łokciu, dłoń z palcami."""
    sx, sy, sz = shoulder
    ex, ey, ez = elbow
    wx, wy, wz = wrist
    mid = lambda a, b, t: (a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t)
    fore_len = math.dist(elbow, wrist)
    parts = [
        sphere(shoulder, 0.27),
        limb(shoulder, elbow, r[0], r[1]),
        limb(mid(shoulder, elbow, 0.25), mid(shoulder, elbow, 0.6), r[0] + 0.025, r[1] + 0.02),  # biceps/triceps
        limb(elbow, wrist, r[1], r[2]),
        limb(mid(elbow, wrist, 0.08), mid(elbow, wrist, 0.4), r[1] + 0.018, r[2] + 0.02),  # mięśnie przedramienia
    ]
    ux, uy, uz = (wx - ex) / fore_len, (wy - ey) / fore_len, (wz - ez) / fore_len
    hd = hand_dir or (ux, uy, uz)
    palm_c = (wx + hd[0] * 0.22, wy + hd[1] * 0.22, wz + hd[2] * 0.22)
    parts.append(limb(wrist, palm_c, 0.1, 0.12))
    side = (-hd[1], hd[0], 0.0)  # w poprzek dłoni
    if fist:
        parts.append(sphere((palm_c[0] + hd[0] * 0.08, palm_c[1] + hd[1] * 0.08, palm_c[2] + 0.05), 0.12))
    else:
        for i, spread in enumerate((-0.075, -0.025, 0.025, 0.075)):
            base = (palm_c[0] + side[0] * spread + hd[0] * 0.09, palm_c[1] + side[1] * spread + hd[1] * 0.09, palm_c[2])
            ln = 0.26 + (0.04 if i in (1, 2) else 0)
            fan = spread * 1.5
            tip = (base[0] + (hd[0] + side[0] * fan) * ln, base[1] + (hd[1] + side[1] * fan) * ln, base[2])
            parts.append(limb(base, tip, 0.036, 0.028))
        thumb_base = (palm_c[0] - side[0] * 0.1 - hd[0] * 0.05, palm_c[1] - side[1] * 0.1 - hd[1] * 0.05, palm_c[2] + 0.02)
        parts.append(limb(thumb_base, (thumb_base[0] - side[0] * 0.14 + hd[0] * 0.12, thumb_base[1] - side[1] * 0.14 + hd[1] * 0.12, thumb_base[2] + 0.03), 0.045, 0.032))
    lo = tuple(min(p[i] for p in (shoulder, elbow, wrist, palm_c)) - 0.45 for i in range(3))
    hi = tuple(max(p[i] for p in (shoulder, elbow, wrist, palm_c)) + 0.45 for i in range(3))
    return bounded(blend(parts, 0.07), lo, hi)


def torso(shift=0.0):
    """Tułów od tyłu: szyja, mięśnie czworoboczne, klatka, łopatki, najszersze grzbietu, talia, prostowniki,
    rowek kręgosłupa (wgłębienie); shift – przesunięcie bioder w bok (kontrapost)."""
    s = shift
    parts = [
        limb((0, 6.75, 0.02), (0, 7.2, 0.0), 0.16),  # szyja
        limb((0, 6.95, 0.04), (-0.6, 6.5, 0.02), 0.13), limb((0, 6.95, 0.04), (0.6, 6.5, 0.02), 0.13),  # czworoboczny
        ellipsoid((0, 5.95, 0), (0.6, 0.72, 0.4)),  # klatka
        ellipsoid((-0.28, 6.05, 0.22), (0.22, 0.3, 0.12)), ellipsoid((0.28, 6.05, 0.22), (0.22, 0.3, 0.12)),  # łopatki
        ellipsoid((-0.4, 5.55, 0.1), (0.16, 0.45, 0.2)), ellipsoid((0.4, 5.55, 0.1), (0.16, 0.45, 0.2)),  # najszersze
        ellipsoid((s * 0.5, 5.0, 0), (0.46, 0.55, 0.32)),  # talia
        ellipsoid((s * 0.6 - 0.11, 5.05, 0.2), (0.09, 0.5, 0.1)), ellipsoid((s * 0.6 + 0.11, 5.05, 0.2), (0.09, 0.5, 0.1)),  # prostowniki
        ellipsoid((s, 4.45, 0), (0.62, 0.42, 0.38)),  # miednica
    ]
    body = blend(parts, 0.22)

    def f(x, y, z):
        d = body(x, y, z)
        if d < 0.1 and z > 0.05 and 4.6 < y < 6.75:  # rowek kręgosłupa
            d += 0.022 * math.exp(-((x - s * 0.6 * (6.75 - y) / 2.15) ** 2) / 0.014)
        return d
    return bounded(f, (-0.9, 4.0, -0.6), (0.9, 7.4, 0.6))


def drapery(top_y, hem_y, legs, fold_amp=0.05, flare=0.75, roll=True):
    """Szata od bioder: zlana z nóg i stożka (rozszerza się ku dołowi), fałdy pionowe zmieniające rytm z wysokością,
    zwinięty brzeg u góry."""
    cone = limb((0, top_y, 0), (0, hem_y, 0), 0.62, flare)
    mass = blend([cone] + legs, 0.4)
    parts = [mass]
    if roll:
        parts.append(ring((0.02, top_y - 0.05, 0), 0.64, 0.42, 0.09))

    def f(x, y, z):
        d = mass(x, y, z)
        if d < 0.15:
            t = max(0.0, min(1.0, (top_y - y) / 0.6))  # u góry fałdy łagodnieją
            a = math.atan2(x, z)
            d -= fold_amp * t * (math.sin(a * 9 + math.sin(y * 1.4) * 1.6) + 0.5 * math.sin(a * 17 - y * 2.1))
            d = max(d, hem_y - y)  # równy dół szaty
        if roll:
            d = smin(d, parts[1](x, y, z), 0.05)
        return d
    return bounded(f, (-1.2, hem_y - 0.1, -0.9), (1.2, top_y + 0.3, 0.9))


# --- postacie ------------------------------------------------------------------------------------------------------

def statue():
    """Kobieta z amforą od tyłu: lewa ręka uniesiona do ramy, prawa trzyma amforę, kontrapost, na udrapowanym cokole."""
    legs = [limb((-0.28, 4.2, 0), (-0.3, 2.2, 0), 0.32, 0.26), limb((-0.3, 2.2, 0), (-0.3, 0.3, 0), 0.26, 0.18),
            limb((0.3, 4.15, 0), (0.42, 2.25, -0.2), 0.3, 0.25), limb((0.42, 2.25, -0.2), (0.36, 0.38, 0.05), 0.25, 0.17)]
    body = union(
        blend([torso(shift=-0.06),  # ręce zlane z tułowiem przez mięśnie naramienne
               arm((-0.7, 6.45, 0), (-0.86, 7.75, 0.03), (-0.98, 8.95, 0.05)),
               arm((0.74, 6.42, 0), (0.9, 5.25, 0.05), (0.97, 4.2, 0.14), hand_dir=(0.05, -1, 0.1), fist=True)], 0.16),
        head((-0.04, 7.45, 0.0)),
        drapery(4.55, 0.18, legs),
        ellipsoid((-0.3, 0.1, 0.12), (0.13, 0.09, 0.2)), ellipsoid((0.37, 0.12, 0.14), (0.12, 0.09, 0.19)),  # stopy
    )
    amphora = bounded(union(
        blend([ellipsoid((1.18, 3.05, 0.18), (0.36, 0.52, 0.36)), limb((1.18, 3.4, 0.18), (1.18, 3.85, 0.18), 0.12, 0.1)], 0.14),
        ring((1.18, 3.87, 0.18), 0.15, 0.15, 0.045),
        limb((1.05, 3.78, 0.18), (0.94, 3.62, 0.18), 0.04), limb((0.94, 3.62, 0.18), (0.98, 3.3, 0.18), 0.04),
        limb((1.31, 3.78, 0.18), (1.42, 3.62, 0.18), 0.04), limb((1.42, 3.62, 0.18), (1.38, 3.3, 0.18), 0.04),
    ), (0.7, 2.4, -0.3), (1.65, 4.05, 0.65))

    block = rounded_box((0, -0.45, 0), (1.45, 0.45, 0.8), 0.06)

    def plinth(x, y, z):
        d = block(x, y, z)
        if y > -0.25 and d < 0.1:  # przewieszona tkanina na cokole
            d -= 0.03 * math.sin(x * 7 + math.sin(z * 4)) * min(1, (y + 0.25) * 3)
        return d
    return union(body, amphora, plinth), (-1.6, 1.75), (-0.95, 10.1)


def caryatid():
    """Kariatyda od tyłu podpierająca róg: lewa ręka w róg, prawa wzdłuż górnej krawędzi; szata od bioder zwęża się
    w ślimacznicę (wspornik)."""
    roll_ = lambda x, y, z: math.sqrt((math.sqrt((x - 0.25) ** 2 + (y - 2.05) ** 2) - 0.22) ** 2 + z * z) - 0.17
    scroll_xy = blend([roll_, ellipsoid((0.25, 2.05, 0), (0.3, 0.3, 0.12))], 0.08)  # zwinięta ślimacznica
    body = union(
        blend([torso(shift=0.04),
               arm((-0.7, 6.5, 0), (-1.0, 7.42, 0.03), (-1.14, 8.25, 0.05)),
               arm((0.72, 6.45, 0), (1.6, 6.78, 0.04), (2.4, 7.05, 0.06))], 0.16),
        head((0.05, 7.45, 0.0)),
        drapery(4.55, 2.15, [limb((0, 4.2, 0), (0.15, 2.3, 0), 0.5, 0.22)], flare=0.25),
        bounded(scroll_xy, (-0.3, 1.5, -0.3), (0.8, 2.6, 0.3)),
    )
    return body, (-1.55, 3.2), (1.55, 9.15)


# --- render ----------------------------------------------------------------------------------------------------------

LIGHT = (-0.78, 0.5, 0.38)
MARBLE = (238, 232, 218)
SHADOW_TINT = (62, 70, 60)


def render(scene, px_per_unit, mirror=False):
    sdf, (x0, x1), (y0, y1) = scene
    w, h = round((x1 - x0) * px_per_unit), round((y1 - y0) * px_per_unit)
    n = math.sqrt(sum(c * c for c in LIGHT))
    lx, ly, lz = (c / n for c in LIGHT)
    f = (lambda x, y, z: sdf(-x, y, z)) if mirror else sdf  # odbicie postaci, światło zostaje z lewej
    if mirror:
        x0, x1 = -x1, -x0
    step = 1.0 / (px_per_unit * SS)
    big = [[None] * (w * SS) for _ in range(h * SS)]
    for j in range(h * SS):
        y = y1 - (j + 0.5) * step
        for i in range(w * SS):
            x = x0 + (i + 0.5) * step
            z, hit = 1.2, False
            for _ in range(90):
                d = f(x, y, z)
                if d < 0.002:
                    hit = True
                    break
                z -= d * 0.8
                if z < -1.2:
                    break
            if not hit:
                continue
            e = 0.004
            nx = f(x + e, y, z) - f(x - e, y, z)
            ny = f(x, y + e, z) - f(x, y - e, z)
            nz = f(x, y, z + e) - f(x, y, z - e)
            nl = math.sqrt(nx * nx + ny * ny + nz * nz) or 1
            nx, ny, nz = nx / nl, ny / nl, nz / nl
            ndl = nx * lx + ny * ly + nz * lz
            wrap = max(0.0, ndl) * 0.7 + max(0.0, (ndl + 0.3) / 1.3) * 0.3
            ao = 1.0
            for k in range(1, 4):
                hk = 0.05 * k
                ao -= (hk - f(x + nx * hk, y + ny * hk, z + nz * hk)) * (1.6 / k)
            ao = max(0.3, min(1.0, ao))
            rz = 2 * ndl * nz - lz  # odbicie światła względem normalnej, widz w +z
            spec = max(0.0, rz) ** 22 * 0.35
            light = (0.14 + 0.92 * wrap) * ao
            col = tuple(min(255, s + (m - s) * light + 255 * spec) for m, s in zip(MARBLE, SHADOW_TINT))
            big[j][i] = col
    img = []
    for y in range(h):
        row = []
        for x in range(w):
            acc, cnt = [0.0, 0.0, 0.0], 0
            for sy in range(SS):
                for sx in range(SS):
                    c = big[y * SS + sy][x * SS + sx]
                    if c:
                        acc = [a + b for a, b in zip(acc, c)]
                        cnt += 1
            row.append((acc[0] / cnt, acc[1] / cnt, acc[2] / cnt, cnt / SS / SS) if cnt else None)
        img.append(row)
    out = []
    for y in range(h):
        row = []
        for x in range(w):
            c = img[y][x]
            if not c:
                row.append((0, 0, 0, 0))
                continue
            edge = c[3] > 0.5 and any(not (0 <= x + dx < w and 0 <= y + dy < h) or not img[y + dy][x + dx] or img[y + dy][x + dx][3] < 0.5
                                      for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)))
            k = 0.62 if edge else 1.0
            row.append((round(c[0] * k), round(c[1] * k), round(c[2] * k), round(c[3] * 255)))
        out.append(row)
    return out


FIGURES = {'posag': (statue, 16), 'naroznik': (caryatid, 13)}  # nazwa → (scena, pikseli na wysokość głowy)
