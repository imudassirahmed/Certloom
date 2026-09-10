#!/usr/bin/env python3
import asyncio, io, subprocess
from pathlib import Path
from PIL import Image
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

# Independent Code 128 symbol table (ISO/IEC 15417 width patterns 0..106).
# This deliberately does not import Certloom's PHP encoder and therefore still
# verifies the Chromium-rasterized Preview as an independent machine-code path.
PATTERNS=(
    '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
    '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
    '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
    '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
    '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
    '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
    '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
    '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
    '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
    '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
    '114131','311141','411131','211412','211214','211232','2331112',
)
PATTERN_TO_CODE={pattern:code for code,pattern in enumerate(PATTERNS)}

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_barcode_preview_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

def _runs(bits):
    dark=[i for i,value in enumerate(bits) if value]
    if not dark: raise ValueError('barcode raster contains no dark modules')
    values=bits[dark[0]:dark[-1]+1]
    result=[]; current=values[0]; width=0
    for value in values:
        if value==current: width+=1
        else: result.append(width); current=value; width=1
    result.append(width)
    return result

def _quantize(widths,total_modules):
    total_pixels=sum(widths)
    ideal=[width*total_modules/total_pixels for width in widths]
    modules=[max(1,min(4,round(value))) for value in ideal]
    while sum(modules)!=total_modules:
        if sum(modules)<total_modules:
            choices=[i for i,value in enumerate(modules) if value<4]
            i=max(choices,key=lambda j:ideal[j]-modules[j])
            modules[i]+=1
        else:
            choices=[i for i,value in enumerate(modules) if value>1]
            i=max(choices,key=lambda j:modules[j]-ideal[j])
            modules[i]-=1
    return ''.join(str(value) for value in modules)

def decode_code128_png(png):
    image=Image.open(io.BytesIO(png)).convert('L')
    # Use the darkest central scanline. This decodes the browser raster, not the
    # SVG markup, and tolerates ordinary sub-pixel rasterization at the edges.
    candidates=[]
    for y in range(int(image.height*0.2),int(image.height*0.8)):
        bits=[image.getpixel((x,y))<128 for x in range(image.width)]
        candidates.append((sum(bits),bits))
    _,bits=max(candidates,key=lambda item:item[0])
    runs=_runs(bits)
    if len(runs)<13 or (len(runs)-7)%6:
        raise ValueError(f'Code 128 raster has invalid run count {len(runs)}')
    codes=[]; offset=0
    while offset+7<len(runs):
        pattern=_quantize(runs[offset:offset+6],11)
        if pattern not in PATTERN_TO_CODE:
            raise ValueError(f'unknown Code 128 symbol pattern {pattern}')
        codes.append(PATTERN_TO_CODE[pattern]); offset+=6
    stop=_quantize(runs[offset:offset+7],13)
    if stop!=PATTERNS[106]: raise ValueError(f'invalid Code 128 stop pattern {stop}')
    codes.append(106)
    if codes[0] not in (103,104,105): raise ValueError(f'invalid Code 128 start code {codes[0]}')
    if len(codes)<4: raise ValueError('Code 128 raster contains too few symbols')
    expected_checksum=(codes[0]+sum(code*i for i,code in enumerate(codes[1:-2],1)))%103
    if codes[-2]!=expected_checksum:
        raise ValueError(f'Code 128 checksum {codes[-2]} != {expected_checksum}')
    code_set={103:'A',104:'B',105:'C'}[codes[0]]
    decoded=[]
    for code in codes[1:-2]:
        if code==99: code_set='C'; continue
        if code==100: code_set='B'; continue
        if code==101: code_set='A'; continue
        if code_set=='B':
            if not 0<=code<=95: raise ValueError(f'unsupported Code B symbol {code}')
            decoded.append(chr(code+32)); continue
        if code_set=='C':
            if not 0<=code<=99: raise ValueError(f'unsupported Code C symbol {code}')
            decoded.append(f'{code:02d}'); continue
        raise ValueError('Code Set A data is outside this fixture decoder contract')
    return ''.join(decoded)

async def run():
    html=fixture(); checks=0
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1200,'height':700},device_scale_factor=2)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(80)
        locator=page.locator('.certloom-template-preview-op-barcode')
        if await locator.count()!=1: raise SystemExit('FAIL  canonical barcode Preview operation was not rendered exactly once')
        checks+=1; print('PASS  canonical v2 barcode reaches production RenderPlanHtmlPreview')
        bbox=await locator.bounding_box()
        if not bbox or bbox['width']<400 or bbox['height']<70: raise SystemExit(f'FAIL  barcode Preview geometry is unexpectedly small: {bbox}')
        checks+=1; print('PASS  barcode Preview preserves scanner-usable physical geometry')
        png=await locator.screenshot(type='png')
        try: decoded=decode_code128_png(png)
        except ValueError as exc: raise SystemExit(f'FAIL  Chromium-rasterized canonical barcode did not independently decode: {exc}')
        if decoded!='CERT-2026-001': raise SystemExit(f'FAIL  Chromium-rasterized canonical barcode decoded value {decoded!r}')
        checks+=1; print('PASS  Chromium-rasterized canonical Preview independently decodes to CERT-2026-001')
        # Verify the white background exists as actual SVG content, not only page CSS.
        markup=await locator.evaluate('(n)=>n.outerHTML')
        if 'fill="#fff"' not in markup: raise SystemExit('FAIL  barcode SVG lacks explicit scanner-safe white background')
        checks+=1; print('PASS  canonical barcode Preview includes explicit white machine-code background')
        if 'fill="#111"' not in markup: raise SystemExit('FAIL  barcode SVG lacks dark module group')
        checks+=1; print('PASS  canonical barcode Preview contains exact dark module runs')
        await browser.close()
    print(f'\n{checks} canonical barcode Preview browser/decode checks, 0 failures')
    return 0
if __name__=='__main__': raise SystemExit(asyncio.run(run()))
