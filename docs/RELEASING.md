# Releasing TryHackX Files

This checklist is for every tagged release. Run it from the repository root. A release must be
built from a reviewed commit, never directly from a working deployment directory containing
uncommitted runtime data.

## 1. Freeze the release scope

Before staging files:

1. finish the release notes and version update. The number appears in `src/config.php`
   (`APP_VERSION`), the fallback in `scripts/mail-worker.php`, `SERVER_VERSION` in
   `upload_server.py`, the first `## [x.y.z]` heading of `CHANGELOG.md` and the sentences in
   `docs/MIGRATION.md`, `docs/P24.md` and `docs/ROADMAP.md`; `scripts/check-repository-policy.php`
   fails when they disagree. Also state the schema version in `docs/MIGRATION.md` and describe any
   change to `scripts/*.service` or `requirements-lock.txt` there, because the operator applies
   those with `FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh` and a rebuilt `venv/`;
2. make `LICENSE`, the README license section and package metadata agree;
3. identify the copyright holder and an approved commercial-licensing contact channel;
4. preserve the licenses and notices of bundled third-party components;
5. back up the database, `config/config.local.php`, `data/` and `uploads/` outside the
   repository.

Never publish an installation key, database credentials, sessions, payment credentials, uploaded
files, thumbnails, advertisements or logs. Do not use `git clean -fdx` or `git clean -fdX` in an
installed TryHackX Files tree: both commands can erase ignored production state.

## 2. Remove only reproducible local artifacts

The following cleanup is deliberately allowlisted. It does not touch `config/`, `data/` or
`uploads/`.

PowerShell:

```powershell
$releaseCachePaths = @(
    '.ruff_cache',
    '.pytest_cache',
    '__pycache__',
    'tests/python/__pycache__',
    'tests/php/.phpunit.cache',
    'src/data',
    'tools/phpunit.phar'
)

foreach ($releaseCachePath in $releaseCachePaths) {
    if (Test-Path -LiteralPath $releaseCachePath) {
        Remove-Item -LiteralPath $releaseCachePath -Recurse -Force
    }
}

Get-ChildItem -LiteralPath 'tests/php' -Directory -Force |
    Where-Object { $_.Name -like '.tmp-data-*' -or $_.Name -like '.tmp-uploads-*' } |
    Remove-Item -Recurse -Force
```

Debian/Bash:

```bash
rm -rf -- \
  .ruff_cache .pytest_cache __pycache__ tests/python/__pycache__ \
  tests/php/.phpunit.cache src/data tools/phpunit.phar

find tests/php -mindepth 1 -maxdepth 1 -type d \
  \( -name '.tmp-data-*' -o -name '.tmp-uploads-*' \) \
  -exec rm -rf -- {} +
```

`vendor/` and `venv/` are ignored and reproducible. They may remain for local testing; deleting
them is optional and requires reinstalling dependencies. Keep the tracked `tools/.gitkeep` file so
CI and the documented PHPUnit download command have a destination in a fresh clone.

## 3. Prove that private paths are ignored

PowerShell:

```powershell
$privatePaths = @(
    '.env',
    '.env.production',
    'config/config.local.php',
    'scripts/filehost-env.bat',
    'data/.appkey',
    'data/install.lock',
    'uploads/example-private-file.bin'
)

foreach ($privatePath in $privatePaths) {
    git check-ignore -q -- $privatePath
    if ($LASTEXITCODE -ne 0) {
        throw "Private path is not ignored: $privatePath"
    }
}
```

Debian/Bash:

```bash
for private_path in \
  .env .env.production config/config.local.php scripts/filehost-env.bat \
  data/.appkey data/install.lock uploads/example-private-file.bin
do
  git check-ignore -q -- "$private_path" || {
    echo "Private path is not ignored: $private_path" >&2
    exit 1
  }
done
```

The public protection files are intentional exceptions and must remain trackable:

- `data/.htaccess` and `data/index.php`;
- `uploads/.htaccess` and `uploads/index.php`.

Use `git status --short --ignored` to inspect both the release candidates and ignored local state.
A large ignored `uploads/` directory is expected in an installed development instance; it must
not be staged or deleted as release cleanup.

## 4. Run the release gates

Run the repository policy and all locally available checks. The complete commands, including the
mandatory isolated-database setup for PHPUnit, are documented in [TESTING.md](TESTING.md).

Common cross-platform gates:

