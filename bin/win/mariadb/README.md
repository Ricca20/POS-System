# MariaDB 10.11 Windows Binaries

This directory holds the bundled MariaDB server used by the Electron shell at
runtime on Windows. The binaries themselves are not committed to git — they
are downloaded by the build pipeline and packaged into the NSIS installer via
the `extraResources` config in `electron/package.json`.

The `electron/db-manager.js` module (task 8.3) supervises this server on
`127.0.0.1:3307` and runs `php artisan migrate --force` on every launch.

## Expected contents

```
bin/win/mariadb/
├── bin/
│   ├── mysqld.exe              the server binary
│   ├── mysql.exe               CLI client
│   ├── mysqladmin.exe          used for graceful shutdown (R3.3)
│   ├── mysqlcheck.exe          used by the MariaDB recovery flow (Error Scenario 2)
│   └── mariadb-install-db.exe  used on first run only (R5.1)
├── share/                      character set + locale files
├── lib/                        InnoDB plugins
└── README                      upstream MariaDB README (kept for license attribution)
```

## Sources

Use the official MariaDB **portable Windows ZIP** from
[mariadb.org/download](https://mariadb.org/download/) — pick the
`10.11.x` LTS line, `Windows x86_64`, and the `.zip` package (NOT the MSI;
the MSI installs to `Program Files`, which we don't want for a portable
runtime).

After downloading, unzip and copy only the `bin/`, `share/`, and `lib/`
directories into this folder; the rest of the upstream tree is not needed.

## Why 10.11 LTS

- Long-term support window through 2028 covers the desktop release horizon.
- Drop-in compatibility with the existing MySQL queries used by the Laravel
  codebase (locked decision #1 — bundle MariaDB, no SQLite migration).
- InnoDB version aligned with what the existing migrations expect.

## Why is this directory empty in git?

Total size is ~150 MB. We commit only the README plus a `.gitkeep` so the
directory layout is preserved; the actual binaries are populated by the
build pipeline before `electron-builder` packages the app.
