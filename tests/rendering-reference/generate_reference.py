#!/usr/bin/env python3
"""Generate development-only golden reference artifacts for Certloom layouts.

This is NOT a production renderer and is never shipped in the installable plugin.
It uses local Inkscape + a fixed local development font only to establish visual
reference artifacts that future PHP renderer adapters must be compared against.
"""
from __future__ import annotations
import base64, hashlib, json, math, os, re, shutil, subprocess, sys
from pathlib import Path
import PIL
from PIL import Image, ImageFont, features
import qrcode
from qrcode.constants import ERROR_CORRECT_L, ERROR_CORRECT_M, ERROR_CORRECT_Q, ERROR_CORRECT_H

ROOT = Path(__file__).resolve().parents[2]
FIXTURES = ROOT / 'tests' / 'fixtures' / 'rendering'
OUT = FIXTURES / 'reference'
FONT = Path('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')
PRIMITIVE_ASSET = FIXTURES / 'assets' / 'primitives.png'
DPI = 96.0
SOURCE_DATE_EPOCH = '1700000000'  # Fixed development-oracle timestamp for byte-stable PDF output.
MERGES = {
    'english': {'recipient.name': 'Amina Rahman'},
    'urdu-rtl': {'recipient.name': 'محمد علی'},
    'long-name-shrink': {'recipient.name': 'Alexandria Noor Fatima Montgomery-Washington'},
}
VERIFY_URL = 'https://example.test/credential/7M4K-2Q8D-F3XP'
QR_EC = {'L': ERROR_CORRECT_L, 'M': ERROR_CORRECT_M, 'Q': ERROR_CORRECT_Q, 'H': ERROR_CORRECT_H}

def sha256(path: Path) -> str:
    h=hashlib.sha256()
    with path.open('rb') as f:
        for chunk in iter(lambda:f.read(1024*1024),b''):
            h.update(chunk)
    return h.hexdigest()

def esc(s: str) -> str:
    return s.replace('&','&amp;').replace('<','&lt;').replace('>','&gt;').replace('"','&quot;')

def px_from_mm(mm: float) -> float:
    return mm * DPI / 25.4

def shrink_size(text: str, pt: float, width_mm: float, height_mm: float) -> float:
    # Development oracle only. Pillow is built with RAQM/HarfBuzz here.
    target_w = px_from_mm(width_mm)
    target_h = px_from_mm(height_mm)
    size = float(pt)
    while size >= 5.0:
        font = ImageFont.truetype(str(FONT), max(1, round(size * DPI / 72.0)))
        box = font.getbbox(text, direction='rtl' if re.search(r'[\u0600-\u06FF]', text) else 'ltr')
        w=max(0,box[2]-box[0]); h=max(0,box[3]-box[1])
        if w <= target_w and h <= target_h:
            return round(size, 2)
        size -= 0.25
    return 5.0

def text_svg(e: dict, text: str) -> tuple[str, dict]:
    st = e.get('style') or {}
    pt=float(st.get('font_size_pt',18.0)); overflow=st.get('overflow','shrink')
    if overflow=='shrink': pt=shrink_size(text,pt,float(e['width_mm']),float(e['height_mm']))
    align=st.get('align','center'); valign=st.get('vertical_align','middle')
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm'])
    if align in ('center',): tx=x+w/2; anchor='middle'
    elif align in ('right','end'): tx=x+w; anchor='end'
    else: tx=x; anchor='start'
    # dominant-baseline central is deliberately simple; future PHP adapters are
    # compared visually and any meaningful variance moves into shared Core.
    if valign=='top': ty=y; baseline='hanging'
    elif valign=='bottom': ty=y+h; baseline='text-after-edge'
    else: ty=y+h/2; baseline='middle'
    direction=st.get('direction','auto')
    if direction=='auto': direction='rtl' if re.search(r'[\u0600-\u06FF]',text) and not re.search(r'[A-Za-z]',text[:1]) else 'ltr'
    color=st.get('color','#000000')[:7]
    weight=int(st.get('font_weight',400)); italic='italic' if st.get('italic',False) else 'normal'
    opacity=float(e.get('opacity',1.0)); rot=float(e.get('rotation_deg',0.0)); cx=x+w/2; cy=y+h/2
    transform=f' transform="rotate({rot:g} {cx:g} {cy:g})"' if abs(rot)>1e-9 else ''
    node=(f'<text x="{tx:g}" y="{ty:g}" text-anchor="{anchor}" dominant-baseline="{baseline}" '
          f'font-family="DejaVu Sans" font-size="{(pt*25.4/72.0):.8f}" font-weight="{weight}" font-style="{italic}" '
          f'fill="{color}" opacity="{opacity:g}" direction="{direction}" unicode-bidi="plaintext"{transform}>{esc(text)}</text>')
    return node, {'font_size_pt':pt,'direction':direction,'overflow':overflow}


