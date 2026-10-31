#!/usr/bin/env python3
"""Verify a Docker archive's config ID and every uncompressed layer digest."""
import hashlib
import json
from pathlib import Path
import sys
import tarfile

bundle=Path(sys.argv[1])
release=json.loads((bundle/'release.json').read_text())
archive=bundle/'backend-image.tar.xz'
with archive.open('rb') as f:
    assert hashlib.file_digest(f,'sha256').hexdigest()==release['image_sha256']
with tarfile.open(archive,'r:xz') as tar:
    manifest=json.load(tar.extractfile('manifest.json'))[0]
    data=tar.extractfile(manifest['Config']).read()
    assert 'sha256:'+hashlib.sha256(data).hexdigest()==release['image_id']
    config=json.loads(data)
    assert config['architecture']=='amd64'
    assert config['config']['Labels']['io.local.thermalright-digital.owner']=='thermalright-digital'
    assert release['image_tag'] in manifest['RepoTags']
    expected=config['rootfs']['diff_ids']
    assert len(expected)==len(manifest['Layers'])
    for layer,want in zip(manifest['Layers'],expected):
        stream=tar.extractfile(layer)
        assert 'sha256:'+hashlib.file_digest(stream,'sha256').hexdigest()==want,layer
print(f"OK: archive SHA-256, image ID, architecture, labels, tag, {len(expected)} Docker layers")
