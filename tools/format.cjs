const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const prettier = require('prettier');
const beautify = require('js-beautify').html;
const root = path.resolve(__dirname, '..');
const write = process.argv.includes('--write');
const generated = /assets\/[^/]+-[a-f0-9]{12}\.(css|js)$/;
const htmlOptions = {
  indent_size: 4,
  wrap_line_length: 0,
  wrap_attributes: 'preserve',
  templating: ['php'],
  preserve_newlines: true,
  extra_liners: [],
  end_with_newline: true,
  content_unformatted: ['pre', 'textarea', 'script', 'style'],
};
function run(command, args) {
  return execFileSync(command, args, { cwd: root, stdio: 'inherit' });
}
async function format() {
  const files = [];
  const visit = (dir, prefix = '') => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (entry.isSymbolicLink()) throw new Error('Symlink in source tree');
      if (
        [
          '.git',
          '.venv',
          'node_modules',
          'dist',
          '.runtime',
          'test-results',
          'playwright-report',
          '__pycache__',
        ].includes(entry.name)
      )
        continue;
      const relative = prefix + entry.name;
      if (entry.isDirectory()) visit(path.join(dir, entry.name), relative + '/');
      else files.push(relative);
    }
  };
  visit(root);
  const web = files.filter(
    (file) => /\.(php|js|cjs|css|json|md|ya?ml)$/.test(file) && !generated.test(file),
  );
  const python = files.filter((file) => file.endsWith('.py'));
  const changed = [];
  for (const file of web) {
    const location = path.join(root, file);
    const original = fs.readFileSync(location, 'utf8');
    const options = { ...(await prettier.resolveConfig(location)), filepath: location };
    let result = original;
    // Mixed templates may need another pass after PHP changes line lengths.
    for (let pass = 0; pass < 8; pass++) {
      const html =
        file.endsWith('.php') && result.includes('?>') ? beautify(result, htmlOptions) : result;
      const spaced = file.endsWith('.md') ? await require('./format-markdown.cjs')(html) : html;
      const next = (await prettier.format(spaced, options)).replace(/\n+$/, '\n');
      if (next === result) break;
      result = next;
      if (pass === 7) throw new Error(`Formatting did not stabilize: ${file}`);
    }
    if (result !== original) {
      changed.push(file);
      if (write) fs.writeFileSync(location, result);
    }
  }
  if (!write && changed.length) throw new Error(`Run npm run format for:\n${changed.join('\n')}`);
  for (const file of python)
    run('python3', ['-m', 'black', '--workers', '1', ...(write ? [] : ['--check']), file]);
  if (write && fs.existsSync(path.join(root, 'build-assets.py')))
    run('python3', ['build-assets.py']);
  const assets = path.join(root, 'assets');
  const manifest = JSON.parse(fs.readFileSync(path.join(assets, 'assets.json'), 'utf8'));
  for (const [key, name] of Object.entries({
    js: 'app.js',
    css: 'app.css',
    admin_css: 'admin.css',
  })) {
    const content = fs.readFileSync(path.join(assets, name));
    const hash = crypto.createHash('sha256').update(content).digest('hex').slice(0, 12);
    const expected = `${path.parse(name).name}-${hash}${path.extname(name)}`;
    if (manifest[key] !== expected || !content.equals(fs.readFileSync(path.join(assets, expected))))
      throw new Error(`Rebuild assets: ${name}`);
  }
  console.log(
    `${web.length} web files checked; ${changed.length} formatting changes; asset hashes verified.`,
  );
}
format().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
