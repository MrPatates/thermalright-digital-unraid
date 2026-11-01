#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
engine=${CONTAINER_ENGINE:-podman}
tag=${1:-localhost/thermalright-digital:$(cat VERSION)}
mkdir -p .build/logs
run_check() {
    local log_file=$1
    shift
    if "$@" > "$log_file" 2>&1; then return 0; else
        local result=$?
        tail -n 35 "$log_file"
        return "$result"
    fi
}
run_check .build/logs/integration-tests.log "$engine" run --rm -v "$PWD:/work:ro,z" -w /work docker.io/library/php:8.3-cli-alpine@sha256:74c3e6f3f78d8bdd090b1d565ef0040c4cd8c7b3757996638cba05dcb87fc94a sh -c 'apk add --no-cache bash coreutils tar xz python3 util-linux curl >/dev/null && bash tests/run.sh'
run_check .build/logs/http-fpm-tests.log "$engine" run --rm -v "$PWD:/work:ro,z" -w /work docker.io/library/php:8.3-fpm-alpine@sha256:25e15cf35ed0f5b75d8cf14fc2c4ed7e0e6bee00d359e681b7fa3e91a7bee49f sh -c 'apk add --no-cache python3 util-linux >/dev/null && python3 tests/test_http_fpm.py'
run_check .build/logs/worker-tests.log "$engine" run --rm -v "$PWD:/work:ro,z" -w /work --entrypoint python "$tag" -m unittest discover -s tests -p test_worker.py -v
run_check .build/logs/ui-tests.log node tests/test_ui.js
run_check .build/logs/translation-tests.log python3 tests/test_translations.py
run_check .build/logs/release-tests.log python3 -m unittest discover -s tests -p test_release.py -v
printf '%s\n' 'OK: PHP, PHP-FPM, TRCC worker, UI and release suites'
