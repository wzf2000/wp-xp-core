(() => {
  'use strict';
  const t = (text) => window.WPXPI18n?.[text] || text;
  const { registerBlockType } = window.wp.blocks;
  const { createElement: h, Fragment } = window.wp.element;
  const { useBlockProps, InspectorControls } = window.wp.blockEditor;
  const { PanelBody, SelectControl, RangeControl } = window.wp.components;
  for (const [name, title, description] of [
    ['growth', '我的成长', '显示当前登录用户的私人成长面板；访客看到登录入口。'],
    ['leaderboard', '经验活动榜', '显示本期公开活动榜；退出或被排除的账号不会展示。'],
  ])
    registerBlockType(`wp-xp-core/${name}`, {
      apiVersion: 2,
      title: t(title),
      description: t(description),
      icon: name === 'growth' ? 'awards' : 'chart-bar',
      category: 'widgets',
      ...(name === 'leaderboard'
        ? {
            attributes: {
              period: { type: 'string', enum: ['week', 'month'], default: 'week' },
              limit: { type: 'integer', default: 10 },
            },
          }
        : {}),
      edit({ attributes, setAttributes }) {
        const controls =
          name === 'leaderboard'
            ? h(
                InspectorControls,
                null,
                h(
                  PanelBody,
                  { title: t('榜单设置') },
                  h(SelectControl, {
                    label: t('统计周期'),
                    value: attributes.period,
                    options: [
                      { label: t('本周'), value: 'week' },
                      { label: t('本月'), value: 'month' },
                    ],
                    onChange: (period) => setAttributes({ period }),
                  }),
                  h(RangeControl, {
                    label: t('每页人数'),
                    value: attributes.limit,
                    min: 1,
                    max: 50,
                    onChange: (limit) => setAttributes({ limit }),
                  }),
                ),
              )
            : null;
        return h(
          Fragment,
          null,
          controls,
          h(
            'div',
            useBlockProps(),
            h('strong', null, t(title)),
            h('p', null, t(description)),
            name === 'leaderboard'
              ? h(
                  'p',
                  null,
                  `${t(attributes.period === 'month' ? '本月' : '本周')} · ${attributes.limit}`,
                )
              : null,
            h('p', null, t('保存后在前台查看；编辑器不读取私人经验。')),
          ),
        );
      },
      save() {
        return null;
      },
    });
})();
