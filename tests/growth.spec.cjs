const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
let state;
test.beforeEach(async ({ page }) => {
  state = JSON.parse(fs.readFileSync('.runtime/frontend/growth-state.json', 'utf8'));
  await page.route('**/*', (route) =>
    route.request().url().startsWith('http://127.0.0.1:18782/') ? route.continue() : route.abort(),
  );
});
function api(page, handler) {
  return page.route('**/api/*', async (route) => {
    expect(route.request().headers()['x-wp-nonce']).toBe('synthetic-nonce');
    const action = route.request().url().split('/').pop();
    const reply = await handler(action, route.request().postDataJSON());
    await route.fulfill(reply?.status ? reply : { json: reply ?? {} });
  });
}
const emptyTasks = { tasks: [], badges: [], submissions: [] };
test('private growth refresh, calendar, paginated filters and narrow layout', async ({ page }) => {
  const history = [];
  await api(page, (action, data) => {
    if (action === 'growth') return state;
    if (action === 'tasks') return emptyTasks;
    if (action === 'history') {
      history.push(data);
      return {
        items: [{ local_day: '2026-10-24', kind: 'manual_adjust', xp: 3 }],
        next_cursor: history.length === 1 ? 15 : null,
      };
    }
  });
  await page.goto('/growth.html');
  await expect(page.locator('.xp-today-items')).toContainText('有效停留 30 秒');
  await expect(page.locator('.xp-task-list')).toContainText('暂时没有开放的任务');
  await page.locator('summary').filter({ hasText: '签到日历' }).click();
  await expect(page.locator('.xp-checkin-calendar li')).toHaveCount(35);
  await expect(page.locator('.xp-checkin-calendar .is-checked')).toHaveCount(2);
  await page.locator('.xp-private-history summary').click();
  await page.locator('.xp-history-more').click();
  expect(history[1]).toEqual({ limit: 12, cursor: 15 });
  await page.locator('[name=kind]').selectOption('manual_adjust');
  await page.locator('.xp-history-filter button').click();
  await expect.poll(() => history.length).toBe(3);
  expect(history[2]).toEqual({ kind: 'manual_adjust', limit: 12 });
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
test('review submission preserves token on failure and displays safe pending text', async ({
  page,
}) => {
  let submitted = false;
  const calls = [];
  const task = {
    id: 't_aaaaaaaaaaaaaaaa',
    title: '审核荣誉',
    description: '<img src=x>',
    icon: '★',
    period: 'once',
    period_key: 'once',
    version: 1,
    type: 'review',
    earned: false,
    pending_submission: false,
  };
  await api(page, (action, data) => {
    if (action === 'growth') return state;
    if (action === 'history') return { items: [], next_cursor: null };
    if (action === 'tasks')
      return { ...emptyTasks, tasks: [{ ...task, pending_submission: submitted }] };
    if (action === 'submit') {
      calls.push(data);
      if (calls.length === 1) return { status: 503, json: { message: '合成临时失败' } };
      submitted = true;
      return { submitted: true };
    }
  });
  await page.goto('/growth.html');
  await expect(page.locator('.xp-personal-task')).toContainText('<img src=x>');
  expect(await page.locator('.xp-personal-task img').count()).toBe(0);
  await page.locator('.xp-task-submission textarea').fill('这是合成完成说明');
  await page.locator('.xp-task-submission button').click();
  await expect(page.locator('.reader-experience-message')).toHaveText('合成临时失败');
  await page.locator('.xp-task-submission button').click();
  await expect(page.locator('.xp-personal-task')).toContainText('等待管理员审核');
  expect(calls[0]).toEqual(calls[1]);
  expect(Object.keys(calls[0]).sort()).toEqual(['request_id', 'task_id', 'text']);
});
test('completed automatic task has a retryable confirm action', async ({ page }) => {
  let earned = false;
  await api(page, (action, data) => {
    if (action === 'growth') return state;
    if (action === 'history') return { items: [], next_cursor: null };
    if (action === 'tasks')
      return {
        ...emptyTasks,
        tasks: [
          {
            id: 't_bbbbbbbbbbbbbbbb',
            title: '合成目标',
            description: '',
            type: 'automatic',
            period: 'week',
            earned,
            complete: true,
            steps: [{ kind: 'visit', count: 2, current: 2, complete: true }],
          },
        ],
      };
    if (action === 'claim') {
      expect(data).toEqual({ task_id: 't_bbbbbbbbbbbbbbbb' });
      earned = true;
      return { earned: true };
    }
  });
  await page.goto('/growth.html');
  await page.getByRole('button', { name: '确认完成' }).click();
  await expect(page.locator('.xp-earned')).toContainText('本期已完成');
});
