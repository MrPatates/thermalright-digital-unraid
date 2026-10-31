#!/usr/bin/env python3
"""Offline, deterministic payload/PLG packaging from a real exported image."""
import argparse
import hashlib
import io
import json
import re
from pathlib import Path
import shutil
import tarfile
import xml.etree.ElementTree as ET
from xml.sax.saxutils import quoteattr

BASE = Path(__file__).resolve().parents[1]


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def package(output, image, image_id, version, image_tag=None, download_base_url=None, repository=None):
    if not re.fullmatch(r'\d{4}\.\d{2}\.\d{2}\.\d+', version):
        raise ValueError('Invalid version')
    if not re.fullmatch(r'sha256:[a-f0-9]{64}', image_id):
        raise ValueError('Invalid Docker ID')
    if repository and not re.fullmatch(r'[A-Za-z0-9-]+/[A-Za-z0-9_.-]+', repository):
        raise ValueError('Invalid GitHub repository')
    plugin_url = None
    if repository:
        download_base_url = download_base_url or f'https://github.com/{repository}/releases/download/v{version}'
        plugin_url = f'https://github.com/{repository}/releases/latest/download/thermalright-digital.plg'
    if download_base_url and not re.fullmatch(r'https?://[A-Za-z0-9.:-]+(?:/[A-Za-z0-9._/-]+)*', download_base_url):
        raise ValueError('Invalid publication URL')
    if not image.is_file():
        raise SystemExit('Docker export does not exist')
    bundle = output / 'thermalright-digital' / 'packages' / version
    bundle.mkdir(parents=True, exist_ok=True)
    target = bundle / 'backend-image.tar.xz'
    if image.resolve() != target.resolve():
        shutil.copyfile(image, target)
    release = dict(owner='thermalright-digital',version=version,trcc='9.11.2',
                   image_id=image_id,image_tag=image_tag or f'localhost/thermalright-digital:{version}',
                   image_sha256=sha(target),architecture='amd64',schema=1)
    files = {p.name: p.read_bytes() for p in (BASE/'plugin').iterdir() if p.is_file()}
    files['logo.png'] = (BASE/'icon.png').read_bytes()
    # Native Settings titles look under icons/, while plugin/menu icons use root.
    files['icons/logo.png'] = files['logo.png']
    files['release.json'] = (json.dumps(release,indent=2)+'\n').encode()
    files['.td-owner'] = b'thermalright-digital\n'
    for event in ['docker_started','started','stopping','stopping_docker','stopped']:
        action = 'event-stop' if event.startswith('stop') else 'event-start'
        files['event/'+event] = ('#!/bin/bash\nexec /usr/bin/php /usr/local/emhttp/plugins/thermalright-digital/control.php '+action+'\n').encode()
    with tarfile.open(bundle/'payload.txz','w:xz',preset=6) as archive:
        for name,data in sorted(files.items()):
            info = tarfile.TarInfo(name)
            info.size=len(data); info.mtime=0; info.uid=info.gid=0
            info.mode=0o755 if name.startswith('event/') else 0o644
            archive.addfile(info,io.BytesIO(data))
    release['payload_sha256']=sha(bundle/'payload.txz')
    (bundle/'release.json').write_text(json.dumps(release,indent=2)+'\n')
    fetch=(BASE/'scripts/fetch-package.sh.in').read_text().replace('@DOWNLOAD_BASE@',download_base_url or '').replace('@IMAGE_SHA@',release['image_sha256'])
    installer=(BASE/'scripts/install.sh.in').read_text().replace('@FETCH_PACKAGE@',fetch).replace('@VERSION@',version).replace('@PAYLOAD_SHA@',release['payload_sha256']).replace('@RELEASE_SHA@',sha(bundle/'release.json'))
    remove='''#!/bin/bash
set -euo pipefail
active=/usr/local/emhttp/plugins/thermalright-digital
if [[ -f "$active/control.php" ]]; then
  exec /usr/bin/php "$active/control.php" uninstall
fi
if [[ -d /boot/config/plugins/thermalright-digital ]]; then
  echo "Cleanup incomplete: reinstall the same bundle and retry. Inventory preserved." >&2
  exit 1
fi
echo "Already uninstalled."
'''
    author = repository.split('/')[0] if repository else 'Local'
    update_attribute = ' pluginURL='+quoteattr(plugin_url) if plugin_url else ''
    manifest=f'''<?xml version="1.0" encoding="UTF-8"?>
<PLUGIN name="thermalright-digital" author={quoteattr(author)} version="{version}" launch="Settings/ThermalrightDigital" icon="logo.png" support={quoteattr('https://github.com/'+repository+'/issues' if repository else 'https://github.com/MrPatates/thermalright-digital-unraid/issues')} min="6.12.0"{update_attribute}>
<CHANGES>Release {version}. English interface and documentation, shared plugin/Docker icon and uppercase display names. Host CPU metrics, RGB effects and inventory-based cleanup. SHA-256 verified packages.</CHANGES>
<FILE Run="/bin/bash" Method="install"><INLINE><![CDATA[
{installer}
]]></INLINE></FILE>
<FILE Run="/bin/bash" Method="remove"><INLINE><![CDATA[
{remove}
]]></INLINE></FILE>
</PLUGIN>
'''
    ET.fromstring(manifest)
    (bundle/'thermalright-digital.plg').write_text(manifest)
    (output/'thermalright-digital.plg').write_text(manifest)
    return bundle


if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--image',type=Path,required=True)
    p.add_argument('--image-id',required=True)
    p.add_argument('--version',default=(BASE/'VERSION').read_text().strip())
    p.add_argument('--image-tag',help='Actual tag in the exported archive; may be an unchanged backend version')
    p.add_argument('--download-base-url',help='Real published directory containing this version’s package files')
    p.add_argument('--repository',help='GitHub owner/repository for release downloads and native update checks')
    p.add_argument('--output',type=Path,default=BASE/'dist')
    a=p.parse_args()
    print(package(a.output,a.image,a.image_id,a.version,a.image_tag,a.download_base_url,a.repository))
