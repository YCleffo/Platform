import { launchBrowser, navigateClientSide } from './e2e-support.mjs';
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 800 } });
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en');
  localStorage.removeItem('platform.admin.sidebar.width');
  localStorage.removeItem('platform.admin.sidebar.collapsed');
});
const page = await context.newPage();
await page.goto('http://127.0.0.1:4176/admin/login', { waitUntil: 'networkidle' });
await page.locator('#admin-identifier').fill('ura.nikogosuan@gmail.com');
await page.locator('#admin-password').fill('OwnerAdmin2026!');
await page.getByRole('button', { name: 'Sign in', exact: true }).click();
await page.waitForURL('**/admin', { timeout: 20_000 });
await page.getByRole('heading', { name: 'Platform overview' }).waitFor({ timeout: 20_000 });
await navigateClientSide(page, '/admin/accounts');
await page.locator('.admin-resource__table tbody tr').first().waitFor({ timeout: 20_000 });

const layout = await page.evaluate(() => {
  const mark = document.querySelector('.admin__mark')?.getBoundingClientRect();
  const topbar = document.querySelector('.admin__topbar')?.getBoundingClientRect();
  return {
    pageScrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    markCenterY: mark ? mark.top + mark.height / 2 : null,
    topbarCenterY: topbar ? topbar.top + topbar.height / 2 : null,
  };
});

await page.getByRole('button', { name: 'Collapse sidebar' }).click();
await page.waitForTimeout(300);
const collapsed = await page.evaluate(() => {
  const icon = document.querySelector('.admin__link svg')?.getBoundingClientRect();
  const resize = document.querySelector('.admin__resize')?.getBoundingClientRect();
  return { iconWidth: icon?.width, handleVisible: Boolean(resize && resize.width > 0) };
});

const resizeHandle = page.locator('.admin__resize');
let box = await resizeHandle.boundingBox();
await page.mouse.move((box?.x ?? 0) + 3, (box?.y ?? 0) + 200);
await page.mouse.down();
await page.mouse.move(300, 300, { steps: 6 });
await page.mouse.up();
await page.waitForTimeout(200);
const afterCollapsedDrag = (await page.locator('.admin__sidebar').boundingBox())?.width;

box = await resizeHandle.boundingBox();
await page.mouse.move((box?.x ?? 0) + 3, (box?.y ?? 0) + 200);
await page.mouse.down();
await page.mouse.move(320, 300, { steps: 6 });
await page.mouse.up();
await page.waitForTimeout(150);
const afterWideDrag = (await page.locator('.admin__sidebar').boundingBox())?.width;
await page.mouse.move(100, 300);
await page.waitForTimeout(150);
const afterIdleMove = (await page.locator('.admin__sidebar').boundingBox())?.width;

box = await resizeHandle.boundingBox();
await page.mouse.move((box?.x ?? 0) + 3, (box?.y ?? 0) + 200);
await page.mouse.down();
await page.mouse.move(340, 300, { steps: 6 });
await page.evaluate(() => window.dispatchEvent(new Event('blur')));
await page.waitForTimeout(150);
const afterCancel = (await page.locator('.admin__sidebar').boundingBox())?.width;
await page.mouse.up();

const label = await page.evaluate(() => {
  const el = document.querySelector('.admin__link-label');
  if (!el) return null;
  return { width: el.getBoundingClientRect().width, scrollWidth: el.scrollWidth, overflow: getComputedStyle(el).textOverflow };
});

await page.locator('#admin-page-size').click();
const menu = await page.evaluate(() => {
  const panel = document.querySelector('.app-menu__panel');
  const item = panel?.querySelector('.app-menu__item');
  return { panelWidth: panel?.getBoundingClientRect().width, itemHeight: item?.getBoundingClientRect().height, itemFont: item ? getComputedStyle(item).fontSize : null };
});
await page.screenshot({ path: 'screenshots/admin-sidebar-final.png' });
console.log(JSON.stringify({ layout, collapsed, afterCollapsedDrag, afterWideDrag, afterIdleMove, afterCancel, label, menu }, null, 2));
await browser.close();
