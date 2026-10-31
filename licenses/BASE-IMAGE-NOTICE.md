# Base image and component licenses

The plugin, worker and original icon use GPL-3.0-or-later (LICENSE). Unmodified TRCC 9.11.2 uses GPL-3.0-or-later. PyUSB 1.3.1 uses BSD-3-Clause; libusb 1.0.26 uses LGPL-2.1-or-later. Their source archives are in `third_party` in the complete release source package.

The official Python slim-bookworm base combines Debian packages, CPython under the PSF license and system libraries with their respective licenses. The Dockerfile pins its digest. The image retains `/usr/share/doc/*/copyright` and `/usr/share/common-licenses/`. Copied notices are in `docs/evidence/base-copyrights.txt`; the exact package inventory is in `docs/evidence/image-packages-and-licenses.txt`.

Base sources: https://github.com/docker-library/python and https://sources.debian.org/ using the inventory versions. The plugin's GPL license does not relicense Debian or CPython. Logos, fonts and other assets present in the upstream TRCC wheel retain their upstream notices; the plugin does not display those assets. This project is unaffiliated with Thermalright, Unraid and the TRCC maintainer.
