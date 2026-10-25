(() => {
  'use strict';
  const xpT = (text, ...values) =>
    (window.WPXPI18n?.[text] || text).replace(/%([1-9][0-9]*)\$s/g, (_, n) =>
      String(values[Number(n) - 1] ?? ''),
    );

  const c = window.ReaderExperience;
  if (!c) return;
  const labels = {
    checkin: xpT('每日签到'),
    visit: xpT('访问网页'),
    comment: xpT('评论'),
    comment_adjust: xpT('评论状态调整'),
    article: xpT('发表文章'),
    milestone_view: xpT('浏览里程碑'),
    milestone_like: xpT('点赞里程碑'),
    migration: xpT('历史经验重算'),
    reconciliation: xpT('历史账本校准'),
  };
  async function api(action, data = {}) {
    const r = await fetch(c.endpoint + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': c.nonce },
      body: JSON.stringify(data),
    });
    const v = await r.json();
    if (!r.ok) throw new Error(v.message || xpT('暂时无法记录'));
    return v;
  }
  function state(v) {
    document
      .querySelectorAll('.reader-experience-state')
      .forEach(
        (el) =>
          (el.textContent =
            xpT('经验 %1$s · Level %2$s', v.experience, v.level) +
            (v.next !== null
              ? xpT(' · 距下一级 %1$s', Math.max(0, v.next - v.experience))
              : xpT(' · 已达最高等级'))),
      );
    document.querySelectorAll('.reader-experience-checkin').forEach((el) => {
      el.disabled = v.checked_in || v.activities?.checkin === false;
      el.textContent =
        v.activities?.checkin === false
          ? xpT('签到奖励已关闭')
          : v.checked_in
            ? xpT('今日已签到')
            : xpT('每日签到 +%1$s', v.checkin_xp);
    });
    document
      .querySelectorAll('.reader-experience-panel:not(.xp-growth-panel) .reader-experience-history')
      .forEach((el) => {
        el.replaceChildren();
        for (const h of v.history) {
          const li = document.createElement('li');
          li.textContent = `${h.local_day} ${labels[h.kind] || xpT('经验记录')} ${Number(h.xp) > 0 ? '+' : ''}${h.xp}`;
          el.append(li);
        }
      });
    document.dispatchEvent(new CustomEvent('wp-xp-core-state', { detail: v }));
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
          message(xpT('今日签到已记录。'));
        } catch (err) {
          message(err.message);
          b.disabled = false;
        }
      }
    },
    true,
  );
  if (c.logged) {
    if (document.querySelector('.reader-experience-panel:not(.xp-growth-panel)'))
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
        if (done || busy || visible < Number(ticket?.dwell_seconds || 15) * 1000 || !ticket) return;
        busy = true;
        try {
          state(
            await api('visit', {
              post_id: c.postId,
              issued: ticket.issued,
              signature: ticket.signature,
            }),
          );
          done = true;
          clearInterval(timer);
        } catch (e) {
          message(e.message);
          if (visible > Number(ticket?.dwell_seconds || 15) * 1000 + 45000) {
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
