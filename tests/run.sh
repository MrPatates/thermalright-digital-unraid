#!/bin/bash
set -euo pipefail
cd /work
php -l plugin/control.php
php -l plugin/api.php
php -l plugin/cleanup.php
php -l plugin/settings.php
php -l plugin/schedule.php
php -l plugin/metrics.php
php -l plugin/i18n.php
php -l plugin/ThermalrightDigital.page
php -l tests/fake-docker.php
php tests/lifecycle.php
php tests/security.php
php tests/csrf-integration.php
php tests/cleanup.php
php tests/settings.php
php tests/schedule.php
php tests/metrics.php
php tests/i18n.php
python3 tests/installer.py
