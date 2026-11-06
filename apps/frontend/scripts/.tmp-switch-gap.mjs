import { chromium } from 'playwright';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1200, height: 1000 } });
await page.goto('http://127.0.0.1:5173/settings/components', { waitUntil: 'networkidle' });
const input = page.locator('#demo-form-updates');
if (!(await input.isChecked())) await input.click();
const result = await input.locator('xpath=..').evaluate((label) => {
  const track = label.querySelector('.app-switch__track').getBoundingClientRect();
  const knob = label.querySelector('.app-switch__thumb').getBoundingClientRect();
  return {
    top: knob.top - track.top,
    bottom: track.bottom - knob.bottom,
    left: knob.left - track.left,
    right: track.right - knob.right,
    track: { width: track.width, height: track.height },
    knob: { width: knob.width, height: knob.height },
  };
});
if (Math.abs(result.top - result.bottom) > 0.5 || Math.abs(result.bottom - result.right) > 0.5) {
  throw new Error(`Switch gaps are not uniform on the active side: ${JSON.stringify(result)}`);
}
console.log(JSON.stringify({ passed: true, result }));
await browser.close();
