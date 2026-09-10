#!/usr/bin/env python3
"""Generate the independent Schema-4 renderer acceptance oracle.

Legacy seven-fixture golden files remain untouched. This script adds only the
four Schema-4 references and a combined acceptance manifest.
"""
from __future__ import annotations
import base64, hashlib, html, json, os, shutil, subprocess
from pathlib import Path
import PIL
from PIL import Image, ImageFont, features

ROOT=Path(__file__).resolve().parents[2]
FIX=ROOT/'tests'/'fixtures'/'rendering'
REF=FIX/'reference'
FONT=Path('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')
SIGNER=ROOT/'admin'/'assets'/'certloom-preview-signer-signature.png'
DPI=96.0
SOURCE_DATE_EPOCH='1700000000'
NAMES=('schema4-rich-text','schema4-signer','schema4-table','schema4-data-table')
RICH_NAME='Alexandria Noor Rahman'
RESULT_ROWS=(
 {'subject':'Mathematics','score':'98','grade':'A+'},
 {'subject':'Physics','score':'92','grade':'A'},
 {'subject':'Computer Science','score':'96','grade':'A+'},
 {'subject':'English','score':'91','grade':'A'},
)

def sha(p:Path)->str:
 h=hashlib.sha256();
 with p.open('rb') as f:
  for c in iter(lambda:f.read(1024*1024),b''): h.update(c)
 return h.hexdigest()

def run(cmd):
 env=os.environ.copy(); env['SOURCE_DATE_EPOCH']=SOURCE_DATE_EPOCH
 p=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env)
 if p.returncode: raise RuntimeError(f"command failed ({p.returncode}): {' '.join(map(str,cmd))}\n{p.stdout}\n{p.stderr}")
 return p.stdout.strip()

def mm_px(mm:float)->float: return mm*DPI/25.4

def color(v:str)->str:
 v=str(v or '#000000'); return v[:7] if len(v)>=7 else '#000000'

def font_px(pt:float)->int: return max(1,round(pt*DPI/72.0))

def text_width(text:str,pt:float,spacing_pt:float=0.0)->float:
 f=ImageFont.truetype(str(FONT),font_px(pt))
 box=f.getbbox(text); w=max(0,box[2]-box[0])
 if spacing_pt and len(text)>1: w+=(len(text)-1)*spacing_pt*DPI/72.0
 return w*25.4/DPI

def text_node(text:str,x:float,y:float,w:float,h:float,pt:float,fill:str,align='left',spacing_pt=0.0)->str:
 if align=='center': tx=x+w/2; anchor='middle'
 elif align=='right': tx=x+w; anchor='end'
 else: tx=x; anchor='start'
 ty=y+h/2
 spacing=f' letter-spacing="{spacing_pt*25.4/72.0:.8f}"' if abs(spacing_pt)>1e-9 else ''
 return (f'<text x="{tx:.8f}" y="{ty:.8f}" text-anchor="{anchor}" dominant-baseline="middle" '
         f'font-family="DejaVu Sans" font-size="{pt*25.4/72.0:.8f}" font-weight="400" fill="{fill}"{spacing}>'
         f'{html.escape(text)}</text>')

def rich_svg(e:dict)->tuple[str,dict]:
 st=e['style']; x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm']); pt=float(st['font_size_pt'])
 values=[]
 for r in e['runs']:
  text=RICH_NAME if r['kind']=='token' else r['text']; rs={**st,**(r.get('style') or {})}; values.append((text,rs))
 total=sum(text_width(t,pt,float(rs.get('letter_spacing_pt',0))) for t,rs in values)
 cursor=x+(w-total)/2
 nodes=[]
 for t,rs in values:
  rw=text_width(t,pt,float(rs.get('letter_spacing_pt',0)))
  nodes.append(text_node(t,cursor,y,rw,h,pt,color(rs.get('color','#111111')),'left',float(rs.get('letter_spacing_pt',0))))
  cursor+=rw
 return '<g>'+''.join(nodes)+'</g>', {'resolved_text':''.join(t for t,_ in values),'runs':len(values),'font_size_pt':pt}

def signer_svg(e:dict)->tuple[str,dict]:
 data=base64.b64encode(SIGNER.read_bytes()).decode('ascii'); x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm']); rot=float(e.get('rotation_deg',0)); cx=x+w/2; cy=y+h/2
 tr=f' transform="rotate({rot:g} {cx:g} {cy:g})"' if abs(rot)>1e-9 else ''
 node=f'<image x="{x:g}" y="{y:g}" width="{w:g}" height="{h:g}" preserveAspectRatio="xMidYMid meet" href="data:image/png;base64,{data}"{tr}/>'
 return node, {'asset_sha256':sha(SIGNER),'source':'signer.signature','rotation_deg':rot}