def color_value(value: str, fallback: str = '#000000') -> tuple[str,float]:
    value=str(value or fallback).strip()
    m=re.match(r'^#([0-9A-Fa-f]{6})([0-9A-Fa-f]{2})?$',value)
    if not m: return fallback,1.0
    return '#'+m.group(1).upper(), (int(m.group(2),16)/255.0 if m.group(2) else 1.0)

def rotation_attr(e: dict) -> str:
    rot=float(e.get('rotation_deg',0.0))
    if abs(rot)<=1e-9: return ''
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm'])
    return f' transform="rotate({rot:g} {x+w/2:g} {y+h/2:g})"'

def shape_svg(e: dict) -> tuple[str,dict]:
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm'])
    fill,fill_alpha=color_value(e.get('fill','#00000000'),'#000000')
    stroke,stroke_alpha=color_value(e.get('stroke','#000000'),'#000000')
    opacity=float(e.get('opacity',1.0)); sw=float(e.get('stroke_width_mm',0.0)); transform=rotation_attr(e)
    common=f' fill="{fill}" fill-opacity="{fill_alpha:g}" stroke="{stroke}" stroke-opacity="{stroke_alpha:g}" stroke-width="{sw:g}" opacity="{opacity:g}"{transform}'
    if e.get('shape')=='ellipse':
        node=f'<ellipse cx="{x+w/2:g}" cy="{y+h/2:g}" rx="{w/2:g}" ry="{h/2:g}"{common}/>'
    else:
        node=f'<rect x="{x:g}" y="{y:g}" width="{w:g}" height="{h:g}"{common}/>'
    return node,{'shape':e.get('shape'),'rotation_deg':float(e.get('rotation_deg',0.0))}

def line_svg(e: dict) -> tuple[str,dict]:
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm'])
    stroke,stroke_alpha=color_value(e.get('stroke','#000000'),'#000000')
    opacity=float(e.get('opacity',1.0)); sw=float(e.get('stroke_width_mm',0.3)); transform=rotation_attr(e)
    return (f'<line x1="{x:g}" y1="{y:g}" x2="{x+w:g}" y2="{y+h:g}" stroke="{stroke}" stroke-opacity="{stroke_alpha:g}" stroke-width="{sw:g}" opacity="{opacity:g}"{transform}/>',{'rotation_deg':float(e.get('rotation_deg',0.0))})

def image_svg(e: dict) -> tuple[str,dict]:
    if e.get('asset')!='asset:primitives' or not PRIMITIVE_ASSET.is_file():
        raise RuntimeError('primitive fixture references an unavailable deterministic local asset')
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm'])
    fit=str(e.get('fit','contain')); preserve={'contain':'xMidYMid meet','cover':'xMidYMid slice','fill':'none'}[fit]
    mime='image/png'; data=base64.b64encode(PRIMITIVE_ASSET.read_bytes()).decode('ascii')
    opacity=float(e.get('opacity',1.0)); transform=rotation_attr(e)
    node=(f'<image x="{x:g}" y="{y:g}" width="{w:g}" height="{h:g}" preserveAspectRatio="{preserve}" '
          f'href="data:{mime};base64,{data}" opacity="{opacity:g}"{transform}/>')
    return node,{'fit':fit,'asset_sha256':sha256(PRIMITIVE_ASSET),'rotation_deg':float(e.get('rotation_deg',0.0))}

def qr_svg(e: dict) -> tuple[str, dict]:
    ec=str(e.get('error_correction','M')).upper()
    qr=qrcode.QRCode(version=None,error_correction=QR_EC[ec],box_size=1,border=0)
    qr.add_data(VERIFY_URL); qr.make(fit=True); matrix=qr.get_matrix(); n=len(matrix)
    quiet=int(e.get('quiet_zone_modules',4)); total=n+quiet*2
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); module=w/total
    bits=[]
    for yy,row in enumerate(matrix):
        for xx,on in enumerate(row):
            if on:
                rx=x+(xx+quiet)*module; ry=y+(yy+quiet)*module
                bits.append(f'<rect x="{rx:.8f}" y="{ry:.8f}" width="{module:.8f}" height="{module:.8f}" fill="#000"/>')
    return '<g>'+''.join(bits)+'</g>', {'modules':n,'quiet_zone_modules':quiet,'url':VERIFY_URL,'error_correction':ec}

