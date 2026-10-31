(() => {
  'use strict';
  const xpT = (text, ...values) =>
    (window.WPXPI18n?.[text] || text).replace(/%([1-9][0-9]*)\$s/g, (_, n) =>
      String(values[Number(n) - 1] ?? ''),
    );

  const c = window.ReaderExperience;
  const panels = [...document.querySelectorAll('.xp-growth-panel')];
  if (!c?.logged || !panels.length) return;
  const names = {
    checkin: xpT('每日签到'),
    visit: xpT('有效阅读'),
    article: xpT('发表文章'),
    comment: xpT('有效评论'),
    comment_adjust: xpT('评论状态调整'),
    milestone_view: xpT('浏览里程碑'),
    milestone_like: xpT('点赞里程碑'),
    manual_adjust: xpT('人工调整'),
    manual_reversal: xpT('撤销人工调整'),
    streak_reward: xpT('签到阶段奖励'),
    provider_reward: xpT('外部活动奖励'),
    provider_revoke: xpT('外部活动撤销'),
    task_complete: xpT('任务完成奖励'),
    task_revoke: xpT('任务奖励撤销'),
    task_grant: xpT('人工授予成就'),
    task_grant_revoke: xpT('撤销人工成就'),
    achievement_admin: xpT('成就管理操作'),
    migration: xpT('历史初始化'),
    reconciliation: xpT('历史校准'),
  };
  const periods = { once: xpT('一次性'), day: xpT('每日'), week: xpT('每周'), month: xpT('每月') };
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
    if (!response.ok) {
      if (
        response.status === 401 ||
        (response.status === 403 &&
          ['nonce', 'rest_cookie_invalid_nonce', 'login_required'].includes(value.code))
      ) {
        for (const panel of panels) {
          panel.querySelector('.xp-inbox-list')?.replaceChildren();
          const state = inboxes.get(panel);
          if (state) {
            state.sequence++;
            state.revision = null;
            state.cursor = null;
            state.mutating = false;
            state.deadSession = true;
          }
          inboxBusy(panel, false);
          const output = panel.querySelector('.xp-inbox-message');
          if (output) output.textContent = value.message || xpT('请先登录。');
        }
      }
      throw new Error(value.message || xpT('暂时无法读取，请稍后重试。'));
    }
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
      xpT('经验 %1$s · Level %2$s', value.experience, value.level) +
      (value.next === null
        ? xpT(' · 已达最高等级')
        : xpT(' · 距下一级 %1$s', Math.max(0, value.next - value.experience)));
    panel.querySelector('.xp-level-progress').value = value.progress_percent;
    const button = panel.querySelector('.reader-experience-checkin');
    button.disabled = value.checked_in || !value.activities.checkin;
    button.textContent = !value.activities.checkin
      ? xpT('签到奖励已关闭')
      : value.checked_in
        ? xpT('今日已签到')
        : xpT('每日签到 +%1$s', value.reward_hold?.active ? 0 : value.checkin_xp);
    panel.querySelector('.xp-checkin-streak').textContent = xpT(
      '连续 %1$s 天 · 累计 %2$s 天',
      value.checkins.consecutive,
      value.checkins.cumulative,
    );
    let hold = panel.querySelector('.xp-reward-hold');
    if (!hold) {
      hold = el('p', '', 'xp-reward-hold');
      panel.querySelector('.xp-today').before(hold);
    }
    hold.hidden = !value.reward_hold?.active;
    hold.textContent = value.reward_hold?.active
      ? xpT('自动经验奖励暂时为零，有效活动和任务荣誉仍记录；恢复后不补差。') +
        ' ' +
        new Date(value.reward_hold.until * 1000).toLocaleString()
      : '';
    const today = panel.querySelector('.xp-today-items');
    today.replaceChildren();
    today.append(
      el(
        'li',
        value.activities.visit
          ? xpT(
              '阅读奖励 %1$s / %2$s 次；有效停留 %3$s 秒',
              value.today.visits,
              value.today.visit_limit,
              value.dwell_seconds,
            )
          : xpT('阅读奖励已关闭'),
      ),
    );
    today.append(
      el(
        'li',
        value.activities.comment
          ? xpT('评论奖励 %1$s / %2$s 次', value.today.comments, value.today.comment_limit)
          : xpT('评论奖励已关闭'),
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
      cell.title = `${iso} ${done ? xpT('已签到') : xpT('未签到')}`;
      cell.setAttribute('aria-label', cell.title);
      calendar.append(cell);
    }
    const next = value.streak.rewards.find((reward) => value.streak.days < reward.days);
    panel.querySelector('.xp-streak-progress').textContent = value.streak.enabled
      ? next
        ? xpT(
            '本版%1$s签到 %2$s 天，达到 %3$s 天可获 %4$s 阶段经验。',
            value.streak.mode === 'consecutive' ? xpT('连续') : xpT('累计'),
            value.streak.days,
            next.days,
            next.xp,
          )
        : xpT('本版签到阶段目标已达到。')
      : xpT('未启用额外签到阶段奖励。');
  }
  function renderTasks(panel, data) {
    const list = panel.querySelector('.xp-task-list');
    list.replaceChildren();
    if (!data.tasks.length) list.append(el('p', xpT('暂时没有开放的任务，日常参与仍可获得经验。')));
    const orderedTasks = [...data.tasks].sort(
      (a, b) =>
        Number(!b.earned && !b.revoked && b.available !== false) -
        Number(!a.earned && !a.revoked && a.available !== false),
    );
    const taskCards = [];
    for (const task of orderedTasks) {
      const article = el('article', undefined, 'xp-personal-task');
      article.id = `xp-task-${task.id}`;
      article.append(
        el('h4', `${task.icon || '◇'} ${task.title}`),
        el('p', task.description),
        el('span', periods[task.period], 'xp-period-label'),
        el('p', task.reward_xp ? xpT('完成奖励 +%1$s 经验', task.reward_xp) : xpT('荣誉任务')),
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
            `${prerequisite.complete ? '✓' : xpT('待完成')} ${prerequisite.title} · ${prerequisite.scope === 'current' ? xpT('当前版本与当前周期') : xpT('终身荣誉（含旧版本）')}`,
          ),
        );
      if (task.steps?.some((step) => step.source_available === false))
        article.append(el('p', xpT('活动来源暂不可用，已有进度与成就保留。')));
      for (const step of task.steps || []) {
        if (step.scoped && !step.scope_links?.length)
          article.append(el('p', xpT('指定内容暂未公开，请选择其他可用目标或等待开放。')));
        if (step.scope_links?.length) {
          const targets = el('p', xpT('可前往：'));
          for (const link of step.scope_links) {
            const anchor = el('a', link.title);
            anchor.href = link.url;
            targets.append(anchor, document.createTextNode(' '));
          }
          article.append(targets);
        }
      }
      if (task.earned) article.append(el('p', xpT('本期已完成 ✓'), 'xp-earned'));
      else if (task.revoked) article.append(el('p', xpT('本期成就已撤销，不能再次领取。')));
      else if (task.available === false)
        article.append(el('p', xpT('尚未开放、已结束或前置任务尚未完成。')));
      else if (task.type === 'automatic') {
        const steps = el('ul');
        for (const step of task.steps)
          steps.append(
            el(
              'li',
              `${step.label || names[step.kind] || step.kind}（${{ events: xpT('活动次数'), objects: step.kind === 'provider_reward' ? xpT('不同对象') : xpT('不同内容'), days: xpT('不同自然日') }[step.mode || 'events']}） ${step.current} / ${step.count}${step.complete ? ' ✓' : ''}`,
            ),
          );
        article.append(steps);
        if (task.complete) {
          const button = el('button', xpT('确认完成'));
          button.type = 'button';
          button.addEventListener('click', async () => {
            button.disabled = true;
            try {
              await api('claim', { task_id: task.id });
              message(panel, xpT('成就已记录。'));
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
        if (pending) article.append(el('p', xpT('已提交说明，等待管理员审核。')));
        else {
          const form = el('form', undefined, 'xp-task-submission');
          const label = el('label', xpT('完成说明'));
          const text = el('textarea');
          text.name = 'text';
          text.required = true;
          text.minLength = 5;
          text.maxLength = 1000;
          text.rows = 3;
          label.append(text);
          const button = el('button', xpT('提交审核'));
          button.type = 'submit';
          form.append(label, button);
          let requestId = crypto.randomUUID();
          form.addEventListener('submit', async (event) => {
            event.preventDefault();
            button.disabled = true;
            try {
              await api('submit', { task_id: task.id, text: text.value, request_id: requestId });
              requestId = crypto.randomUUID();
              message(panel, xpT('说明已提交，等待审核。'));
              await refresh();
            } catch (error) {
              message(panel, error.message);
              button.disabled = false;
            }
          });
          article.append(form);
        }
      }
      article.tabIndex = -1;
      taskCards.push(article);
      list.append(article);
    }
    function applyTaskExpansion() {
      taskCards.forEach((card, index) => {
        card.hidden = index >= 3 && !panel.xpTasksExpanded;
      });
    }
    applyTaskExpansion();
    if (taskCards.length > 3) {
      const toggle = el('button');
      toggle.type = 'button';
      toggle.className = 'xp-tasks-toggle';
      const sync = () => {
        toggle.textContent = panel.xpTasksExpanded
          ? xpT('收起其余任务')
          : xpT('展开其余任务（%1$s）', taskCards.length - 3);
        toggle.setAttribute('aria-expanded', String(Boolean(panel.xpTasksExpanded)));
      };
      toggle.addEventListener('click', () => {
        panel.xpTasksExpanded = !panel.xpTasksExpanded;
        applyTaskExpansion();
        sync();
      });
      sync();
      list.append(toggle);
    }
    if (data.tasks.length) {
      const next = data.tasks.find(
        (task) => !task.earned && !task.revoked && task.available !== false,
      );
      const goal = el(
        'p',
        next
          ? xpT('下一步：%1$s', next.title)
          : xpT('当前可用任务已完成，或正在等待开放／前置任务。'),
        'xp-next-goal',
      );
      if (next) {
        const step = next.steps?.find((item) => !item.complete);
        if (step)
          goal.append(
            document.createTextNode(
              xpT(
                ' · %1$s：还需 %2$s %3$s',
                step.label || names[step.kind] || step.kind,
                Math.max(0, step.count - step.current),
                {
                  events: xpT('次有效活动'),
                  objects: step.kind === 'provider_reward' ? xpT('个不同对象') : xpT('项不同内容'),
                  days: xpT('个不同自然日'),
                }[step.mode || 'events'],
              ),
            ),
          );
        const link = el('a', xpT('查看任务'));
        link.href = `#xp-task-${next.id}`;
        link.addEventListener('click', () => {
          panel.xpTasksExpanded = true;
          applyTaskExpansion();
          const toggle = list.querySelector('.xp-tasks-toggle');
          if (toggle) {
            toggle.textContent = xpT('收起其余任务');
            toggle.setAttribute('aria-expanded', 'true');
          }
          const target = list.querySelector(`#xp-task-${next.id}`);
          target?.focus({ preventScroll: true });
          target?.scrollIntoView({ block: 'nearest' });
        });
        goal.append(document.createTextNode(' '), link);
      }
      list.prepend(goal);
    }
    const badges = panel.querySelector('.xp-badges');
    badges.replaceChildren();
    if (data.badges.length) {
      badges.append(el('h4', xpT('最近获得的成就')));
      const items = el('ul');
      const badgeItems = [];
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
          ? new Date(badge.earned_at * 1000).toLocaleString(window.WPXPLocale || 'zh-CN', {
              timeZone: data.timezone || undefined,
            })
          : badge.day;
        item.append(
          el(
            'p',
            `${earned}${badge.reward_xp ? xpT(' · +%1$s 经验', badge.reward_xp) : ''}${badge.method === 'manual' ? xpT(' · 管理员授予') : ''}`,
          ),
        );
        if (badge.category || badge.series)
          item.append(el('p', [badge.category, badge.series].filter(Boolean).join(' · ')));
        if (badge.condition_text) {
          const detail = el('details');
          detail.append(el('summary', xpT('获得条件')), el('p', badge.condition_text));
          item.append(detail);
        }
        badgeItems.push(item);
        items.append(item);
      }
      const applyBadgeExpansion = () =>
        badgeItems.forEach((item, index) => {
          item.hidden = index >= 3 && !panel.xpBadgesExpanded;
        });
      applyBadgeExpansion();
      badges.append(items);
      if (badgeItems.length > 3) {
        const toggle = el('button');
        toggle.type = 'button';
        toggle.className = 'xp-badges-toggle';
        const sync = () => {
          toggle.textContent = panel.xpBadgesExpanded
            ? xpT('收起最近成就')
            : xpT('展开其余最近记录（%1$s）', badgeItems.length - 3);
          toggle.setAttribute('aria-expanded', String(Boolean(panel.xpBadgesExpanded)));
        };
        toggle.addEventListener('click', () => {
          panel.xpBadgesExpanded = !panel.xpBadgesExpanded;
          applyBadgeExpansion();
          sync();
        });
        sync();
        badges.append(toggle, el('p', xpT('仅展示本次接口返回的最近成就，不代表全部历史。')));
      }
    }
    const submissions = panel.querySelector('.xp-submissions');
    submissions.replaceChildren();
    if (data.submissions.length) {
      const details = el('details');
      details.append(el('summary', xpT('我的审核记录')));
      for (const row of data.submissions) {
        const article = el('article');
        article.append(
          el('strong', row.title),
          el(
            'p',
            `${{ pending: xpT('等待审核'), approved: xpT('已通过'), rejected: xpT('已退回') }[row.status]}${row.feedback ? ' · ' + row.feedback : ''}`,
          ),
        );
        details.append(article);
      }
      submissions.append(details);
    }
  }
  const inboxes = new WeakMap();
  function inboxBusy(panel, busy) {
    panel.querySelectorAll('.xp-inbox button,.xp-inbox input,.xp-inbox select').forEach((field) => {
      field.disabled = busy;
    });
  }
  async function loadInbox(panel, append = false) {
    if (!panel.querySelector('.xp-inbox')) return;
    const state = inboxes.get(panel) || { sequence: 0, revision: null, cursor: null, through: 0 };
    if (state.mutating || state.deadSession) return;
    inboxes.set(panel, state);
    const sequence = ++state.sequence;
    inboxBusy(panel, true);
    try {
      if (!state.sources || !append) {
        const available = await api('notification-sources');
        if (sequence !== state.sequence) return;
        state.sources = available.sources;
        panel.querySelector('.xp-inbox-source')?.parentElement.remove();
        if (!state.sources.some((source) => source.id === (state.source || 'xp'))) {
          state.source = 'xp';
          state.cursor = null;
          state.revision = null;
          panel.querySelector('.xp-inbox-list').replaceChildren();
        }
        if (state.sources.length > 1) {
          const label = el('label', xpT('通知来源'));
          const select = el('select');
          select.className = 'xp-inbox-source';
          for (const source of state.sources) {
            const option = el('option', source.label);
            option.value = source.id;
            select.append(option);
          }
          select.value = state.source || 'xp';
          select.disabled = true;
          label.append(select);
          panel.querySelector('.xp-inbox-message').before(label);
          select.addEventListener('change', () => {
            state.source = select.value;
            state.cursor = null;
            state.revision = null;
            panel.querySelector('.xp-inbox-list').replaceChildren();
            loadInbox(panel);
          });
        }
      }
      const external = state.source && state.source !== 'xp';
      panel.querySelector('.xp-inbox-preferences').closest('details').hidden = external;
      panel.querySelector('.xp-inbox-read-all').hidden = external;
      const data = await api(external ? 'notification-source-list' : 'notifications', {
        ...(external ? { source: state.source } : {}),
        limit: 12,
        ...(append && state.cursor ? { cursor: state.cursor } : {}),
      });
      if (sequence !== state.sequence) return;
      const list = panel.querySelector('.xp-inbox-list');
      if (!append) list.replaceChildren();
      for (const item of data.items || []) {
        const row = el('li');
        const title = el('strong', item.title);
        if (item.url) {
          try {
            const url = new URL(item.url);
            if (['http:', 'https:'].includes(url.protocol)) {
              const link = el('a', item.title);
              link.href = url.href;
              title.replaceChildren(link);
            }
          } catch {
            /* A source cannot introduce executable links. */
          }
        }
        row.append(title, el('p', item.message), el('small', item.day));
        if (!item.read) {
          const read = el('button', xpT('标为已读'));
          read.type = 'button';
          read.addEventListener('click', () =>
            external ? markSource(panel, item.id) : saveInbox(panel, { ids: [item.id] }),
          );
          row.append(read);
        } else row.append(el('small', xpT(' · 已读')));
        list.append(row);
      }
      if (!list.children.length) list.append(el('li', xpT('暂无这类站内通知。')));
      state.revision = data.revision;
      state.cursor = data.next_cursor;
      if (!append) state.through = data.through;
      panel.querySelector('.xp-inbox-more').hidden = !state.cursor;
      if (!external)
        for (const type of ['task', 'review', 'level', 'activity'])
          panel.querySelector(`.xp-inbox-preferences [name="${type}"]`).checked =
            data.preferences?.[type] !== false;
      panel.querySelector('.xp-inbox-message').textContent = '';
    } catch (error) {
      if (sequence === state.sequence) {
        panel.querySelector('.xp-inbox-list').replaceChildren();
        panel.querySelector('.xp-inbox-message').textContent =
          error.message + xpT(' 可稍后重新打开面板。');
      }
    } finally {
      if (sequence === state.sequence) inboxBusy(panel, false);
    }
  }
  async function markSource(panel, id) {
    const state = inboxes.get(panel);
    if (!state || state.mutating) return;
    state.mutating = true;
    inboxBusy(panel, true);
    try {
      await api('notification-source-read', { source: state.source, id });
      state.mutating = false;
      await loadInbox(panel);
    } catch (error) {
      state.mutating = false;
      panel.querySelector('.xp-inbox-message').textContent = error.message;
      inboxBusy(panel, false);
    }
  }
  async function saveInbox(panel, changes) {
    const state = inboxes.get(panel);
    if (!state?.revision || state.mutating || (state.source && state.source !== 'xp')) return;
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
  const participationStates = new WeakMap();
  async function participation(panel) {
    const form = panel.querySelector('.xp-board-participation');
    if (!form) return;
    const previous = participationStates.get(panel);
    if (previous?.saving) return;
    const state = { sequence: (previous?.sequence || 0) + 1 };
    participationStates.set(panel, state);
    try {
      const value = await api('participation');
      if (participationStates.get(panel) !== state) return;
      state.revision = value.revision;
      form.querySelector('input').checked = value.participating !== false;
      form.querySelector('.xp-board-preference-message').textContent =
        value.status === 'excluded' ? xpT('此账号由管理员排除榜单；个人经验仍照常记录。') : '';
    } catch (error) {
      if (participationStates.get(panel) === state)
        form.querySelector('.xp-board-preference-message').textContent = error.message;
    }
  }
  const positions = new WeakMap();
  async function position(panel, period) {
    const previous = positions.get(panel) || { sequence: 0 };
    const current = { period, sequence: previous.sequence + 1 };
    positions.set(panel, current);
    panel.querySelector('.xp-personal-standing').hidden = false;
    const output = panel.querySelector('.xp-personal-position');
    output.textContent = xpT('正在读取本期位置…');
    panel.querySelectorAll('[data-xp-position-period]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.xpPositionPeriod === period));
    });
    try {
      const data = await api('position', { period });
      if (positions.get(panel) !== current) return;
      output.textContent =
        data.participation && data.participation !== 'active'
          ? xpT('当前未参与公开榜单；经验和私人任务照常保留。')
          : data.position
            ? xpT(
                '%1$s第 %2$s 名 · 活动净经验 +%3$s',
                period === 'month' ? xpT('本月') : xpT('本周'),
                data.position.rank,
                data.position.xp,
              )
            : xpT('本期还没有计入榜单的活动净经验。');
    } catch (error) {
      if (positions.get(panel) === current)
        output.textContent = error.message + xpT(' 点击周期可重试。');
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
        participation(panel);
        if (previousLevel !== null && previousLevel < value.level)
          message(panel, xpT('已升至 %1$s！', value.level_profile.name));
      }
      previousLevel = value.level;
    } catch (error) {
      if (sequence === refreshSequence) for (const panel of panels) message(panel, error.message);
    }
  }
  for (const panel of panels) {
    panel.querySelector('.xp-board-participation')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const state = participationStates.get(panel);
      if (!state?.revision || state.saving) return;
      state.saving = true;
      form.querySelector('button').disabled = true;
      try {
        const result = await api('participation-settings', {
          revision: state.revision,
          participating: form.querySelector('input').checked,
        });
        state.revision = result.revision;
        form.querySelector('.xp-board-preference-message').textContent =
          result.status === 'active' ? xpT('已参与公开榜单。') : xpT('当前未参与公开榜单。');
        position(panel, positions.get(panel)?.period || 'week');
      } catch (error) {
        form.querySelector('.xp-board-preference-message').textContent = error.message;
      } finally {
        state.saving = false;
        form.querySelector('button').disabled = false;
      }
    });
    panel.querySelector('.xp-inbox-refresh')?.addEventListener('click', () => loadInbox(panel));
    panel
      .querySelector('.xp-inbox-read-all')
      ?.addEventListener('click', () =>
        saveInbox(panel, { through: inboxes.get(panel)?.through || 0 }),
      );
    panel.querySelector('.xp-inbox-more')?.addEventListener('click', () => loadInbox(panel, true));
    panel.querySelector('.xp-inbox-preferences')?.addEventListener('submit', (event) => {
      event.preventDefault();
      if (inboxes.get(panel)?.source && inboxes.get(panel).source !== 'xp') return;
      const preferences = {};
      for (const type of ['task', 'review', 'level', 'activity'])
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
              `${row.local_day} · ${names[row.kind] || xpT('经验记录')} ${row.xp > 0 ? '+' : ''}${row.xp}`,
            ),
          );
        if (!result.items.length && !append) list.append(el('li', xpT('没有符合条件的经验记录。')));
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
