#!/usr/bin/env python3
"""Fetch exact upstream archives; never extract or install them on the host."""
import hashlib
import json
from pathlib import Path
import tempfile
from urllib.request import urlopen

BASE = Path(__file__).resolve().parents[1]


def fetch(base=BASE):
    lock = json.loads((base / 'dependencies.lock.json').read_text())
    for item in lock['files']:
        path = base / item['path']
        if path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest() == item['sha256']:
            print('OK cache:', item['path'])
            continue
        if path.exists():
            raise RuntimeError('Modified local archive preserved : ' + str(path))
        path.parent.mkdir(parents=True, exist_ok=True)
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as stream:
                temporary = Path(stream.name)
                digest = hashlib.sha256()
                with urlopen(item['url'], timeout=60) as response:
                    while data := response.read(1024 * 1024):
                        digest.update(data)
                        stream.write(data)
            if digest.hexdigest() != item['sha256']:
                raise RuntimeError('SHA-256 mismatch: ' + item['path'])
            temporary.replace(path)
            print('Download verified:', item['path'])
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    fetch()
