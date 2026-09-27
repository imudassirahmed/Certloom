/* Run with playwright-cli run-code --filename on an authenticated, disposable
 * Local WordPress tab. Exercises the real editor without saving/publishing mail.
 * The same shared picker is used by Certificate Studio.
 */
async (page) => {
 const site = page.url().split('/wp-admin/')[0];
 if (site !== 'http://ecapla.local') throw new Error('Use the disposable ecapla.local site.');
 const checks = [];
 const check = (pass, message) => { if (!pass) throw new Error(message); checks.push(message); };
 await page.bringToFront();
 for (const width of [1536, 390]) {
  await page.setViewportSize({width, height: width === 390 ? 844 : 674});
  await page.goto(site + '/wp-admin/admin.php?page=certloom-email-designs&email_view=designer', {waitUntil:'domcontentloaded'});
  const field = page.locator('.certloom-designer-inspector .certloom-font-picker-field');
  const button = field.locator('.certloom-font-picker__button');
  await button.click();
  const panel = page.locator('.certloom-font-picker__panel:not([hidden])');
  check(await field.locator('select[data-certloom-email-font-select]').evaluate(e => e.hidden), width + ': native control hidden after enhancement');
  check(await panel.evaluate(e => {const r=e.getBoundingClientRect(); return r.left>=0 && r.top>=0 && r.right<=innerWidth && r.bottom<=innerHeight;}), width + ': font panel fits viewport');
  const search = page.getByRole('searchbox', {name:'Search fonts', exact:true});
  await search.press('ArrowDown');
  check(await page.evaluate(() => document.activeElement.getAttribute('role')==='option'), width + ': ArrowDown reaches a font');
  await page.keyboard.press('End');
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  check(await page.evaluate(() => document.activeElement.getAttribute('role')==='option'), width + ': virtual scrolling preserves keyboard focus');
  await page.keyboard.press('Escape');
  check(await panel.count()===0 && await button.evaluate(e => e===document.activeElement), width + ': Escape closes and restores focus');
  await button.click();
  await search.fill('Arial');
  await panel.locator('[data-font-key=arial]').click();
  check(await field.locator('select[data-certloom-email-font-select]').inputValue()==='Arial, Helvetica, sans-serif', width + ': font selection updates the native value');
  await field.locator('.certloom-font-picker__button').click();
  await search.fill('');
  await panel.getByRole('option', {name:/^Use email default/}).click();
  check(await field.locator('select[data-certloom-email-font-select]').inputValue()==='', width + ': inheritance can be restored');
 }
 return {checks:checks.length, results:checks};
}