def build_svg(name: str, layout: dict) -> tuple[str, dict]:
    page=layout['page']; w=float(page['width_mm']); h=float(page['height_mm'])
    nodes=[f'<rect x="0" y="0" width="{w:g}" height="{h:g}" fill="#fff"/>']
    facts=[]
    for e in sorted(layout['elements'], key=lambda x:int(x.get('z',0))):
        typ=e['type']
        if typ=='merge': text=MERGES.get(name,{}).get(e['token'],''); node,fact=text_svg(e,text)
        elif typ=='text': node,fact=text_svg(e,str(e.get('text','')))
        elif typ=='qr': node,fact=qr_svg(e)
        elif typ=='shape': node,fact=shape_svg(e)
        elif typ=='line': node,fact=line_svg(e)
        elif typ in ('image','logo','signature'): node,fact=image_svg(e)
        else: raise RuntimeError(f'unsupported golden fixture primitive: {typ}')
        nodes.append(node); facts.append({'id':e['id'],'type':typ,**fact})
    svg=(f'<svg xmlns="http://www.w3.org/2000/svg" width="{w:g}mm" height="{h:g}mm" viewBox="0 0 {w:g} {h:g}">'
         +''.join(nodes)+'</svg>')
    return svg, {'page_width_mm':w,'page_height_mm':h,'elements':facts}

def run(cmd):
    env=os.environ.copy(); env['SOURCE_DATE_EPOCH']=SOURCE_DATE_EPOCH
    p=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env)
    if p.returncode:
        raise RuntimeError(f"command failed ({p.returncode}): {' '.join(cmd)}\n{p.stdout}\n{p.stderr}")
    return p.stdout.strip()

def main():
    if not FONT.is_file(): raise SystemExit(f'missing reference font: {FONT}')
    if not PRIMITIVE_ASSET.is_file(): raise SystemExit(f'missing primitive reference asset: {PRIMITIVE_ASSET}')
    if not shutil.which('inkscape'): raise SystemExit('inkscape is required for development reference generation')
    if not features.check('raqm'): raise SystemExit('Pillow RAQM/HarfBuzz support is required for reference text measurement')
    OUT.mkdir(parents=True,exist_ok=True)
    inkscape=run(['inkscape','--version']).splitlines()[0]
    manifest={'schema':'certloom-development-reference','version':4,'renderer':inkscape,'raster_dpi':DPI,'source_date_epoch':SOURCE_DATE_EPOCH,
              'jpeg_reference':{'encoder':'Pillow '+PIL.__version__,'quality':95,'subsampling':0,'progressive':False,'optimize':False},
              'font':{'family':'DejaVu Sans','sha256':sha256(FONT)},'assets':{'primitives':{'sha256':sha256(PRIMITIVE_ASSET),'mime':'image/png'}},'verification_url':VERIFY_URL,'fixtures':{}}
    names=['english','urdu-rtl','mixed-script','long-name-shrink','rotation','qr','primitives']
    for name in names:
        layout=json.loads((FIXTURES/f'{name}.json').read_text())
        svg,facts=build_svg(name,layout)
        d=OUT/name; d.mkdir(parents=True,exist_ok=True)
        svg_path=d/'reference.svg'; png=d/'reference.png'; jpeg=d/'reference.jpeg'; pdf=d/'reference.pdf'
        svg_path.write_text(svg,encoding='utf-8')
        run(['inkscape',str(svg_path),'--export-type=png',f'--export-dpi={DPI:g}',f'--export-filename={png}'])
        run(['inkscape',str(svg_path),'--export-type=pdf',f'--export-filename={pdf}'])
        with Image.open(png) as im:
            expected=(round(px_from_mm(facts['page_width_mm'])),round(px_from_mm(facts['page_height_mm'])))
            if im.size != expected: raise RuntimeError(f'{name}: PNG geometry {im.size} != {expected}')
            rgb=im.convert('RGB')
            rgb.save(jpeg,'JPEG',quality=95,subsampling=0,optimize=False,progressive=False,dpi=(int(DPI),int(DPI)))
            png_size=list(im.size)
        with Image.open(jpeg) as im:
            if im.size != expected: raise RuntimeError(f'{name}: JPEG geometry {im.size} != {expected}')
            jpeg_size=list(im.size)
        info=run(['pdfinfo',str(pdf)])
        page_line=next((line for line in info.splitlines() if line.startswith('Page size:')), '')
        manifest['fixtures'][name]={**facts,'png_px':png_size,'pdf_page':page_line,
                                    'svg_sha256':sha256(svg_path),'png_sha256':sha256(png),'jpeg_px':jpeg_size,'jpeg_sha256':sha256(jpeg),'pdf_sha256':sha256(pdf)}
    (OUT/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,sort_keys=True,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'renderer':inkscape,'font_sha256':manifest['font']['sha256'],'fixtures':len(names)},indent=2))

if __name__=='__main__': main()
