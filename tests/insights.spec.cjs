const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (r) =>
    r.request().url().startsWith('http://127.0.0.1:18782/') ? r.continue() : r.abort(),
  );
});
test('public board escapes names, preserves shared places and contains mobile table', async ({
  page,
}) => {
  await page.goto('/board.html');
  await expect(page.locator('.xp-period-board')).toContainText('合成用户 <img src=x>');
  await expect(page.locator('.xp-period-board img')).toHaveCount(0);
  expect(await page.locator('tbody tr td:first-child').allTextContents()).toEqual(['1', '1']);
  await expect(page.getByRole('link', { name: '下一页' })).toHaveAttribute(
    'href',
    '/board.html?xp_week_page=2',
  );
  await expect(page.locator('.xp-board-note')).toContainText('不含人工调分');
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
test('administrative metrics show zero-XP participation and explain task denominator', async ({
  page,
}) => {
  await page.goto('/insights.html');
  await expect(page.locator('.xp-insight-totals')).toContainText('参与人数');
  await expect(page.locator('.xp-insight-totals dd').first()).toHaveText('2');
  await expect(page.locator('.xp-card').nth(1)).toContainText('50.0%');
  await expect(page.locator('.xp-card').nth(1)).toContainText('这不是曝光转化率');
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
test('personal position switches periods, rejects stale replies and sends no owner', async ({
  page,
}) => {
  const state = JSON.parse(fs.readFileSync('.runtime/frontend/growth-state.json', 'utf8'));
  const calls = [];
  let releaseWeek;
  await page.route('**/api/*', async (route) => {
    const action = route.request().url().split('/').pop();
    if (action === 'position') {
      const data = route.request().postDataJSON();
      calls.push(data);
      expect(route.request().headers()['x-wp-nonce']).toBe('synthetic-nonce');
      if (data.period === 'week')
        await new Promise((resolve) => {
          releaseWeek = resolve;
        });
      return route.fulfill({
        json: {
          position: {
            rank: data.period === 'month' ? 3 : 1,
            xp: data.period === 'month' ? 25 : 10,
          },
        },
      });
    }
    return route.fulfill({
      json:
        action === 'growth'
          ? state
          : action === 'tasks'
            ? { tasks: [], badges: [], submissions: [] }
            : { items: [], next_cursor: null },
    });
  });
  await page.goto('/growth.html');
  await expect.poll(() => calls.length).toBe(1);
  await page.locator('[data-xp-position-period=month]').click();
  await expect(page.locator('.xp-personal-position')).toHaveText('本月第 3 名 · 活动净经验 +25');
  releaseWeek();
  await expect(page.locator('[data-xp-position-period=month]')).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await expect(page.locator('.xp-personal-position')).toContainText('本月第 3 名');
  expect(calls).toEqual([{ period: 'week' }, { period: 'month' }]);
});
