#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
engine=${CONTAINER_ENGINE:-podman}
version=$(cat VERSION)
repository=${GITHUB_REPOSITORY:-$(python3 -c 'import json; print(json.load(open("project.json"))["repository"])')}
tag="localhost/thermalright-digital:$version"
mkdir -p .build/logs dist
python3 scripts/fetch-dependencies.py
build_args=(--build-arg "TD_VERSION=$version" -t "$tag")
if [[ "$engine" != docker ]]; then build_args=(--format docker "${build_args[@]}"); fi
"$engine" build "${build_args[@]}" backend
CONTAINER_ENGINE="$engine" bash scripts/check.sh "$tag"
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
if [[ "$engine" == docker ]]; then "$engine" save -o "$tmp/image.tar" "$tag"; else "$engine" save --format docker-archive -o "$tmp/image.tar" "$tag"; fi
xz -T2 -6 "$tmp/image.tar"
image_id=$("$engine" image inspect "$tag" --format '{{.Id}}')
[[ $image_id == sha256:* ]] || image_id="sha256:$image_id"
python3 scripts/package.py --image "$tmp/image.tar.xz" --image-id "$image_id" --image-tag "$tag" --version "$version" --repository "$repository"
python3 scripts/verify-image.py "dist/thermalright-digital/packages/$version" | tee .build/logs/image-verification.log
python3 scripts/make-update.py
python3 scripts/finalize.py
python3 scripts/verify-bundle.py | tee .build/logs/bundle-verification.log
python3 scripts/prepare-github.py
