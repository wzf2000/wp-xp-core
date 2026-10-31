(() => {
  'use strict';
  const t = (text) => window.WPXPI18n?.[text] || text;
  const { registerBlockType } = window.wp.blocks;
  const { createElement: h, Fragment } = window.wp.element;
  const { useBlockProps, InspectorControls } = window.wp.blockEditor;
  const { PanelBody, SelectControl, RangeControl, TextControl } = window.wp.components;
  for (const [name, title, description] of [
    ['growth', '我的成长', '显示当前登录用户的私人成长面板；访客看到登录入口。'],
    ['summary', '我的成长概览', '显示本人经验、等级和今日签到状态。'],
    ['tasks', '我的任务', '显示本人当前任务进度，操作在完整成长面板完成。'],
    ['badges', '我的成就', '显示本人最近获得的成就。'],
    ['notifications', '我的通知', '显示本人的站内通知快照，不自动标为已读。'],
    ['path', '我的成长路径', '按系列和前置任务查看本人当前成长路径。'],
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
        : name === 'growth'
          ? {}
          : {
              attributes: {
                limit: { type: 'integer', default: 5 },
                density: {
                  type: 'string',
                  enum: ['compact', 'comfortable'],
                  default: 'comfortable',
                },
                accent: { type: 'string', default: '' },
                title: { type: 'string', default: '' },
                ...(name === 'path' ? { series: { type: 'string', default: '' } } : {}),
              },
            }),
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
            : name === 'growth'
              ? null
              : h(
                  InspectorControls,
                  null,
                  h(
                    PanelBody,
                    { title: t('成长组件设置') },
                    h(TextControl, {
                      label: t('标题（可选）'),
                      value: attributes.title,
                      onChange: (title) => setAttributes({ title }),
                    }),
                    name !== 'summary'
                      ? h(RangeControl, {
                          label: t('显示条数'),
                          value: attributes.limit,
                          min: 1,
                          max: 20,
                          onChange: (limit) => setAttributes({ limit }),
                        })
                      : null,
                    h(SelectControl, {
                      label: t('显示密度'),
                      value: attributes.density,
                      options: [
                        { label: t('舒适'), value: 'comfortable' },
                        { label: t('紧凑'), value: 'compact' },
                      ],
                      onChange: (density) => setAttributes({ density }),
                    }),
                    h(TextControl, {
                      label: t('强调色（可选十六进制）'),
                      value: attributes.accent,
                      placeholder: '#3856b0',
                      onChange: (accent) => setAttributes({ accent }),
                    }),
                    name === 'path'
                      ? h(TextControl, {
                          label: t('系列筛选（可选）'),
                          value: attributes.series,
                          onChange: (series) => setAttributes({ series }),
                        })
                      : null,
                  ),
                );
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
