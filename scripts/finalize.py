#!/usr/bin/env python3
"""Assemble current offline bundle, complete source and flat GitHub assets."""
from pathlib import Path
import hashlib
import shutil
import tarfile
import zipfile

BASE = Path(__file__).resolve().parents[1]
VERSION = (BASE / 'VERSION').read_text().strip()
DIST = BASE / 'dist'
DIST.mkdir(exist_ok=True)
EVIDENCE = BASE / 'docs/evidence'
EVIDENCE.mkdir(exist_ok=True)
for name in ['worker-tests.log', 'integration-tests.log', 'ui-tests.log', 'http-fpm-tests.log', 'release-tests.log', 'image-verification.log', 'runtime-smoke.log']:
    source = BASE / '.build/logs' / name
    if source.exists():
        shutil.copyfile(source, EVIDENCE / name)
for name in ['README.md', 'README.fr.md', 'LICENSE', 'CHANGELOG.md']:
    shutil.copyfile(BASE / name, DIST / name)
if (DIST / 'docs').exists():
    shutil.rmtree(DIST / 'docs')
shutil.copytree(BASE / 'docs', DIST / 'docs')
shutil.copyfile(BASE / 'scripts/audit-residuals.sh', DIST / 'audit-residuals.sh')
shutil.copyfile(BASE / 'plugin/cleanup.php', DIST / 'cleanup-thermalright.php')


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


(DIST / 'cleanup-thermalright.sha256').write_text(f'{digest(DIST / "cleanup-thermalright.php")}  cleanup-thermalright.php\n')
source = DIST / 'thermalright-digital-source.tar.xz'
with tarfile.open(source, 'w:xz', preset=6) as archive:
    def accepted(info):
        if '__pycache__' in info.name or info.name.endswith('.pyc'):
            return None
        info.uid = info.gid = 0
        info.uname = info.gname = ''
        info.mtime = 0
        return info
    for name in ['README.md', 'README.fr.md', 'icon.svg', 'icon.png', 'ca_profile.xml', 'plugins', 'LICENSE', 'VERSION', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'project.json', 'dependencies.lock.json', '.gitignore', '.gitattributes', '.editorconfig', '.github', 'backend', 'plugin', 'scripts', 'tests', 'docs', 'licenses', 'third_party']:
        archive.add(BASE / name, arcname=name, filter=accepted)

package = DIST / 'thermalright-digital/packages' / VERSION
files = [DIST / name for name in ['README.md', 'README.fr.md', 'LICENSE', 'CHANGELOG.md', 'audit-residuals.sh', 'cleanup-thermalright.php', 'cleanup-thermalright.sha256', 'thermalright-digital-source.tar.xz', 'thermalright-digital.plg', f'thermalright-digital-update-{VERSION}.tar.gz', f'update-{VERSION}.sha256']]
files += sorted(p for p in (DIST / 'docs').rglob('*') if p.is_file())
files += sorted(p for p in package.iterdir() if p.is_file())
(DIST / 'SHA256SUMS').write_text(''.join(f'{digest(p)}  {p.relative_to(DIST)}\n' for p in sorted(files)))
output = DIST / f'thermalright-digital-{VERSION}.zip'
temporary = output.with_suffix('.zip.tmp')
with zipfile.ZipFile(temporary, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for path in sorted(files + [DIST / 'SHA256SUMS']):
        archive.write(path, path.relative_to(DIST), compress_type=zipfile.ZIP_STORED if path.suffix == '.xz' else zipfile.ZIP_DEFLATED)
temporary.replace(output)
(DIST / 'release.sha256').write_text(f'{digest(output)}  {output.name}\n')

assets = DIST / 'release'
if assets.exists():
    shutil.rmtree(assets)
assets.mkdir()
for path in [*package.iterdir(), output, source, DIST / 'audit-residuals.sh', DIST / 'cleanup-thermalright.php', DIST / 'cleanup-thermalright.sha256', DIST / f'thermalright-digital-update-{VERSION}.tar.gz']:
    if path.is_file():
        shutil.copyfile(path, assets / path.name)
(assets / 'SHA256SUMS').write_text(''.join(f'{digest(p)}  {p.name}\n' for p in sorted(assets.iterdir())))
print(output)
print('GitHub assets :', assets)
