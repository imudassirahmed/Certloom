#!/usr/bin/env python3
"""Validate Certloom development-only golden rendering references."""
from __future__ import annotations
import hashlib, json, re, subprocess
from pathlib import Path
from PIL import Image
import cv2

ROOT=Path(__file__).resolve().parents[2]
FIX=ROOT/'tests'/'fixtures'/'rendering'
REF=FIX/'reference'
MM_TO_PT=72.0/25.4
MM_TO_PX=96.0/25.4

def sha256(p:Path)->str:
    h=hashlib.sha256()
    with p.open('rb') as f:
        for chunk in iter(lambda:f.read(1024*1024),b''): h.update(chunk)
    return h.hexdigest()

def pdf_size(path:Path):
    out=subprocess.check_output(['pdfinfo',str(path)],text=True)
    m=re.search(r'^Page size:\s+([0-9.]+) x ([0-9.]+) pts',out,re.M)
    if not m: raise RuntimeError(f'{path}: PDF page size unavailable')
    return float(m.group(1)),float(m.group(2))

def check(cond,msg):
    if not cond: raise RuntimeError(msg)
    print('PASS ',msg)

def main():
    manifest=json.loads((REF/'manifest.json').read_text(encoding='utf-8'))
    check(manifest.get('version')==2,'reference manifest uses deterministic v2 contract')
    for name,facts in sorted(manifest['fixtures'].items()):
        layout=json.loads((FIX/f'{name}.json').read_text(encoding='utf-8'))
        page=layout['page']; w=float(page['width_mm']); h=float(page['height_mm'])
        d=REF/name
        for ext in ('svg','png','pdf'):
            p=d/f'reference.{ext}'
            check(p.is_file(),f'{name} {ext.upper()} reference exists')
            check(sha256(p)==facts[f'{ext}_sha256'],f'{name} {ext.upper()} hash matches manifest')
        with Image.open(d/'reference.png') as im:
            expected=(round(w*MM_TO_PX),round(h*MM_TO_PX))
            check(im.size==expected,f'{name} PNG preserves canonical page geometry')
        pw,ph=pdf_size(d/'reference.pdf')
        check(abs(pw-w*MM_TO_PT)<0.02 and abs(ph-h*MM_TO_PT)<0.02,f'{name} PDF preserves canonical physical page size')
    long_facts=manifest['fixtures']['long-name-shrink']['elements'][0]
    check(5.0 <= float(long_facts['font_size_pt']) < 30.0,'long-name oracle actually exercises shrink behavior')
    check(manifest['fixtures']['urdu-rtl']['elements'][0]['direction']=='rtl','Urdu oracle resolves right-to-left direction')
    check(abs(float(json.loads((FIX/'rotation.json').read_text())['elements'][0]['rotation_deg'])-17.5)<1e-9,'rotation oracle retains arbitrary 17.5 degree angle')

    # Decode the QR from its real page crop, not from source data, to prove the raster reference is scannable.
    layout=json.loads((FIX/'qr.json').read_text()); e=layout['elements'][0]
    image=cv2.imread(str(REF/'qr'/'reference.png'))
    pad=5.0
    x=max(0,round((float(e['x_mm'])-pad)*MM_TO_PX)); y=max(0,round((float(e['y_mm'])-pad)*MM_TO_PX))
    x2=min(image.shape[1],round((float(e['x_mm'])+float(e['width_mm'])+pad)*MM_TO_PX)); y2=min(image.shape[0],round((float(e['y_mm'])+float(e['height_mm'])+pad)*MM_TO_PX))
    value,points,_=cv2.QRCodeDetector().detectAndDecode(image[y:y2,x:x2])
    check(points is not None and value==manifest['verification_url'],'QR raster oracle decodes to the exact verification URL')
    print(f"\n{len(manifest['fixtures'])}/6 reference fixtures validated.")

if __name__=='__main__': main()
