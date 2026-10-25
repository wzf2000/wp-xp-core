(() => {
  'use strict';
  const root = document.querySelector('.wp-xp-core-admin');
  if (!root) return;
  function addRow(list, template, maximum, prefix) {
    if (list.children.length >= maximum) return;
    const holder = document.createElement('div');
    const id = 'r_' + crypto.randomUUID().replaceAll('-', '').slice(0, 24);
    holder.innerHTML = template.innerHTML
      .replaceAll('__INDEX__', String(Date.now()))
      .replaceAll('__ID__', id);
    const row = holder.firstElementChild;
    list.append(row);
    renumber(list, prefix);
    row.querySelector('input:not([type=hidden]),select')?.focus();
  }
  function renumber(list, prefix) {
    [...list.children].forEach((row, index) => {
      row.querySelectorAll('[name]').forEach((input) => {
        input.name = input.name.replace(
          new RegExp(`^${prefix.replaceAll('[', '\\[').replaceAll(']', '\\]')}\\[[^\\]]+\\]`),
          `${prefix}[${index}]`,
        );
      });
    });
  }
  const streaks = root.querySelector('#xp-streak-rows');
  const steps = root.querySelector('#xp-task-steps');
  root
    .querySelector('[data-xp-add-streak]')
    ?.addEventListener('click', () =>
      addRow(streaks, root.querySelector('#xp-streak-template'), 10, 'growth[streak][rewards]'),
    );
  root
    .querySelector('[data-xp-add-step]')
    ?.addEventListener('click', () =>
      addRow(steps, root.querySelector('#xp-task-step-template'), 8, 'task[steps]'),
    );
  root.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-xp-remove-row]');
    if (!remove) return;
    const row = remove.parentElement;
    const list = row.parentElement;
    const next = row.nextElementSibling || row.previousElementSibling;
    row.remove();
    renumber(list, list === streaks ? 'growth[streak][rewards]' : 'task[steps]');
    (
      next?.querySelector('input:not([type=hidden]),select') ||
      root.querySelector(list === streaks ? '[data-xp-add-streak]' : '[data-xp-add-step]')
    )?.focus();
  });
  const badge = root.querySelector('[data-xp-badge-image]');
  root.querySelector('[data-xp-pick-badge]')?.addEventListener('click', () => {
    if (!window.wp?.media) return;
    const frame = wp.media({ title: '选择徽章图片', library: { type: 'image' }, multiple: false });
    frame.on('select', () => {
      badge.value = frame.state().get('selection').first().get('id');
    });
    frame.open();
  });
  root.querySelector('[data-xp-clear-badge]')?.addEventListener('click', () => {
    badge.value = '0';
  });
  const grant = root.querySelector('[data-xp-grant-form]');
  grant?.querySelector('select')?.addEventListener('change', (event) => {
    const option = event.target.selectedOptions[0];
    grant.querySelector('[name="achievement[version]"]').value = option.dataset.version;
    grant.querySelector('[name="achievement[period]"]').value = option.dataset.period;
  });
  const type = root.querySelector('[data-xp-task-type]');
  function updateType() {
    if (!type) return;
    const manual = type.value === 'review';
    const fieldset = root.querySelector('.xp-task-steps');
    fieldset.hidden = manual;
    fieldset.disabled = manual;
    root.querySelector('.xp-task-review-help').hidden = !manual;
  }
  type?.addEventListener('change', updateType);
  updateType();
})();
