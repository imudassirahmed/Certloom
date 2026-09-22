#!/usr/bin/env python3
import asyncio
import base64
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
CSS_PATH = ROOT / 'admin/assets/menu-icon.css'
SVG_PATH = ROOT / 'admin/assets/certloom-menu-icon.svg'

async def run():
    checks = 0
    failed = []
    svg = SVG_PATH.read_bytes()
    data_uri = 'data:image/svg+xml;base64,' + base64.b64encode(svg).decode('ascii')
    css = CSS_PATH.read_text(encoding='utf-8').replace('url("certloom-menu-icon.svg")', f'url("{data_uri}")')
    html = f'''<!doctype html><html><head><meta charset="utf-8"><style>{css}\nbody{{margin:0;background:#1d2327;color:#a7aaad;font-family:Arial,sans-serif}}#toplevel_page_certloom{{width:160px;height:40px;display:flex;align-items:center;color:#a7aaad;background:#1d2327}}#toplevel_page_certloom:hover{{color:#72aee6}}.wp-menu-image{{width:36px;height:34px;position:relative}}.wp-menu-name{{padding-left:2px;font-size:14px}}</style></head><body><div id="toplevel_page_certloom"><div class="wp-menu-image"></div><div class="wp-menu-name">Certloom</div></div><img id="asset-probe" src="{data_uri}" alt="" style="position:absolute;width:1px;height:1px;left:-9999px;top:-9999px"></body></html>'''

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page = await browser.new_page(viewport={'width': 320, 'height': 160})
        await page.set_content(html, wait_until='load')

        async def ck(cond, label):
            nonlocal checks
            checks += 1
            if not cond:
                failed.append(label)

        probe = page.locator('#asset-probe')
        await ck(await probe.evaluate('e=>e.complete && e.naturalWidth>0 && e.naturalHeight>0'), 'uploadedSvgDecodesInChromium')
        icon = page.locator('#toplevel_page_certloom .wp-menu-image')
        before = await icon.evaluate("e=>{const s=getComputedStyle(e,'::before');return {content:s.content,width:s.width,height:s.height,top:s.top,left:s.left,margin:s.margin,transform:s.transform,bg:s.backgroundColor,mask:s.maskImage,webkit:s.webkitMaskImage}}")
        await ck(before['content'] not in ('none','normal',''), 'pseudoElementExists')
        await ck(before['width']=='18px' and before['height']=='20px', 'sidebarIconIsTenPercentSmaller')
        await ck(before['top']=='17px' and before['left']=='18px' and before['margin']=='0px', 'sidebarIconCenteredInWordPressSlot')
        await ck(before['transform']!='none', 'sidebarIconUsesCenteringTransform')
        await ck('data:image/svg+xml;base64,' in (before['mask'] or before['webkit']), 'sidebarMaskUsesUploadedSvg')
        await ck(before['bg'] == 'rgb(167, 170, 173)', 'sidebarIconFollowsCurrentColor')
        await page.locator('#toplevel_page_certloom').hover()
        hover_bg = await icon.evaluate("e=>getComputedStyle(e,'::before').backgroundColor")
        await ck(hover_bg == 'rgb(114, 174, 230)', 'sidebarIconFollowsWordPressHoverColor')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'noFixtureOverflow')
        await browser.close()

    if failed:
        print('FAIL  WordPress Certloom menu icon:', ', '.join(failed))
        print(f'\n{checks-len(failed)} passed, {len(failed)} failures')
        return 1
    print('PASS  WordPress Certloom menu icon: user-supplied SVG is centered, ten-percent smaller and follows WordPress currentColor states')
    print(f'\n{checks} menu-icon browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
