const { test, expect } = require('@playwright/test');
const state = {
  experience: 5,
  checkin_xp: 17,
  level: 2,
  next: 20,
  checked_in: false,
  history: [
    { local_day: '2026-01-01', kind: 'checkin', xp: 2 },
    { local_day: '2026-01-01', kind: 'reconciliation', xp: 10 },
  ],
};
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) =>
    route.request().url().startsWith('http://127.0.0.1:18782/') ? route.continue() : route.abort(),
  );
});
test('state, check-in and safe history rendering', async ({ page }) => {
  const calls = [];
  await page.route('**/api/*', async (route) => {
    const action = route.request().url().split('/').pop();
    calls.push(action);
    expect(route.request().headers()['x-wp-nonce']).toBe('synthetic-nonce');
    await route.fulfill({
      json:
        action === 'ticket'
          ? { ticket: 'synthetic-ticket', issued: 1 }
          : action === 'checkin'
            ? {
                ...state,
                experience: 7,
                checked_in: true,
                history: [{ local_day: '<img src=x>', kind: 'unknown', xp: 0 }],
              }
            : state,
    });
  });
  await page.goto('/');
  await expect(page.locator('.reader-experience-state')).toContainText('经验 5');
  await expect(page.locator('.reader-experience-history')).toContainText('历史账本校准 +10');
  await expect(page.locator('.reader-experience-checkin')).toHaveText('每日签到 +17');
  await page.locator('.reader-experience-checkin').click();
  await expect(page.locator('.reader-experience-checkin')).toBeDisabled();
  await expect(page.locator('.reader-experience-message')).toContainText('今日签到已记录');
  await expect(page.locator('.reader-experience-history')).toContainText('<img src=x>');
  await expect(page.locator('.reader-experience-history img')).toHaveCount(0);
  const before = calls.filter((x) => x === 'like').length;
  await page.locator('.favorite').click();
  expect(calls.filter((x) => x === 'like')).toHaveLength(before);
});
test('failed check-in restores action and announces failure', async ({ page }) => {
  await page.route('**/api/*', (route) =>
    route.fulfill(
      route.request().url().endsWith('checkin')
        ? { status: 503, json: { message: 'Synthetic temporary failure' } }
        : { json: state },
    ),
  );
  await page.goto('/');
  await expect(page.locator('.reader-experience-state')).toContainText('经验 5');
  await page.locator('.reader-experience-checkin').click();
  await expect(page.locator('.reader-experience-message')).toHaveText(
    'Synthetic temporary failure',
  );
  await expect(page.locator('.reader-experience-checkin')).toBeEnabled();
});
test('visible reading sends one ticketed visit after fifteen seconds', async ({ page }) => {
  await page.clock.install();
  const visits = [];
  await page.route('**/api/*', async (route) => {
    const action = route.request().url().split('/').pop();
    if (action === 'visit') visits.push(route.request().postDataJSON());
    await route.fulfill({
      json: action === 'ticket' ? { ticket: 'synthetic-ticket', issued: 1 } : state,
    });
  });
  await page.goto('/');
  await expect(page.locator('.reader-experience-state')).toContainText('经验 5');
  await page.clock.runFor(16000);
  await expect.poll(() => visits.length).toBe(1);
  expect(visits[0]).toEqual({ post_id: 9, ticket: 'synthetic-ticket', issued: 1 });
  await page.clock.runFor(20000);
  expect(visits).toHaveLength(1);
});

test('custom final level has no next threshold', async ({ page }) => {
  await page.route('**/api/*', (route) =>
    route.fulfill({ json: { ...state, level: 3, next: null } }),
  );
  await page.goto('/');
  await expect(page.locator('.reader-experience-state')).toContainText('Level 3 · 已达最高等级');
});