def table_svg(e:dict, dynamic:bool)->tuple[str,dict]:
 x=float(e['x_mm']); y=float(e['y_mm']); w=float(e['width_mm']); h=float(e['height_mm']); st=e['style']; cols=e['columns']
 rows=list(RESULT_ROWS if dynamic else e['rows']); row_slots=int(e.get('max_rows',len(rows))) if dynamic else len(rows); visual=row_slots+(1 if e.get('show_header') else 0); rh=h/max(1,visual)
 pad=float(st.get('cell_padding_mm',1.5)); border=color(st.get('border_color','#D1D5DB')); bw=float(st.get('border_width_mm',0.2)); pt=float(st.get('font_size_pt',10)); nodes=[]
 widths=[w*float(c['width_pct'])/100.0 for c in cols]
 def add_row(idx, vals, header=False):
  cy=y+idx*rh; cx=x; fill=color(st.get('header_fill','#F3F4F6') if header else (st.get('row_fill','#FFFFFF') if (idx-(1 if e.get('show_header') else 0))%2==0 else st.get('alternate_row_fill','#F9FAFB')))
  tcolor=color(st.get('header_color','#111827') if header else st.get('color','#111827'))
  for c,cw in zip(cols,widths):
   nodes.append(f'<rect x="{cx:.8f}" y="{cy:.8f}" width="{cw:.8f}" height="{rh:.8f}" fill="{fill}" stroke="{border}" stroke-width="{bw:.8f}"/>')
   val=str(c.get('label','')) if header else str(vals.get(c['key'],'') if not dynamic else vals.get(c.get('field',''),'') )
   nodes.append(text_node(val,cx+pad,cy+pad,cw-2*pad,rh-2*pad,pt,tcolor,str(c.get('align','left'))))
   cx+=cw
 ri=0
 if e.get('show_header'): add_row(ri,{},True); ri+=1
 for r in rows[:row_slots]: add_row(ri,r,False); ri+=1
 return '<g>'+''.join(nodes)+'</g>', {'rows':len(rows),'row_slots':row_slots,'row_height_mm':rh,'columns':len(cols),'source':e.get('source') if dynamic else 'literal'}

def build(name:str, layout:dict):
 page=layout['page']; w=float(page['width_mm']); h=float(page['height_mm']); nodes=[f'<rect x="0" y="0" width="{w:g}" height="{h:g}" fill="#fff"/>']; facts=[]
 for e in sorted(layout['elements'],key=lambda z:int(z.get('z',0))):
  if e['type']=='rich_text': node,f=rich_svg(e)
  elif e['type']=='signature': node,f=signer_svg(e)
  elif e['type']=='table': node,f=table_svg(e,False)
  elif e['type']=='data_table': node,f=table_svg(e,True)
  else: raise RuntimeError('unsupported schema4 oracle type '+str(e['type']))
  nodes.append(node); facts.append({'id':e['id'],'type':e['type'],**f})
 svg=f'<svg xmlns="http://www.w3.org/2000/svg" width="{w:g}mm" height="{h:g}mm" viewBox="0 0 {w:g} {h:g}">'+''.join(nodes)+'</svg>'
 return svg,{'page_width_mm':w,'page_height_mm':h,'elements':facts}

def main():
 if not FONT.is_file() or sha(FONT)!='57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e': raise SystemExit('exact reference font unavailable')
 if not SIGNER.is_file(): raise SystemExit('signer fixture asset unavailable')
 if not shutil.which('inkscape') or not shutil.which('pdfinfo'): raise SystemExit('inkscape/pdfinfo required')
 if not features.check('raqm'): raise SystemExit('Pillow RAQM required')
 legacy_path=REF/'manifest.json'; legacy=json.loads(legacy_path.read_text())
 combined=json.loads(legacy_path.read_text()); combined['schema']='certloom-renderer-acceptance-reference'; combined['version']=5; combined['legacy_manifest_sha256']=sha(legacy_path); combined['schema4_fixtures']=list(NAMES); combined.setdefault('assets',{})['signer']={'sha256':sha(SIGNER),'mime':'image/png'}
 for name in NAMES:
  layout=json.loads((FIX/f'{name}.json').read_text()); svg,facts=build(name,layout); d=REF/name; d.mkdir(parents=True,exist_ok=True); sp=d/'reference.svg'; pp=d/'reference.png'; jp=d/'reference.jpeg'; pdf=d/'reference.pdf'; sp.write_text(svg,encoding='utf-8')
  run(['inkscape',str(sp),'--export-type=png',f'--export-dpi={DPI:g}',f'--export-filename={pp}']); run(['inkscape',str(sp),'--export-type=pdf',f'--export-filename={pdf}'])
  expected=(round(mm_px(facts['page_width_mm'])),round(mm_px(facts['page_height_mm'])))
  with Image.open(pp) as im:
   if im.size!=expected:
    raise RuntimeError(f'{name} png geometry mismatch')
   im.convert('RGB').save(jp,'JPEG',quality=95,subsampling=0,optimize=False,progressive=False,dpi=(96,96))
   png_size=list(im.size)
  with Image.open(jp) as im:
   if im.size!=expected:
    raise RuntimeError(f'{name} jpeg geometry mismatch')
   jpeg_size=list(im.size)
  info=run(['pdfinfo',str(pdf)]); page_line=next((z for z in info.splitlines() if z.startswith('Page size:')),'')
  combined['fixtures'][name]={**facts,'png_px':png_size,'pdf_page':page_line,'svg_sha256':sha(sp),'png_sha256':sha(pp),'jpeg_px':jpeg_size,'jpeg_sha256':sha(jp),'pdf_sha256':sha(pdf)}
 out=REF/'acceptance-manifest.json'; out.write_text(json.dumps(combined,ensure_ascii=False,sort_keys=True,indent=2)+'\n',encoding='utf-8')
 print(json.dumps({'fixtures':len(combined['fixtures']),'schema4':list(NAMES),'legacy_manifest_sha256':combined['legacy_manifest_sha256'],'acceptance_manifest_sha256':sha(out)},indent=2))

if __name__=='__main__': main()
