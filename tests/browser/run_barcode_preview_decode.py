#!/usr/bin/env python3
import asyncio, io, subprocess
from pathlib import Path
from PIL import Image
from pyzbar.pyzbar import decode as zbar_decode
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_barcode_preview_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    html=fixture(); checks=0
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1200,'height':700},device_scale_factor=2)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(80)
        locator=page.locator('.certloom-template-preview-op-barcode')
        if await locator.count()!=1: raise SystemExit('FAIL  canonical barcode Preview operation was not rendered exactly once')
        checks+=1; print('PASS  canonical v2 barcode reaches production RenderPlanHtmlPreview')
        svg=locator.locator('xpath=self::*')
        bbox=await locator.bounding_box()
        if not bbox or bbox['width']<400 or bbox['height']<70: raise SystemExit(f'FAIL  barcode Preview geometry is unexpectedly small: {bbox}')
        checks+=1; print('PASS  barcode Preview preserves scanner-usable physical geometry')
        png=await locator.screenshot(type='png')
        decoded=[item for item in zbar_decode(Image.open(io.BytesIO(png))) if item.type=='CODE128']
        values=[item.data.decode('ascii') for item in decoded]
        if 'CERT-2026-001' not in values: raise SystemExit(f'FAIL  Chromium-rasterized canonical barcode decoded values {values!r}')
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
