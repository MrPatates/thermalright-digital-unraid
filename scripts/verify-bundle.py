#!/usr/bin/env python3
"""Final offline consistency check: ZIP, sums, PLG, payload and full source."""
from pathlib import Path
import hashlib
import io
import tarfile
import xml.etree.ElementTree as ET
import zipfile

base=Path(__file__).resolve().parents[1]
dist=base/'dist'
version=(base/'VERSION').read_text().strip()
with zipfile.ZipFile(dist/f'thermalright-digital-{version}.zip') as z:
    assert z.testzip() is None
    for line in z.read('SHA256SUMS').decode().splitlines():
        expected,name=line.split('  ',1)
        assert '..' not in Path(name).parts
        assert hashlib.sha256(z.read(name)).hexdigest()==expected,name
    prefix=f'thermalright-digital/packages/{version}/'
    plg=z.read(prefix+'thermalright-digital.plg')
    assert plg==z.read('thermalright-digital.plg')
    xml=ET.fromstring(plg)
    assert xml.tag=='PLUGIN' and xml.attrib['name']=='thermalright-digital'
    install=xml.find("./FILE[@Method='install']/INLINE").text
    assert xml.find("./FILE[@Method='remove']/INLINE") is not None
    assert hashlib.sha256(z.read(prefix+'payload.txz')).hexdigest() in install
    assert hashlib.sha256(z.read(prefix+'release.json')).hexdigest() in install
    with tarfile.open(fileobj=io.BytesIO(z.read(prefix+'payload.txz')),mode='r:xz') as tar:
        assert xml.attrib['icon']=='logo.png'
        assert tar.extractfile('logo.png').read()==(base/'icon.png').read_bytes()
        assert tar.extractfile('icons/logo.png').read()==(base/'icon.png').read_bytes()
        for p in (base/'plugin').iterdir():
            if p.is_file():assert tar.extractfile(p.name).read()==p.read_bytes(),p.name
        for event in ['docker_started','started','stopping','stopping_docker','stopped']:
            assert tar.getmember('event/'+event).mode & 0o111
    with tarfile.open(fileobj=io.BytesIO(z.read('thermalright-digital-source.tar.xz')),mode='r:xz') as tar:
        for name in ['backend/worker.py','backend/Dockerfile','scripts/build.sh','scripts/verify-bundle.py','plugin/control.php','third_party/trcc_linux-9.11.2.tar.gz','third_party/pyusb-1.3.1.tar.gz','third_party/libusb-1.0_1.0.26.orig.tar.bz2','LICENSE']:
            assert tar.getmember(name).isfile(),name
        assert tar.extractfile('backend/worker.py').read()==(base/'backend/worker.py').read_bytes()
        assert tar.extractfile('plugin/control.php').read()==(base/'plugin/control.php').read_bytes()
        for name in ['README.md','README.fr.md','icon.svg','icon.png','ca_profile.xml','plugins/thermalright-digital.xml']:
            assert tar.extractfile(name).read()==(base/name).read_bytes(),name
    for name in ['README.md','README.fr.md']:
        assert z.read(name)==(base/name).read_bytes(),name
print('OK: ZIP/CRC, all checksums, identical manifests, install/remove scripts, current payload, executable events and complete sources')
