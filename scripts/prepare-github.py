#!/usr/bin/env python3
"""Export a lightweight source repository, excluding local/generated material."""
from pathlib import Path
import hashlib
import re
import zipfile

BASE = Path(__file__).resolve().parents[1]
ROOTS = ['README.md', 'README.fr.md', 'icon.svg', 'icon.png', 'ca_profile.xml', 'plugins', 'LICENSE', 'VERSION', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'project.json', 'dependencies.lock.json', '.gitignore', '.gitattributes', '.editorconfig', '.github', 'backend', 'plugin', 'scripts', 'tests', 'docs', 'licenses', 'third_party']


def source_files(base=BASE):
    files = []
    for name in ROOTS:
        path = base / name
        for item in sorted(path.rglob('*')) if path.is_dir() else [path]:
            if not item.is_file() or item.is_symlink():
                continue
            relative = item.relative_to(base)
            if '__pycache__' in relative.parts or item.suffix in ('.pyc', '.whl'):
                continue
            if relative.parts[0] == 'third_party' and item.name.endswith(('.tar.gz', '.tar.bz2', '.tar.xz')):
                continue
            if item.stat().st_size > 20 * 1024 * 1024:
                raise ValueError('File too large for the repository : ' + str(relative))
            content = item.read_bytes()
            if re.search(rb'192[.]168[.][0-9]+[.][0-9]+|/home/[A-Za-z0-9_.-]+/', content):
                raise ValueError('Local data found in a public file : ' + str(relative))
            files.append(item)
    return files


if __name__ == '__main__':
    output = BASE / 'dist/thermalright-digital-github.zip'
    output.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
        for path in source_files():
            archive.write(path, path.relative_to(BASE))
    with output.open('rb') as stream:
        digest = hashlib.file_digest(stream, 'sha256').hexdigest()
    output.with_suffix('.sha256').write_text(f'{digest}  {output.name}\n')
    print('Source repository ready :', output)
