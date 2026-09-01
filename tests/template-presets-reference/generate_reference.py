#!/usr/bin/env python3
"""Development-only visual reference generator for Certloom factory presets.

Never shipped in the installable plugin and never used as credential authority.
It renders the canonical factory layouts with local development-only tools so
future renderer/designer changes can be compared against a deterministic visual
baseline.
"""
from __future__ import annotations
import hashlib, json, os, re, shutil, subprocess
from pathlib import Path
from PIL import Image, ImageDraw
import qrcode
from qrcode.constants import ERROR_CORRECT_H

ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'tests'/'fixtures'/'templates'/'reference'
DPI=72.0
SOURCE_DATE_EPOCH='1700000000'
VERIFY_URL='https://example.test/credential/7M4K-2Q8D-F3XP'
MERGES={
 'recipient.name':'Alexandria Noor Rahman',
 'program.name':'Professional Credential Program',
 'issuer.name':'Northstar Learning Institute',
 'credential.display_number':'CERT-2026-000184',
 'credential.issue_date':'24 August 2026',
}

def run(cmd):
    env=os.environ.copy(); env['SOURCE_DATE_EPOCH']=SOURCE_DATE_EPOCH
    p=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env)
    if p.returncode: raise RuntimeError(f"command failed: {' '.join(cmd)}\n{p.stdout}\n{p.stderr}")
    return p.stdout.strip()

def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def esc(s): return str(s).replace('&','&amp;').replace('<','&lt;').replace('>','&gt;').replace('"','&quot;')
def mm_px(v): return round(float(v)*DPI/25.4)
def rgb(c): return c[:7]

def text_node(e,text):
    st=e.get('style') or {}; x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm'])
    align=st.get('align','center'); tx=x+w/2 if align=='center' else (x+w if align in ('right','end') else x); anchor='middle' if align=='center' else ('end' if align in ('right','end') else 'start')
    ty=y+h/2; pt=float(st.get('font_size_pt',18)); size=pt*25.4/72.0; color=rgb(st.get('color','#000000')); weight=int(st.get('font_weight',400)); opacity=float(e.get('opacity',1))
    letter=float(st.get('letter_spacing_pt',0))*25.4/72.0
    extra=f' letter-spacing="{letter:.4f}"' if abs(letter)>1e-9 else ''
    return f'<text x="{tx:g}" y="{ty:g}" text-anchor="{anchor}" dominant-baseline="middle" font-family="DejaVu Sans" font-size="{size:.8f}" font-weight="{weight}" fill="{color}" opacity="{opacity:g}"{extra}>{esc(text)}</text>'

def shape_node(e):
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm']); fill=e.get('fill','#00000000'); stroke=e.get('stroke','#00000000'); sw=float(e.get('stroke_width_mm',0)); opacity=float(e.get('opacity',1))
    fill_rgb=rgb(fill); fill_op=(int(fill[7:9],16)/255.0 if len(fill)==9 else 1.0)*opacity
    stroke_rgb=rgb(stroke); stroke_op=(int(stroke[7:9],16)/255.0 if len(stroke)==9 else 1.0)*opacity
    if e.get('shape')=='ellipse': return f'<ellipse cx="{x+w/2:g}" cy="{y+h/2:g}" rx="{w/2:g}" ry="{h/2:g}" fill="{fill_rgb}" fill-opacity="{fill_op:.5f}" stroke="{stroke_rgb}" stroke-opacity="{stroke_op:.5f}" stroke-width="{sw:g}"/>'
    return f'<rect x="{x:g}" y="{y:g}" width="{w:g}" height="{h:g}" fill="{fill_rgb}" fill-opacity="{fill_op:.5f}" stroke="{stroke_rgb}" stroke-opacity="{stroke_op:.5f}" stroke-width="{sw:g}"/>'

def line_node(e):
    x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm']); return f'<line x1="{x:g}" y1="{y:g}" x2="{x+w:g}" y2="{y+h:g}" stroke="{rgb(e.get("stroke","#000000"))}" stroke-width="{float(e.get("stroke_width_mm",.3)):g}"/>'

