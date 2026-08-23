#!/usr/bin/env python3
"""Optional real-browser Phase 2 resilience gate. Requires Python Playwright + Chromium."""
import asyncio
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HTML = (ROOT / 'tests/browser/phase2-resilience.html').read_text(encoding='utf-8')
CSS = (ROOT / 'public/assets/certloom-public.css').read_text(encoding='utf-8')
HTML = HTML.replace('<link rel="stylesheet" href="../../public/assets/certloom-public.css">', '<style>' + CSS + '</style>')
SCENARIOS = [(320, 900, 'ltr'), (768, 900, 'ltr'), (1440, 1000, 'ltr'), (320, 900, 'rtl')]

async def run():
    try:
        from playwright.async_api import async_playwright
    except Exception as exc:
        print('SKIP  Playwright unavailable:', exc)
        return 0
    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            executable_path='/usr/bin/chromium',
            args=['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
        )
        failed = 0
        for width, height, direction in SCENARIOS:
            page = await browser.new_page(viewport={'width': width, 'height': height})
            await page.set_content(HTML, wait_until='load')
            if direction == 'rtl':
                await page.evaluate("document.documentElement.setAttribute('dir','rtl'); document.getElementById('certloom-browser-result').textContent='';")
                await page.evaluate("window.dispatchEvent(new Event('resize'))")
                # Re-run the fixture checks after changing writing direction.
                await page.evaluate("var s=document.querySelector('script:last-of-type'); if(s){eval(s.textContent)}")
            raw = await page.locator('#certloom-browser-result').text_content()
            result = json.loads(raw or '{}')
            checks = result.get('checks', {})
            bad = [name for name, ok in checks.items() if not ok]
            if bad:
                failed += 1
                print(f'FAIL  viewport {width}px {direction}: ' + ', '.join(bad))
            else:
                print(f'PASS  viewport {width}px {direction}: {len(checks)} checks')
            await page.close()
        media_page = await browser.new_page(viewport={'width': 768, 'height': 900})
        await media_page.emulate_media(reduced_motion='reduce', forced_colors='active')
        await media_page.set_content(HTML, wait_until='load')
        media_checks = await media_page.evaluate("""() => {
            document.querySelector('.cv-search-btn').classList.add('cv-loading');
            document.querySelector('.cvx-search-btn').classList.add('cvx-loading');
            return {
                reducedMotionQuery: matchMedia('(prefers-reduced-motion: reduce)').matches,
                forcedColorsQuery: matchMedia('(forced-colors: active)').matches,
                legacyAnimationStopped: getComputedStyle(document.querySelector('.cv-arrow')).animationName === 'none',
                modernAnimationStopped: getComputedStyle(document.querySelector('.cvx-arrow')).animationName === 'none',
                forcedColorActionBorder: parseFloat(getComputedStyle(document.querySelector('.certloom-result-action')).borderWidth) >= 1
            };
        }""")
        media_bad = [name for name, ok in media_checks.items() if not ok]
        if media_bad:
            failed += 1
            print('FAIL  accessibility media: ' + ', '.join(media_bad))
        else:
            print(f'PASS  accessibility media: {len(media_checks)} checks')
        await media_page.close()
        await browser.close()
        print(f'\n{len(SCENARIOS)} browser scenarios + accessibility media, {failed} failures')
        return 1 if failed else 0

if __name__ == '__main__':
    raise SystemExit(asyncio.run(run()))
