(() => {
  'use strict';
  const xpT = (text, ...values) =>
    (window.WPXPI18n?.[text] || text).replace(/%([1-9][0-9]*)\$s/g, (_, n) =>
      String(values[Number(n) - 1] ?? ''),
    );

  const root = document.querySelector('.wp-xp-core-admin');
  if (!root) return;
  root.querySelectorAll('.xp-milestone-group').forEach((group) => {
    const rows = group.querySelector('.xp-milestone-rows');
    const add = group.querySelector('.xp-milestone-add');
    const template = group.querySelector('.xp-milestone-template');
    const message = group.querySelector('.xp-milestone-message');
    const kind = group.dataset.kind;
    const refresh = () => {
      [...rows.children].forEach((row, index) => {
        const id = row.querySelector('[data-field="id"]').value;
        row.querySelector('.xp-tier').textContent = xpT('第 %1$s 档', index + 1);
        row.querySelectorAll('[data-field]').forEach((input) => {
          const field = input.dataset.field;
          input.name = `rules[milestones][${kind}][${index}][${field}]`;
          if (field === 'id') return;
          input.id = `xp-${kind}_${id}${field === 'threshold' ? '-threshold' : ''}`;
          row.querySelector(`[data-for="${field}"]`).htmlFor = input.id;
        });
        row.querySelector('button').setAttribute('aria-label', xpT('移除第 %1$s 档', index + 1));
      });
      add.disabled = rows.children.length >= 20 || group.disabled;
    };
    add.hidden = false;
    add.addEventListener('click', () => {
      if (rows.children.length >= 20 || group.disabled) return;
      const row = template.content.firstElementChild.cloneNode(true);
      const random = new Uint8Array(16);
      crypto.getRandomValues(random);
      row.querySelector('[data-field="id"]').value =
        `m_${[...random].map((n) => n.toString(16).padStart(2, '0')).join('')}`;
      const previous = rows.lastElementChild?.querySelector('[data-field="threshold"]');
      const threshold = previous ? Number(previous.value) + 1 : 1;
      row.querySelector('[data-field="threshold"]').value =
        threshold <= 1000000000 ? threshold : '';
      rows.append(row);
      refresh();
      row.querySelector('[data-field="threshold"]').focus();
      message.textContent = xpT('已添加新档位，请设置次数和经验，保存后生效。');
    });
    rows.addEventListener('click', (event) => {
      const button = event.target.closest('.xp-milestone-remove');
      if (!button || group.disabled) return;
      const row = button.closest('.xp-milestone-row');
      const next = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      refresh();
      (next?.querySelector('[data-field="threshold"]') || add).focus();
      message.textContent = xpT('档位已移除，保存后永久停用；重新添加会创建新档位。');
    });
    refresh();
  });
  const list = root.querySelector('#xp-levels');
  const add = root.querySelector('.xp-level-add');
  const message = root.querySelector('.xp-level-message');
  if (!list || !add) return;
  const refresh = () => {
    const rows = [...list.children];
    rows.forEach((row, index) => {
      const input = row.querySelector('input');
      const label = row.querySelector('label');
      const remove = row.querySelector('button');
      input.id = `xp-level-${index}`;
      input.name = `rules[levels][${index}]`;
      input.readOnly = index === 0;
      label.htmlFor = input.id;
      label.textContent = `Level ${index + 1}`;
      remove.disabled = index === 0;
      remove.setAttribute('aria-label', xpT('移除 Level %1$s', index + 1));
    });
    root.querySelector('#xp-level-count').textContent = rows.length;
    add.disabled = rows.length >= 100;
  };
  add.hidden = false;
  add.addEventListener('click', () => {
    if (list.children.length >= 100) return;
    const row = list.lastElementChild.cloneNode(true);
    const previous = Number(list.lastElementChild.querySelector('input').value);
    const input = row.querySelector('input');
    input.value = Number.isInteger(previous) && previous < 1000000000 ? previous + 1 : '';
    list.append(row);
    refresh();
    input.focus();
    message.textContent = xpT('已添加 Level %1$s，请设置最低经验。', list.children.length);
  });
  list.addEventListener('click', (event) => {
    const button = event.target.closest('.xp-level-remove');
    if (!button || button.disabled) return;
    const row = button.closest('.xp-level-row');
    const next = row.nextElementSibling || row.previousElementSibling;
    row.remove();
    refresh();
    next.querySelector('input').focus();
    message.textContent = xpT('等级已移除，后续等级已重新编号；保存后生效。');
  });
  refresh();
})();
