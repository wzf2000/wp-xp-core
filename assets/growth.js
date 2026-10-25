(() => {
  'use strict';
  const c = window.ReaderExperience;
  const panels = [...document.querySelectorAll('.xp-growth-panel')];
  if (!c?.logged || !panels.length) return;
  const names = {
    checkin: '每日签到',
    visit: '有效阅读',
    article: '发表文章',
    comment: '有效评论',
    comment_adjust: '评论状态调整',
    milestone_view: '浏览里程碑',
    milestone_like: '点赞里程碑',
    manual_adjust: '人工调整',
    manual_reversal: '撤销人工调整',
    streak_reward: '签到阶段奖励',
    task_complete: '任务完成奖励',
    task_revoke: '任务奖励撤销',
    task_grant: '人工授予成就',
    task_grant_revoke: '撤销人工成就',
    achievement_admin: '成就管理操作',
    migration: '历史初始化',
    reconciliation: '历史校准',
  };
  const periods = { once: '一次性', day: '每日', week: '每周', month: '每月' };
  let refreshSequence = 0,
    previousLevel = null;
  async function api(action, data = {}) {
    const response = await fetch(c.endpoint + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': c.nonce },
      body: JSON.stringify(data),
    });
    const value = await response.json();
    if (!response.ok) throw new Error(value.message || '暂时无法读取，请稍后重试。');
    return value;
  }
  function el(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  }
  function message(panel, text) {
    panel.querySelector('.reader-experience-message').textContent = text;
  }
  function render(panel, value) {
    panel.querySelector('.xp-level-name').textContent = value.level_profile.name;
    const symbol = panel.querySelector('.xp-level-symbol');
    symbol.textContent = value.level_profile.icon || 'XP';
    symbol.style.setProperty('--xp-level-color', value.level_profile.color);
    panel.querySelector('.xp-level-description').textContent = value.level_profile.description;
    panel.querySelector('.reader-experience-state').textContent =
      `经验 ${value.experience} · Level ${value.level}` +
      (value.next === null
        ? ' · 已达最高等级'
        : ` · 距下一级 ${Math.max(0, value.next - value.experience)}`);
    panel.querySelector('.xp-level-progress').value = value.progress_percent;
    const button = panel.querySelector('.reader-experience-checkin');
    button.disabled = value.checked_in || !value.activities.checkin;
    button.textContent = !value.activities.checkin
      ? '签到奖励已关闭'
      : value.checked_in
        ? '今日已签到'
        : `每日签到 +${value.checkin_xp}`;
    panel.querySelector('.xp-checkin-streak').textContent =
      `连续 ${value.checkins.consecutive} 天 · 累计 ${value.checkins.cumulative} 天`;
    const today = panel.querySelector('.xp-today-items');
    today.replaceChildren();
    today.append(
      el(
        'li',
        value.activities.visit
          ? `阅读奖励 ${value.today.visits} / ${value.today.visit_limit} 次；有效停留 ${value.dwell_seconds} 秒`
          : '阅读奖励已关闭',
      ),
    );
    today.append(
      el(
        'li',
        value.activities.comment
          ? `评论奖励 ${value.today.comments} / ${value.today.comment_limit} 次`
          : '评论奖励已关闭',
      ),
    );
    const calendar = panel.querySelector('.xp-checkin-calendar');
    calendar.replaceChildren();
    const current = new Date(`${value.day}T12:00:00Z`);
    for (let offset = 34; offset >= 0; offset--) {
      const date = new Date(current);
      date.setUTCDate(date.getUTCDate() - offset);
      const iso = date.toISOString().slice(0, 10),
        done = value.checkins.dates.includes(iso);
      const cell = el('li', String(date.getUTCDate()), done ? 'is-checked' : '');
      cell.title = `${iso} ${done ? '已签到' : '未签到'}`;
      cell.setAttribute('aria-label', cell.title);
      calendar.append(cell);
    }
    const next = value.streak.rewards.find((reward) => value.streak.days < reward.days);
    panel.querySelector('.xp-streak-progress').textContent = value.streak.enabled
      ? next
        ? `本版${value.streak.mode === 'consecutive' ? '连续' : '累计'}签到 ${value.streak.days} 天，达到 ${next.days} 天可获 ${next.xp} 阶段经验。`
        : '本版签到阶段目标已达到。'
      : '未启用额外签到阶段奖励。';
  }
  function renderTasks(panel, data) {
    const list = panel.querySelector('.xp-task-list');
    list.replaceChildren();
    if (!data.tasks.length) list.append(el('p', '暂时没有开放的任务，日常参与仍可获得经验。'));
    for (const task of data.tasks) {
      const article = el('article', undefined, 'xp-personal-task');
      article.id = `xp-task-${task.id}`;
      article.append(
        el('h4', `${task.icon || '◇'} ${task.title}`),
        el('p', task.description),
        el('span', periods[task.period], 'xp-period-label'),
        el('p', task.reward_xp ? `完成奖励 +${task.reward_xp} 经验` : '荣誉任务'),
      );
      if (task.image_url) {
        const image = el('img');
        image.src = task.image_url;
        image.alt = '';
        image.width = image.height = 40;
        image.loading = 'lazy';
        article.prepend(image);
      }
      if (task.category || task.series)
        article.append(el('p', [task.category, task.series].filter(Boolean).join(' · ')));
      if (task.condition_text) article.append(el('p', task.condition_text));
      for (const prerequisite of task.prerequisites || [])
        article.append(
          el(
            'p',
            `${prerequisite.complete ? '✓' : '待完成'} ${prerequisite.title} · ${prerequisite.scope === 'current' ? '当前版本与当前周期' : '终身荣誉（含旧版本）'}`,
          ),
        );
      for (const step of task.steps || []) {
        if (step.scoped && !step.scope_links?.length)
          article.append(el('p', '指定内容暂未公开，请选择其他可用目标或等待开放。'));
        if (step.scope_links?.length) {
          const targets = el('p', '可前往：');
          for (const link of step.scope_links) {
            const anchor = el('a', link.title);
            anchor.href = link.url;
            targets.append(anchor, document.createTextNode(' '));
          }
          article.append(targets);
        }
      }
      if (task.earned) article.append(el('p', '本期已完成 ✓', 'xp-earned'));
      else if (task.revoked) article.append(el('p', '本期成就已撤销，不能再次领取。'));
      else if (task.available === false)
        article.append(el('p', '尚未开放、已结束或前置任务尚未完成。'));
      else if (task.type === 'automatic') {
        const steps = el('ul');
        for (const step of task.steps)
          steps.append(
            el(
              'li',
              `${names[step.kind] || step.kind}（${{ events: '活动次数', objects: '不同内容', days: '不同自然日' }[step.mode || 'events']}） ${step.current} / ${step.count}${step.complete ? ' ✓' : ''}`,
            ),
          );
        article.append(steps);
        if (task.complete) {
          const button = el('button', '确认完成');
          button.type = 'button';
          button.addEventListener('click', async () => {
            button.disabled = true;
            try {
              await api('claim', { task_id: task.id });
              message(panel, '成就已记录。');
              await refresh();
            } catch (error) {
              message(panel, error.message);
              button.disabled = false;
            }
          });
          article.append(button);
        }
      } else {
        const pending = task.pending_submission;
        if (pending) article.append(el('p', '已提交说明，等待管理员审核。'));
        else {
          const form = el('form', undefined, 'xp-task-submission');
          const label = el('label', '完成说明');
          const text = el('textarea');
          text.name = 'text';
          text.required = true;
          text.minLength = 5;
          text.maxLength = 1000;
          text.rows = 3;
          label.append(text);
          const button = el('button', '提交审核');
          button.type = 'submit';
          form.append(label, button);
          let requestId = crypto.randomUUID();
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            button.disabled = true;
            try {
              await api('submit', { task_id: task.id, text: text.value, request_id: requestId });
              requestId = crypto.randomUUID();
              message(panel, '说明已提交，等待审核。');
              await refresh();
            } catch (error) {
              message(panel, error.message);
              button.disabled = false;
            }
          });
          article.append(form);
        }
      }
      list.append(article);
    }
    if (data.tasks.length) {
      const next = data.tasks.find(
        (task) => !task.earned && !task.revoked && task.available !== false,
      );
      const goal = el(
        'p',
        next ? `下一步：${next.title}` : '当前可用任务已完成，或正在等待开放／前置任务。',
        'xp-next-goal',
      );
      if (next) {
        const step = next.steps?.find((item) => !item.complete);
        if (step)
          goal.append(
            document.createTextNode(
              ` · 还需 ${Math.max(0, step.count - step.current)} ${{ events: '次有效活动', objects: '项不同内容', days: '个不同自然日' }[step.mode || 'events']}`,
            ),
          );
        const link = el('a', '查看任务');
        link.href = `#xp-task-${next.id}`;
        goal.append(document.createTextNode(' '), link);
      }
      list.prepend(goal);
    }
    const badges = panel.querySelector('.xp-badges');
    badges.replaceChildren();
    if (data.badges.length) {
      badges.append(el('h4', '最近获得的成就'));
      const items = el('ul');
      for (const badge of data.badges) {
        const item = el('li');
        if (badge.image_url) {
          const image = el('img');
          image.src = badge.image_url;
          image.alt = '';
          image.width = image.height = 40;
          image.loading = 'lazy';
          item.append(image);
        }
        item.append(el('strong', `${badge.icon || '★'} ${badge.title}`));
        const earned = badge.earned_at
          ? new Date(badge.earned_at * 1000).toLocaleString('zh-CN', {
              timeZone: data.timezone || undefined,
            })
          : badge.day;
        item.append(
          el(
            'p',
            `${earned}${badge.reward_xp ? ` · +${badge.reward_xp} 经验` : ''}${badge.method === 'manual' ? ' · 管理员授予' : ''}`,
          ),
        );
        if (badge.category || badge.series)
          item.append(el('p', [badge.category, badge.series].filter(Boolean).join(' · ')));
        if (badge.condition_text) {
          const detail = el('details');
          detail.append(el('summary', '获得条件'), el('p', badge.condition_text));
          item.append(detail);
        }
        items.append(item);
      }
      badges.append(items);
    }
    const submissions = panel.querySelector('.xp-submissions');
    submissions.replaceChildren();
    if (data.submissions.length) {
      const details = el('details');
      details.append(el('summary', '我的审核记录'));
      for (const row of data.submissions) {
        const article = el('article');
        article.append(
          el('strong', row.title),
          el(
            'p',
            `${{ pending: '等待审核', approved: '已通过', rejected: '已退回' }[row.status]}${row.feedback ? ' · ' + row.feedback : ''}`,
          ),
        );
        details.append(article);
      }
      submissions.append(details);
    }
  }
  const inboxes = new WeakMap();
  function inboxBusy(panel, busy) {
    panel.querySelectorAll('.xp-inbox button,.xp-inbox input').forEach((field) => {
      field.disabled = busy;
    });
  }
  async function loadInbox(panel, append = false) {
    if (!panel.querySelector('.xp-inbox')) return;
    const state = inboxes.get(panel) || { sequence: 0, revision: null, cursor: null, through: 0 };
    if (state.mutating) return;
    inboxes.set(panel, state);
    const sequence = ++state.sequence;
    inboxBusy(panel, true);
    try {
      const data = await api('notifications', {
        limit: 12,
        ...(append && state.cursor ? { cursor: state.cursor } : {}),
      });
      if (sequence !== state.sequence) return;
      const list = panel.querySelector('.xp-inbox-list');
      if (!append) list.replaceChildren();
      for (const item of data.items || []) {
        const row = el('li');
        row.append(el('strong', item.title), el('p', item.message), el('small', item.day));
        if (!item.read) {
          const read = el('button', '标为已读');
          read.type = 'button';
          read.addEventListener('click', () => saveInbox(panel, { ids: [item.id] }));
          row.append(read);
        } else row.append(el('small', ' · 已读'));
        list.append(row);
      }
      if (!list.children.length) list.append(el('li', '暂无这类站内通知。'));
      state.revision = data.revision;
      state.cursor = data.next_cursor;
      state.through = data.through;
      panel.querySelector('.xp-inbox-more').hidden = !state.cursor;
      for (const type of ['task', 'review', 'level'])
        panel.querySelector(`.xp-inbox-preferences [name="${type}"]`).checked =
          data.preferences?.[type] !== false;
      panel.querySelector('.xp-inbox-message').textContent = '';
    } catch (error) {
      if (sequence === state.sequence)
        panel.querySelector('.xp-inbox-message').textContent =
          error.message + ' 可稍后重新打开面板。';
    } finally {
      if (sequence === state.sequence) inboxBusy(panel, false);
    }
  }
  async function saveInbox(panel, changes) {
    const state = inboxes.get(panel);
    if (!state?.revision || state.mutating) return;
    state.mutating = true;
    const sequence = ++state.sequence;
    inboxBusy(panel, true);
    try {
      const result = await api('notification-settings', { revision: state.revision, ...changes });
      if (sequence !== state.sequence) return;
      state.revision = result.revision;
      state.mutating = false;
      await loadInbox(panel);
    } catch (error) {
      if (sequence !== state.sequence) return;
      state.mutating = false;
      panel.querySelector('.xp-inbox-message').textContent = error.message;
      inboxBusy(panel, false);
    }
  }
  const positions = new WeakMap();
  async function position(panel, period) {
    const previous = positions.get(panel) || { sequence: 0 };
    const current = { period, sequence: previous.sequence + 1 };
    positions.set(panel, current);
    panel.querySelector('.xp-personal-standing').hidden = false;
    const output = panel.querySelector('.xp-personal-position');
    output.textContent = '正在读取本期位置…';
    panel.querySelectorAll('[data-xp-position-period]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.xpPositionPeriod === period));
    });
    try {
      const data = await api('position', { period });
      if (positions.get(panel) !== current) return;
      output.textContent = data.position
        ? `${period === 'month' ? '本月' : '本周'}第 ${data.position.rank} 名 · 活动净经验 +${data.position.xp}`
        : '本期还没有计入榜单的活动净经验。';
    } catch (error) {
      if (positions.get(panel) === current)
        output.textContent = error.message + ' 点击周期可重试。';
    }
  }
  async function refresh() {
    const sequence = ++refreshSequence;
    try {
      const [value, tasks] = await Promise.all([api('growth'), api('tasks')]);
      if (sequence !== refreshSequence) return;
      for (const panel of panels) {
        render(panel, value);
        renderTasks(panel, tasks);
        position(panel, positions.get(panel)?.period || 'week');
        loadInbox(panel);
        if (previousLevel !== null && previousLevel < value.level)
          message(panel, `已升至 ${value.level_profile.name}！`);
      }
      previousLevel = value.level;
    } catch (error) {
      if (sequence === refreshSequence) for (const panel of panels) message(panel, error.message);
    }
  }
  for (const panel of panels) {
    panel
      .querySelector('.xp-inbox-read-all')
      ?.addEventListener('click', () =>
        saveInbox(panel, { through: inboxes.get(panel)?.through || 0 }),
      );
    panel.querySelector('.xp-inbox-more')?.addEventListener('click', () => loadInbox(panel, true));
    panel.querySelector('.xp-inbox-preferences')?.addEventListener('submit', (event) => {
      event.preventDefault();
      const preferences = {};
      for (const type of ['task', 'review', 'level'])
        preferences[type] = event.currentTarget.querySelector(`[name="${type}"]`).checked;
      saveInbox(panel, { preferences });
    });
    panel.querySelectorAll('[data-xp-position-period]').forEach((button) => {
      button.addEventListener('click', () => position(panel, button.dataset.xpPositionPeriod));
    });
    let cursor = null,
      sequence = 0,
      filters = {};
    const more = panel.querySelector('.xp-history-more');
    async function history(append = false) {
      const request = ++sequence;
      more.disabled = true;
      try {
        const result = await api('history', {
          ...filters,
          limit: 12,
          ...(append && cursor ? { cursor } : {}),
        });
        if (request !== sequence) return;
        const list = panel.querySelector('.reader-experience-history');
        if (!append) list.replaceChildren();
        for (const row of result.items)
          list.append(
            el(
              'li',
              `${row.local_day} · ${names[row.kind] || '经验记录'} ${row.xp > 0 ? '+' : ''}${row.xp}`,
            ),
          );
        if (!result.items.length && !append) list.append(el('li', '没有符合条件的经验记录。'));
        cursor = result.next_cursor;
        more.hidden = !cursor;
      } catch (error) {
        message(panel, error.message);
      } finally {
        more.disabled = false;
      }
    }
    panel.querySelector('.xp-history-filter').addEventListener('submit', (event) => {
      event.preventDefault();
      filters = Object.fromEntries(
        [...new FormData(event.currentTarget)].filter(([, value]) => value !== ''),
      );
      cursor = null;
      history();
    });
    more.addEventListener('click', () => history(true));
    history();
  }
  document.addEventListener('wp-xp-core-state', refresh);
  refresh();
})();
