# Composants de l’image

Le plugin et worker sont GPL-3.0-or-later (LICENSE). TRCC 9.11.2 reste non modifié sous GPL-3.0-or-later. PyUSB 1.3.1 est BSD-3-Clause. Libusb 1.0.26 est LGPL-2.1-or-later. Les archives de leurs sources sont dans third_party dans le paquet source.

La base officielle Python slim-bookworm est un agrégat Debian avec CPython sous licence PSF et diverses bibliothèques système. Le Dockerfile fixe son digest. L’image conserve `/usr/share/doc/*/copyright` et `/usr/share/common-licenses/`. Une copie des notices est dans `docs/evidence/base-copyrights.txt`, l’inventaire exact dans `docs/evidence/image-packages-and-licenses.txt`.

Sources de la base : https://github.com/docker-library/python et https://sources.debian.org/ (versions de l’inventaire). Le bundle ne prétend pas attribuer la licence GPL du plugin à l’ensemble de Debian ou de CPython. Les logos, polices et autres actifs éventuellement présents dans le wheel TRCC conservent leurs notices amont ; le plugin n’affiche aucun de ces actifs. Il n’est affilié ni à Thermalright, ni à Unraid, ni au mainteneur TRCC.
