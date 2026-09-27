/* Run with playwright-cli run-code --filename in an authenticated Local tab.
 * Creates one QA recipient and one details-only credential on ecapla.local.
 * Leaves email empty and skips manual email review. Keep these local fixtures.
 */
async (page) => {
 if (page.url().split('/wp-admin/')[0] !== 'http://ecapla.local') throw new Error('Use the disposable ecapla.local site.');
 const checks = [];
 const check = (pass, message) => { if (!pass) throw new Error(message); checks.push(message); };
 await page.bringToFront();
 await page.setViewportSize({width:390, height:844});
 await page.goto('http://ecapla.local/wp-admin/admin.php?page=certloom-issuance', {waitUntil:'domcontentloaded'});
 const root = page.locator('.certloom-admin-shell');
 const next = page.getByRole('button', {name:'Continue', exact:true});
 await page.locator('.certloom-issue-stepper').waitFor();
 await next.click();
 check(await root.getByRole('status').textContent() === 'Choose an existing Recipient before continuing.', 'missing recipient is explained');
 await page.getByRole('button', {name:'New recipient', exact:true}).click();
 await page.getByRole('textbox', {name:/^Display name/}).fill('Lance receipt focus QA');
 await next.click();
 await page.locator('[data-issue-step="1"]:visible').waitFor();
 await page.getByRole('button', {name:'Independent credential', exact:true}).click();
 await next.click();
 const title = page.getByRole('textbox', {name:/^Credential title/});
 await title.fill('Lance receipt focus verification');
 await next.click();
 await page.getByRole('button', {name:'Back', exact:true}).click();
 check(await title.inputValue() === 'Lance receipt focus verification', 'Back preserves credential details');
 await next.click();
 for (const step of [3,4]) {
  await page.locator('[data-issue-step="'+step+'"]:visible').waitFor();
  await next.click();
 }
 await page.getByRole('button', {name:'Issue credential', exact:true}).waitFor();
 check((await root.ariaSnapshot()).includes('Skip manual review'), 'manual email review remains optional');
 await page.getByRole('button', {name:'Issue credential', exact:true}).click();
 const heading = page.getByRole('heading', {name:'Credential issued successfully', exact:true});
 await heading.waitFor();
 await page.waitForFunction(() => document.activeElement?.textContent === 'Credential issued successfully');
 check(await heading.evaluate(e => {const r=e.getBoundingClientRect();return r.top>=46 && r.bottom<=innerHeight;}), 'success heading is focused and visible after issuance');
 const receipt = root.locator('[data-issue-receipt]');
 const publicLink = receipt.getByRole('link', {name:'View credential', exact:true});
 const url = await publicLink.getAttribute('href');
 check(url.startsWith('http://ecapla.local/certloom/credential/id/'), 'receipt exposes a local public credential link');
 await page.screenshot({path:'output/playwright/lance-issued-focused-390.png', timeout:20000});
 return {checks:checks.length, results:checks, url, receipt:await receipt.ariaSnapshot()};
}
