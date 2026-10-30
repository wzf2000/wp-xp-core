// Exercise the independent write-token publication gate before any release is created.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync, spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const version = JSON.parse(fs.readFileSync(path.join(root, 'package.json'))).version;
const sha =
  process.env.SOURCE_SHA ||
  execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
const workflow = fs.readFileSync(path.join(root, '.github/workflows/release.yml'), 'utf8');
const block = workflow.match(/^ {10}python3 - <<'PY'\n([\s\S]*?)^ {10}PY$/m);
if (!block) throw new Error('Independent publication verifier not found');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-xp-publication-'));
try {
  const release = path.join(temporary, 'release');
  fs.mkdirSync(release);
  const prefix = `wp-xp-core-${version}`;
  const names = [
    `${prefix}.zip`,
    `${prefix}.zip.sha256`,
    `${prefix}.manifest.json`,
    'release-notes.md',
    'release-validation.json',
  ];
  for (const name of names)
    fs.copyFileSync(path.join(root, 'dist', name), path.join(release, name));
  const verifier = path.join(temporary, 'verify.py');
  fs.writeFileSync(
    verifier,
    block[1]
      .split('\n')
      .map((line) => line.slice(10))
      .join('\n'),
  );
  const environment = { ...process.env, SOURCE_SHA: sha, RELEASE_VERSION: version };
  function verify() {
    const result = spawnSync('python3', [verifier], {
      cwd: temporary,
      env: environment,
      encoding: 'utf8',
    });
    if (result.error) throw result.error;
    return result;
  }
  const accepted = verify();
  if (accepted.status !== 0)
    throw new Error(`Publication gate rejected the built package: ${accepted.stderr}`);

  // Add an unregistered root PHP module with otherwise correct hashes and closed file sets.
  // The publisher must reject it even though all ordinary archive checks still agree.
  const tamper = String.raw`import hashlib,json,pathlib,zipfile
p=pathlib.Path('release'); prefix='wp-xp-core-'+__import__('os').environ['RELEASE_VERSION']
manifest=json.loads((p/(prefix+'.manifest.json')).read_bytes())
with zipfile.ZipFile(p/(prefix+'.zip')) as z: payload={n:z.read(n) for n in z.namelist()}
name='unregistered-publication-module.php'; data=b'<?php // Synthetic publication rejection fixture.\n'
manifest['files'][name]=hashlib.sha256(data).hexdigest()
raw=(json.dumps(manifest,indent=2,sort_keys=True)+'\n').encode()
(p/(prefix+'.manifest.json')).write_bytes(raw)
payload['wp-xp-core/'+name]=data; payload['wp-xp-core/release-manifest.json']=raw
with zipfile.ZipFile(p/(prefix+'.zip'),'w',compression=zipfile.ZIP_DEFLATED) as z:
 for n,data in payload.items():
  info=zipfile.ZipInfo(n); info.external_attr=0o100644<<16; z.writestr(info,data)
(p/(prefix+'.zip.sha256')).write_text(hashlib.sha256((p/(prefix+'.zip')).read_bytes()).hexdigest()+'  '+prefix+'.zip\n')
proof=json.loads((p/'release-validation.json').read_bytes())
proof['files']={n:hashlib.sha256((p/n).read_bytes()).hexdigest() for n in proof['files']}
(p/'release-validation.json').write_text(json.dumps(proof))
`;
  execFileSync('python3', ['-c', tamper], { cwd: temporary, env: environment, stdio: 'pipe' });
  if (verify().status === 0)
    throw new Error('Publication gate admitted an unregistered root module');
  const registered = fs
    .readFileSync(verifier, 'utf8')
    .replace('fixed = {', "fixed = {'unregistered-publication-module.php', ");
  fs.writeFileSync(verifier, registered);
  const admitted = verify();
  if (admitted.status !== 0)
    throw new Error(`Negative fixture failed ordinary archive checks: ${admitted.stderr}`);
  console.log(
    'Independent publication gate accepts the candidate and rejects unregistered modules',
  );
} finally {
  fs.rmSync(temporary, { recursive: true, force: true });
}
