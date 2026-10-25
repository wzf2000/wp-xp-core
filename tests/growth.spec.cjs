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
    await route.fulfill(typeof reply?.status === 'number' ? reply : { json: reply ?? {} });
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

test('private inbox marks read and saves only the current user preferences', async ({ page }) => {
  let read = false;
  let revision = 'a'.repeat(64);
  let preferences = { task: true, review: true, level: true };
  await api(page, (action, data) => {
    if (action === 'growth') return state;
    if (action === 'tasks') return emptyTasks;
    if (action === 'history') return { items: [], next_cursor: null };
    if (action === 'notifications')
      return {
        items: [
          {
            id: '17:task',
            type: 'task',
            title: '获得成就',
            message: '合成成就',
            day: '2026-10-25',
            read,
          },
        ],
        next_cursor: null,
        through: 18,
        revision,
        preferences,
      };
    if (action === 'notification-settings') {
      expect(data.revision).toBe(revision);
      expect(data.user_id).toBeUndefined();
      if (data.ids) {
        expect(data.ids).toEqual(['17:task']);
        read = true;
      }
      if (data.preferences) preferences = data.preferences;
      revision = 'b'.repeat(64);
      return { saved: true, revision, preferences };
    }
  });
  await page.goto('/growth.html');
  await expect(page.locator('.xp-inbox-list')).toContainText('合成成就');
  await page.locator('.xp-inbox-list').getByRole('button', { name: '标为已读' }).click();
  await expect(page.locator('.xp-inbox-list')).toContainText('已读');
  await page.locator('.xp-inbox summary').click();
  await page.locator('.xp-inbox-preferences [name="level"]').uncheck();
  await page.getByRole('button', { name: '保存通知偏好' }).click();
  await expect(page.locator('.xp-inbox-preferences [name="level"]')).not.toBeChecked();
  expect(preferences.level).toBe(false);
});

test('slow inbox reads cannot replace a newer response', async ({ page }) => {
  let calls = 0;
  let release;
  const held = new Promise((resolve) => {
    release = resolve;
  });
  await api(page, async (action) => {
    if (action === 'growth') return state;
    if (action === 'tasks') return emptyTasks;
    if (action === 'history') return { items: [], next_cursor: null };
    if (action === 'notifications') {
      const current = ++calls;
      if (current === 2) await held;
      return {
        items: [
          {
            id: '17:task',
            type: 'task',
            title: '合成通知',
            message: current >= 3 ? '最新状态' : '旧状态',
            day: '2026-10-25',
            read: current >= 3,
          },
        ],
        revision: 'a'.repeat(64),
        preferences: { task: true, review: true, level: true },
        through: 17,
        next_cursor: null,
      };
    }
  });
  await page.goto('/growth.html');
  await expect(page.locator('.xp-inbox-list')).toContainText('旧状态');
  await page.getByRole('button', { name: '刷新通知' }).click();
  await expect.poll(() => calls).toBe(2);
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('wp-xp-core-state')));
  await expect(page.locator('.xp-inbox-list')).toContainText('最新状态');
  release();
  await expect(page.locator('.xp-inbox-list')).not.toContainText('旧状态');
});

test('review tasks keep rewards editable while automatic and checkin controls follow their kind', async ({
  page,
}) => {
  await page.goto('/tasks.html');
  const type = page.locator('[data-xp-task-type]');
  const steps = page.locator('[data-xp-auto-steps]');
  const scope = page.locator('[data-xp-step-scope]');
  await expect(scope).toBeHidden();
  expect(
    await page
      .locator('[data-xp-step-mode] option[value="objects"]')
      .evaluate((option) => option.disabled),
  ).toBe(true);
  await page.locator('[data-xp-step-kind]').selectOption('visit');
  await expect(scope).toBeVisible();
  await scope.locator('summary').click();
  await page.locator('[data-xp-step-mode]').selectOption('objects');
  await scope.locator('input').first().fill('123');
  await page.locator('[data-xp-step-kind]').selectOption('checkin');
  await expect(page.locator('[data-xp-step-mode]')).toHaveValue('events');
  await expect(scope.locator('input').first()).toBeDisabled();
  await type.selectOption('review');
  await expect(steps).toBeHidden();
  await expect(page.locator('[name="task[reward_xp]"]')).toBeEnabled();
  await page.locator('[name="task[reward_xp]"]').fill('5');
  await expect(page.locator('[name="task[category]"]')).toBeVisible();
  const submitted = await page
    .locator('.xp-task-editor')
    .first()
    .evaluate((form) => [...new FormData(form).keys()]);
  expect(submitted).not.toContain('task[steps][0][kind]');
  expect(submitted).toContain('task[reward_xp]');
  await type.selectOption('automatic');
  await expect(steps).toBeVisible();
});

