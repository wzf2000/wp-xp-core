/** Format prose nodes only; preserve fenced code, inline code and link targets. */
module.exports = async function spaceMarkdown(source) {
  const { fromMarkdown } = await import('mdast-util-from-markdown');
  const { default: pangu } = await import('pangu');
  const changes = [];
  function visit(node) {
    if (node.type === 'text' && node.position) {
      const start = node.position.start.offset;
      const end = node.position.end.offset;
      const raw = source.slice(start, end);
      const proposed = pangu.spaceText(raw);
      // This task changes spacing only, not punctuation or wording.
      const value =
        proposed.replace(/\s/g, '') === raw.replace(/\s/g, '')
          ? proposed
          : raw
              .replace(/([\p{Script=Han}])([A-Za-z0-9])/gu, '$1 $2')
              .replace(/([A-Za-z0-9])([\p{Script=Han}])/gu, '$1 $2');
      changes.push({ start, end, value });
    }
    const children = node.children || [];
    const visible = (item) => item.value || (item.children || []).map(visible).join('');
    for (let i = 1; i < children.length; i++) {
      const left = children[i - 1];
      const right = children[i];
      if (!left.position || !right.position) continue;
      const boundary = visible(left).slice(-1) + visible(right).slice(0, 1);
      const gap = source.slice(left.position.end.offset, right.position.start.offset);
      if (!gap && /(?:[\p{Script=Han}][A-Za-z0-9]|[A-Za-z0-9][\p{Script=Han}])/u.test(boundary)) {
        changes.push({
          start: right.position.start.offset,
          end: right.position.start.offset,
          value: ' ',
        });
      }
    }
    for (const child of children) visit(child);
  }
  visit(fromMarkdown(source));
  for (const change of changes.sort((a, b) => b.start - a.start || b.end - a.end)) {
    source = source.slice(0, change.start) + change.value + source.slice(change.end);
  }
  return source;
};