```text
php scripts/check-repository-policy.php
composer validate --strict
composer analyse
python -m ruff check upload_server.py tests/python
python -m pytest tests/python -q
node --test tests/js/*.test.js
```

Use `venv\\Scripts\\python.exe` instead of `python` in PowerShell and `venv/bin/python` on
Debian when running from the project virtual environment.

PowerShell PHP syntax check:

```powershell
Get-ChildItem public,scripts,src,tests/php -Recurse -Filter *.php |
    ForEach-Object {
        php -l $_.FullName
        if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $($_.FullName)" }
    }
```

Debian PHP syntax check:

```bash
find public scripts src tests/php -type f -name '*.php' -print0 |
  xargs -0 -n1 php -l
```

Docker is optional on a workstation. The GitHub Actions Docker smoke job remains a required gate
before tagging a release.

## 5. Stage and inspect the exact release

Stage only after the ignore checks and tests pass:

```text
git add --all
git status --short
git diff --cached --stat
git diff --cached --check
```

Review every staged path. In particular, the staged set must not contain:

- `.env` variants other than `.env.example`;
- `config.local.php` or `scripts/filehost-env.bat`;
- generated contents of `data/`, `uploads/` or `src/data/`;
- logs, database dumps, caches, `vendor/`, `venv/` or downloaded test tools.

Check staged file sizes. Investigate every unexpected file over 10 MiB before committing.

PowerShell:

```powershell
$largeStagedFiles = git diff --cached --name-only --diff-filter=ACMR |
    ForEach-Object {
        if (Test-Path -LiteralPath $_ -PathType Leaf) {
            $stagedItem = Get-Item -LiteralPath $_
            if ($stagedItem.Length -gt 10MB) {
                [PSCustomObject]@{
                    Path = $_
                    MiB = [math]::Round($stagedItem.Length / 1MB, 2)
                }
            }
        }
    }

$largeStagedFiles | Format-Table -AutoSize
if ($largeStagedFiles) { throw 'Unexpected large staged files require review.' }
```

Debian/Bash:

```bash
large_staged=0
while IFS= read -r staged_path; do
  [ -f "$staged_path" ] || continue
  staged_size=$(stat -c '%s' -- "$staged_path")
  if [ "$staged_size" -gt 10485760 ]; then
    printf '%s (%s bytes)\n' "$staged_path" "$staged_size" >&2
    large_staged=1
  fi
done < <(git diff --cached --name-only --diff-filter=ACMR)
[ "$large_staged" -eq 0 ]
```

Run `php scripts/check-repository-policy.php` once more against the final staged working tree.

## 6. Publish the commit, then the tag

Verify the author identity that Git will embed permanently in public history. If the personal
mailbox must stay private, use the exact GitHub-provided `noreply` address from **GitHub
Settings -> Emails**; do not invent or guess the numeric form:

```text
git config --local --get user.name
git config --local --get user.email
```

Create and push the release commit first:

```text
git commit -m "Prepare TryHackX Files X.Y.Z"
git push origin main
```

Wait until the CI run of that push to `main` has passed completely, the Docker smoke included
(the Actions tab, or `gh run watch`). Pushing the tag together with `main` only starts a second,
identical run and gives no verdict before the tag exists, so tag afterwards, from that exact
commit:

```text
git tag -a vX.Y.Z -m "TryHackX Files X.Y.Z"
git push origin vX.Y.Z
```

Only the newest release is supported ([SECURITY.md](../SECURITY.md)), so only its tag is kept.
Delete the previous tag locally and on GitHub once the new one is pushed:

```text
git tag -d vA.B.C
git push origin --delete refs/tags/vA.B.C
```

The release notes are the version's section in `CHANGELOG.md`; no GitHub Release is published.
Operators deploy the tagged source archive
(`https://github.com/TryHackX/TryHackX-Files/archive/refs/tags/vX.Y.Z.tar.gz`) or a checkout of
the tag, never a copy made from a live working directory. Security-relevant configuration and
migration steps belong in the changelog section and in [MIGRATION.md](MIGRATION.md).

Keep private vulnerability reporting (**Settings → Advanced Security**;
[SECURITY.md](../SECURITY.md) sends reporters there), Dependabot alerts and GitHub secret scanning
enabled. `main` is not branch-protected, so the green run before tagging is checked by hand. A
ruleset that requires the CI checks would enforce it; it has to name the job names in
`.github/workflows/ci.yml`, which change whenever the test matrix does.