test('loading an older inbox page preserves the first observed mark-all boundary', async ({
  page,
}) => {
  let through;
  await api(page, (action, data) => {
    if (action === 'growth') return state;
    if (action === 'tasks') return emptyTasks;
    if (action === 'history') return { items: [], next_cursor: null };
    if (action === 'notifications')
      return {
        items: [
          {
            id: data.cursor ? '80:task' : '100:task',
            type: 'task',
            title: '合成通知',
            message: '先前通知',
            day: '2026-10-25',
            read: false,
          },
        ],
        next_cursor: data.cursor ? null : '90:task',
        through: data.cursor ? 101 : 100,
        revision: 'a'.repeat(64),
        preferences: { task: true, review: true, level: true },
      };
    if (action === 'notification-settings') {
      through = data.through;
      return { saved: true, revision: 'b'.repeat(64) };
    }
  });
  await page.goto('/growth.html');
  await expect(page.locator('.xp-inbox-list li')).toHaveCount(1);
  await page.getByRole('button', { name: '更早的通知' }).click();
  await expect(page.locator('.xp-inbox-list li')).toHaveCount(2);
  await page.getByRole('button', { name: '全部标为已读' }).click();
  await expect.poll(() => through).toBe(100);
});

test('public-board opt-out reports nonparticipation while private XP remains visible', async ({
  page,
}) => {
  let participating = true;
  const revision = 'a'.repeat(64);
  await api(page, (action, data) => {
    if (action === 'growth') return state;
    if (action === 'tasks') return emptyTasks;
    if (action === 'history') return { items: [], next_cursor: null };
    if (action === 'notifications') return { items: [], next_cursor: null };
    if (action === 'participation')
      return { participating, status: participating ? 'active' : 'opted_out', revision };
    if (action === 'participation-settings') {
      expect(Object.keys(data).sort()).toEqual(['participating', 'revision']);
      participating = data.participating;
      return { participating, status: 'opted_out', revision };
    }
    if (action === 'position')
      return { position: null, participation: participating ? 'active' : 'opted_out' };
  });
  await page.goto('/growth.html');
  await expect(page.locator('.xp-board-participation input')).toBeEnabled();
  await page.locator('.xp-board-participation input').uncheck();
  await page.getByRole('button', { name: '保存榜单偏好' }).click();
  await expect(page.locator('.xp-personal-position')).toContainText('当前未参与公开榜单');
  await expect(page.locator('.xp-level-name')).toBeVisible();
  expect(participating).toBe(false);
});

test('phone tasks and recent achievements collapse, keyboard controls and refresh preserve expansion', async ({
  page,
}) => {
  const tasks = Array.from({ length: 8 }, (_, i) => ({
    id: 't_' + String(i).padStart(16, '0'),
    title: `合成目标${i}`,
    description: '合成说明',
    type: 'automatic',
    period: 'once',
    available: true,
    earned: false,
    steps: [{ kind: 'visit', mode: 'objects', current: 0, count: 3, scope_links: [] }],
  }));
  const badges = Array.from({ length: 8 }, (_, i) => ({
    title: `合成历史${i}`,
    day: '2026-10-24',
    icon: '★',
    condition_text: '用户原始说明',
  }));
  await api(page, (action) => {
    if (action === 'growth') return state;
    if (action === 'tasks') return { ...emptyTasks, tasks, badges };
    if (action === 'history') return { items: [], next_cursor: null };
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/growth.html');
  await expect(page.locator('.xp-personal-task:visible')).toHaveCount(3);
  await expect(page.locator('.xp-badges li:visible')).toHaveCount(3);
  const taskToggle = page.locator('.xp-tasks-toggle');
  await expect(taskToggle).toHaveAttribute('aria-expanded', 'false');
  await taskToggle.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.xp-personal-task:visible')).toHaveCount(8);
  await page.locator('.xp-badges-toggle').click();
  await expect(page.locator('.xp-badges li:visible')).toHaveCount(8);
  await expect(page.locator('.xp-badges')).toContainText('不代表全部历史');
  await page.locator('.reader-experience-checkin').click();
  await expect(page.locator('.xp-personal-task:visible')).toHaveCount(8);
  await expect(page.locator('.xp-badges li:visible')).toHaveCount(8);
  await page.locator('.xp-tasks-toggle').click();
  await page.getByRole('link', { name: '查看任务' }).click();
  await expect(page.locator('#xp-task-' + tasks[0].id)).toBeFocused();
  await expect(page.locator('.xp-tasks-toggle')).toHaveAttribute('aria-expanded', 'true');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('English JS catalog translates fixed labels and preserves Chinese user snapshots', async ({
  page,
}) => {
  const catalog = JSON.parse(fs.readFileSync('languages/wp-xp-core-en_US.json', 'utf8')).locale_data
    .messages;
  const dictionary = Object.fromEntries(
    Object.entries(catalog)
      .filter(([key]) => key)
      .map(([key, value]) => [key, value[0]]),
  );
  await page.addInitScript((map) => {
    window.WPXPI18n = map;
    window.WPXPLocale = 'en-US';
  }, dictionary);
  await api(page, (action) => {
    if (action === 'growth') return state;
    if (action === 'history') return { items: [], next_cursor: null };
    if (action === 'tasks')
      return {
        ...emptyTasks,
        tasks: [
          {
            id: 't_1212121212121212',
            title: '经验规则',
            description: '我的成长',
            type: 'automatic',
            period: 'once',
            available: true,
            steps: [{ kind: 'visit', count: 2, current: 1, mode: 'objects' }],
          },
        ],
      };
  });
  await page.goto('/growth.html');
  await expect(page.locator('.reader-experience-state')).toContainText('XP 5 · Level 2');
  await expect(page.locator('.xp-personal-task h4')).toContainText('经验规则');
  await expect(page.locator('.xp-personal-task')).toContainText('Different content');
  await expect(page.locator('.xp-personal-task p').first()).toHaveText('我的成长');
});
