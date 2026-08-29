#!/usr/bin/env python3
"""Real Chromium gate for the canonical 1.6.55 Template Preview presenter."""
import asyncio
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BUILD = ROOT / 'tests/browser/build_template_preview_fixture.php'
SCENARIOS = [(320, 900), (768, 900), (1440, 1000)]


def build_html():
    proc = subprocess.run(['php', str(BUILD)], cwd=ROOT, capture_output=True, text=True, check=False)
    if proc.returncode:
        raise RuntimeError(proc.stderr or proc.stdout or 'fixture builder failed')
    return proc.stdout


async def run():
    try:
        from playwright.async_api import async_playwright
    except Exception as exc:
        print('SKIP  Playwright unavailable:', exc)
        return 0
    html = build_html()
    failed = 0
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        for width, height in SCENARIOS:
            page = await browser.new_page(viewport={'width': width, 'height': height})
            await page.set_content(html, wait_until='load')
            raw = await page.locator('#certloom-browser-result').text_content()
            result = json.loads(raw or '{}')
            checks = result.get('checks', {})
            bad = [name for name, ok in checks.items() if not ok]
            if bad:
                failed += 1
                print(f'FAIL  Template Preview viewport {width}px: ' + ', '.join(bad))
            else:
                print(f'PASS  Template Preview viewport {width}px: {len(checks)} checks')
            await page.close()
        media_page = await browser.new_page(viewport={'width':768,'height':900})
        await media_page.emulate_media(reduced_motion='reduce')
        await media_page.set_content(html, wait_until='load')
        media = await media_page.evaluate("""() => ({
          reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
          focusableScroll: document.querySelector('.certloom-template-preview-scroll').tabIndex === 0,
          noTransition: getComputedStyle(document.querySelector('.certloom-template-preview-page')).transitionDuration === '0s',
          noAnimation: getComputedStyle(document.querySelector('.certloom-template-preview-page')).animationName === 'none'
        })""")
        bad = [name for name, ok in media.items() if not ok]
        if bad:
            failed += 1
            print('FAIL  Template Preview accessibility media: ' + ', '.join(bad))
        else:
            print(f'PASS  Template Preview accessibility media: {len(media)} checks')
        await media_page.close()
        await browser.close()
    print(f'\n{len(SCENARIOS)} Preview viewport scenarios + accessibility media, {failed} failures')
    return 1 if failed else 0

if __name__ == '__main__':
    raise SystemExit(asyncio.run(run()))
