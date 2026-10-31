#!/usr/bin/env python3
"""Small native-plugin update. Downloads the pinned image if the backend changed."""
from pathlib import Path
import hashlib
import io
import tarfile

base=Path(__file__).resolve().parents[1]
version=(base/'VERSION').read_text().strip()
dist=base/'dist'
package=dist/'thermalright-digital/packages'/version
archive=dist/f'thermalright-digital-update-{version}.tar.gz'
with tarfile.open(archive,'w:gz') as tar:
    for name in ('payload.txz','release.json','thermalright-digital.plg'):
        data=(package/name).read_bytes()
        info=tarfile.TarInfo(f'thermalright-digital/packages/{version}/{name}')
        info.size=len(data);info.mtime=0;info.mode=0o644
        tar.addfile(info,io.BytesIO(data))
with archive.open('rb') as f:digest=hashlib.file_digest(f,'sha256').hexdigest()
(dist/f'update-{version}.sha256').write_text(f'{digest}  {archive.name}\n')
print(archive)
