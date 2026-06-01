# PHP 8.2 Windows Binary

This directory holds the bundled PHP runtime used by the Electron shell at
runtime. The binary itself is not committed to git — it is downloaded by the
build pipeline (or placed manually for local dev) and packaged into the NSIS
installer via the `extraResources` config in `electron/package.json`.

## Expected contents

```
bin/win/php/
├── php.exe                 PHP 8.2 NTS x64 CLI
├── php-cgi.exe             optional, for `artisan serve` parity
├── php.ini                 production INI tuned for the desktop runtime
└── ext/                    compiled extensions (see list below)
```

## Required extensions

`php.ini` MUST enable at minimum:

- `mbstring`
- `pdo_mysql`
- `gd`
- `zip`
- `intl`
- `bcmath`
- `xml`
- `curl`
- `openssl`
- `fileinfo`
- `tokenizer`
- `ctype`
- `json` (built-in on 8.x)
- `session`

These mirror the modules used by the existing Laravel application and the
modules under `Modules/*`. Missing any of them causes PHP boot failures that
the Electron `server-manager` surfaces on the fatal startup screen.

## Sources

The recommended way to produce this directory is the
[static-php-cli](https://github.com/crazywhalecc/static-php-cli) project,
which can produce a static, single-file `php.exe` plus a curated extensions
directory for Windows. Alternatively, the official Windows builds at
[windows.php.net](https://windows.php.net/) (NTS x64) are acceptable for
internal testing.

## Update procedure

1. Download or build a fresh `php.exe` matching the target version.
2. Place it (and `ext/`) in this directory.
3. Run the desktop app once locally; the Electron `server-manager` should
   spawn it and `/api/v1/health` should return 200.
4. Bump the PHP version reference in CI (`.github/workflows/desktop-windows.yml`,
   added in task 11.4) so auto-update channels rebuild against the new binary.

## Why is this directory empty in git?

The PHP binary is ~50 MB and is not source code. We commit only the README
plus a `.gitkeep` so the directory layout is preserved; the actual binary is
populated by the build pipeline before `electron-builder` packages the app.
