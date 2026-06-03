# Windows Target Binaries

To correctly bundle this application for Windows, you must place the required portable Windows binaries into this folder. When building (`npm run dist:win` in the `electron` directory), these will be packaged along with the application.

## 1. PHP
Download a portable ZIP of PHP for Windows (e.g. VS16 x64 Thread Safe) from [windows.php.net](https://windows.php.net/download/).
Extract the contents directly into `bin/php/`. You should end up with a structure like:
- `bin/php/php.exe`
- `bin/php/php.ini`
- etc.

## 2. MariaDB / MySQL
Download a portable ZIP of MariaDB for Windows from [mariadb.org](https://mariadb.org/download/).
Extract the contents directly into `bin/mariadb/`. You should end up with a structure like:
- `bin/mariadb/bin/mysqld.exe`
- `bin/mariadb/bin/mysql.exe`
- `bin/mariadb/data/` (this folder will be initialized by MariaDB)

## Verification
Ensure the structure looks exactly like this:
```
pos_system/
├── bin/
│   ├── php/
│   │   ├── php.exe
│   │   └── ...
│   ├── mariadb/
│   │   ├── bin/
│   │   │   ├── mysqld.exe
│   │   │   └── ...
│   │   └── data/
```

After these are placed, running `npm run dist:win` inside the `electron/` directory will correctly package the Windows installer containing the portable backend.
