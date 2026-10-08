(() => {
  'use strict';
  const c = window.ReaderExperience;
  if (!c) return;
  const labels = {
    checkin: '每日签到',
    visit: '访问网页',
    comment: '评论',
    comment_adjust: '评论状态调整',
    article: '发表文章',
    milestone_view: '浏览里程碑',
    milestone_like: '点赞里程碑',
    migration: '历史经验重算',
    reconciliation: '历史账本校准',
  };
  async function api(action, data = {}) {
    const r = await fetch(c.endpoint + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': c.nonce },
      body: JSON.stringify(data),
    });
    const v = await r.json();
    if (!r.ok) throw new Error(v.message || '暂时无法记录');
    return v;
  }
  function state(v) {
    document
      .querySelectorAll('.reader-experience-state')
      .forEach(
        (el) =>
          (el.textContent =
            `经验 ${v.experience} · Level ${v.level}` +
            (v.next !== null
              ? ` · 距下一级 ${Math.max(0, v.next - v.experience)}`
              : ' · 已达最高等级')),
      );
    document.querySelectorAll('.reader-experience-checkin').forEach((el) => {
      el.disabled = v.checked_in;
      el.textContent = v.checked_in ? '今日已签到' : '每日签到 +2';
    });
    document.querySelectorAll('.reader-experience-history').forEach((el) => {
      el.replaceChildren();
      for (const h of v.history) {
        const li = document.createElement('li');
        li.textContent = `${h.local_day} ${labels[h.kind] || '经验记录'} ${Number(h.xp) > 0 ? '+' : ''}${h.xp}`;
        el.append(li);
      }
    });
  }
  function message(s) {
    document.querySelectorAll('.reader-experience-message').forEach((el) => (el.textContent = s));
  }
  document.addEventListener(
    'click',
    async (e) => {
      const b = e.target.closest('.reader-experience-checkin');
      if (b) {
        e.preventDefault();
        b.disabled = true;
        try {
          state(await api('checkin'));
          message('签到成功，今日 +2 经验。');
        } catch (err) {
          message(err.message);
          b.disabled = false;
        }
      }
    },
    true,
  );
  if (c.logged) {
    if (document.querySelector('.reader-experience-panel'))
      api('state')
        .then(state)
        .catch((e) => message(e.message));
    if (c.postId !== null) {
      let visible = 0,
        last = performance.now(),
        busy = false,
        done = false,
        ticket;
      api('ticket', { post_id: c.postId })
        .then((v) => (ticket = v))
        .catch(() => {});
      const timer = setInterval(async () => {
        const now = performance.now();
        if (!document.hidden) visible += Math.min(now - last, 1500);
        last = now;
        if (done || busy || visible < 15000 || !ticket) return;
        busy = true;
        try {
          state(await api('visit', { post_id: c.postId, ...ticket }));
          done = true;
          clearInterval(timer);
        } catch (e) {
          message(e.message);
          if (visible > 60000) {
            done = true;
            clearInterval(timer);
          }
        } finally {
          busy = false;
        }
      }, 1000);
    }
  }
})();
