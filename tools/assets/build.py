#!/usr/bin/env python3
"""Generează variantele web (AVIF/WebP/JPEG) pentru asset-urile statice din manifest.

Originalele NU sunt în repository. Se descarcă din Google Drive-ul francizei
(ID-urile sunt în manifest.json) într-un director local, apoi:

    python3 tools/assets/build.py --originals /cale/catre/originale

Rezultat:
  public/media/static/<key>-<lățime>.{avif,webp,jpg}
  resources/data/asset-catalog.json  (citit de aplicație și de seeder-ul bibliotecii media)
"""
import argparse
import json
import os
import sys

from PIL import Image, ImageOps

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def crop_to_aspect(img, aspect, focal):
    if not aspect:
        return img
    aw, ah = (float(x) for x in aspect.split(":"))
    target = aw / ah
    w, h = img.size
    if w / h > target:
        nw = round(h * target)
        cx = focal[0] * w
        left = min(max(0, round(cx - nw / 2)), w - nw)
        return img.crop((left, 0, left + nw, h))
    nh = round(w / target)
    cy = focal[1] * h
    top = min(max(0, round(cy - nh / 2)), h - nh)
    return img.crop((0, top, w, top + nh))


def average_color(img):
    small = img.resize((1, 1), Image.Resampling.LANCZOS)
    r, g, b = small.getpixel((0, 0))[:3]
    return f"#{r:02x}{g:02x}{b:02x}"


def build(originals, out_dir, catalog_path, only=None):
    manifest = json.load(open(os.path.join(ROOT, "tools/assets/manifest.json"), encoding="utf-8"))
    os.makedirs(out_dir, exist_ok=True)
    catalog = []
    for entry in manifest["images"]:
        if only and entry["key"] not in only:
            continue
        src = os.path.join(originals, entry["src"])
        if not os.path.exists(src):
            print(f"lipsește originalul: {entry['src']}", file=sys.stderr)
            continue
        img = Image.open(src)
        img = ImageOps.exif_transpose(img).convert("RGB")
        img = crop_to_aspect(img, entry.get("aspect"), entry.get("focal", [0.5, 0.5]))
        variants = []
        widths = [w for w in entry["widths"] if w <= img.width] or [img.width]
        for w in widths:
            h = round(img.height * w / img.width)
            resized = img.resize((w, h), Image.Resampling.LANCZOS)
            base = os.path.join(out_dir, f"{entry['key']}-{w}")
            resized.save(base + ".avif", quality=52, speed=4)
            resized.save(base + ".webp", quality=74, method=6)
            variants.append({"width": w, "height": h})
        # o singură variantă JPEG ca rezervă pentru browsere vechi și pentru Open Graph
        fallback_w = widths[min(1, len(widths) - 1)]
        fh = round(img.height * fallback_w / img.width)
        img.resize((fallback_w, fh), Image.Resampling.LANCZOS).save(
            os.path.join(out_dir, f"{entry['key']}-{fallback_w}.jpg"), quality=80, progressive=True, optimize=True
        )
        catalog.append({
            "key": entry["key"],
            "kind": entry["kind"],
            "alt_ro": entry["alt_ro"],
            "game": entry.get("game"),
            "aspect": entry.get("aspect"),
            "color": average_color(img),
            "variants": variants,
            "fallback_width": fallback_w,
            "source": {"drive_id": entry["drive_id"], "path": entry["src"]},
            "rights": entry["rights"],
        })
        print(f"ok {entry['key']} {[v['width'] for v in variants]}")
    if not only:
        os.makedirs(os.path.dirname(catalog_path), exist_ok=True)
        json.dump({"version": 1, "images": catalog}, open(catalog_path, "w", encoding="utf-8"), ensure_ascii=False, indent=1)


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--originals", required=True)
    p.add_argument("--out", default=os.path.join(ROOT, "public/media/static"))
    p.add_argument("--catalog", default=os.path.join(ROOT, "resources/data/asset-catalog.json"))
    p.add_argument("--only", nargs="*")
    a = p.parse_args()
    build(a.originals, a.out, a.catalog, a.only)
