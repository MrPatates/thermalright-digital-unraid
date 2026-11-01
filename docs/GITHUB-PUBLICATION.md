# Publishing on GitHub

Repository: **MrPatates/thermalright-digital-unraid**. The first public release is **2026.10.31.8**; the release version in this checkout is **2026.11.01.1**. For a fork, change `project.json` and update Community Apps and README links before publishing under another account.

## Publish sources

For an initial import, create an empty public repository, extract `dist/thermalright-digital-github.zip` into an empty directory and keep `.github`, `.gitignore`, `.gitattributes` and `.editorconfig`.

```bash
git init -b main
git add .
git commit -m "Prepare THERMALRIGHT DIGITAL for Unraid"
git remote add origin https://github.com/MrPatates/thermalright-digital-unraid.git
git push -u origin main
```

For an existing clone, commit and push the reviewed changes on `main`. Authenticate through your Git client's credential manager or GitHub CLI. Set a Git name and commit email if requested. Use HTTPS authentication or a GitHub token, not an account password; see [GitHub's credential guide](https://docs.github.com/en/get-started/git-basics/caching-your-github-credentials-in-git). Never put a token in source files.

The **Validation** workflow builds the backend and runs the suites. Test logs can be downloaded from Actions.

## Prepare and publish a release

After validation succeeds, create a tag matching VERSION exactly:

```bash
git tag -a v2026.11.01.1 -m "THERMALRIGHT DIGITAL 2026.11.01.1"
git push origin v2026.11.01.1
```

The **Draft release** workflow rebuilds and verifies the image, creates a draft and attaches `dist/release/`. Review it in **Releases**, leave **This is a pre-release** unchecked, select **Set as latest release** if offered, then click **Publish release**. The stable release is used by `releases/latest`.

The manifest pins downloads to the repository and version used by the workflow. Its `pluginURL` points to the latest stable manifest:

```text
https://github.com/MrPatates/thermalright-digital-unraid/releases/latest/download/thermalright-digital.plg
```

Required annexes beside the manifest are `payload.txz`, `release.json` and `backend-image.tar.xz`. Also attach the complete offline ZIP, source package and `SHA256SUMS`. Draft assets require authentication and cannot be installed publicly from Unraid.

## Manual build

```bash
CONTAINER_ENGINE=podman bash scripts/build.sh
cd dist/release
sha256sum -c SHA256SUMS
```

Create the matching tagged release and attach **all** files in `dist/release/`. Do not combine files from different builds: each manifest pins specific hashes and a Docker image ID.

For each future release, update VERSION, CHANGELOG, backend default build version and release notes; build, verify and push a new tag. Published releases and tags must remain immutable. Official Actions are pinned by commit. Large archives belong in release assets, not Git; see [GitHub's large-file guide](https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-large-files-on-github).

## Verify publication

The tag must point to the checked commit. Download the public latest installation link without authentication, verify `SHA256SUMS`, inspect the versioned annexes and check that source/payload match the tag. Publishing source code alone does not produce an installable plugin.

GitHub and local builds may have different image IDs and archive hashes. Always distribute one build's manifest and annexes together, under a new version number. Community Applications submission is described [separately](COMMUNITY-APPLICATIONS.md).
