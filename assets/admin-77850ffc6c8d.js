(() => {
  'use strict';
  const root = document.querySelector('.wp-xp-core-admin');
  if (!root) return;
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
      remove.setAttribute('aria-label', `移除 Level ${index + 1}`);
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
    message.textContent = `已添加 Level ${list.children.length}，请设置最低经验。`;
  });
  list.addEventListener('click', (event) => {
    const button = event.target.closest('.xp-level-remove');
    if (!button || button.disabled) return;
    const row = button.closest('.xp-level-row');
    const next = row.nextElementSibling || row.previousElementSibling;
    row.remove();
    refresh();
    next.querySelector('input').focus();
    message.textContent = '等级已移除，后续等级已重新编号；保存后生效。';
  });
  refresh();
})();