def qr_node(e):
    qr=qrcode.QRCode(error_correction=ERROR_CORRECT_H,box_size=1,border=0); qr.add_data(VERIFY_URL); qr.make(fit=True); m=qr.get_matrix(); n=len(m); quiet=int(e.get('quiet_zone_modules',4)); total=n+2*quiet
    x=float(e['x_mm']); y=float(e['y_mm']); side=float(e['width_mm']); unit=side/total; out=[]
    for yy,row in enumerate(m):
        for xx,on in enumerate(row):
            if on: out.append(f'<rect x="{x+(xx+quiet)*unit:.8f}" y="{y+(yy+quiet)*unit:.8f}" width="{unit:.8f}" height="{unit:.8f}" fill="#000000"/>')
    return '<g>'+''.join(out)+'</g>'

def svg_for(defn):
    l=defn['layout']; p=l['page']; w=float(p['width_mm']); h=float(p['height_mm']); nodes=[f'<rect width="{w:g}" height="{h:g}" fill="#FFFFFF"/>']
    for e in sorted(l['elements'],key=lambda x:int(x.get('z',0))):
        t=e['type']
        if t=='shape': nodes.append(shape_node(e))
        elif t=='line': nodes.append(line_node(e))
        elif t=='text': nodes.append(text_node(e,e.get('text','')))
        elif t=='merge': nodes.append(text_node(e,MERGES.get(e.get('token',''),e.get('token',''))))
        elif t=='qr': nodes.append(qr_node(e))
    return f'<svg xmlns="http://www.w3.org/2000/svg" width="{w:g}mm" height="{h:g}mm" viewBox="0 0 {w:g} {h:g}">'+''.join(nodes)+'</svg>'

def main():
    if not shutil.which('inkscape'): raise SystemExit('inkscape required')
    data=json.loads(run(['php',str(ROOT/'tests'/'dump-template-presets.php')]))
    OUT.mkdir(parents=True,exist_ok=True)
    manifest={'schema':'certloom-template-preset-reference','version':1,'catalog_version':data['catalog_version'],'renderer':run(['inkscape','--version']).splitlines()[0],'dpi':DPI,'source_date_epoch':SOURCE_DATE_EPOCH,'presets':{}}
    thumbs=[]
    for d in data['presets']:
        key=d['preset_key']; folder=OUT/key; folder.mkdir(parents=True,exist_ok=True); svg=folder/'reference.svg'; png=folder/'reference.png'; svg.write_text(svg_for(d),encoding='utf-8')
        run(['inkscape',str(svg),'--export-type=png',f'--export-dpi={DPI:g}',f'--export-filename={png}'])
        with Image.open(png) as im:
            expected=(mm_px(d['layout']['page']['width_mm']),mm_px(d['layout']['page']['height_mm']))
            if im.size!=expected: raise RuntimeError(f'{key} geometry {im.size}!={expected}')
            thumbs.append((key,im.convert('RGB').copy()))
            size=list(im.size)
        manifest['presets'][key]={'family':d['family_key'],'variant':d['variant_key'],'layout_sha256':d['layout_sha256'],'svg_sha256':sha(svg),'png_sha256':sha(png),'png_px':size,'elements':len(d['layout']['elements'])}
    # Contact sheet is a review aid, not part of the hash contract.
    tile_w,tile_h=420,330; cols=4; rows=(len(thumbs)+cols-1)//cols
    sheet=Image.new('RGB',(cols*tile_w,rows*tile_h),'white'); draw=ImageDraw.Draw(sheet)
    for i,(key,img) in enumerate(thumbs):
        img.thumbnail((tile_w-20,tile_h-45)); x=(i%cols)*tile_w+(tile_w-img.width)//2; y=(i//cols)*tile_h+25; sheet.paste(img,(x,y)); draw.text(((i%cols)*tile_w+10,(i//cols)*tile_h+5),key,fill='black')
    sheet.save(OUT/'contact-sheet.png',optimize=True)
    (OUT/'manifest.json').write_text(json.dumps(manifest,sort_keys=True,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'presets':len(thumbs),'families':len(set(v['family'] for v in manifest['presets'].values())),'manifest_sha256':sha(OUT/'manifest.json')},indent=2))
if __name__=='__main__': main()
