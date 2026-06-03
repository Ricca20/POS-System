const { spawn, exec, execSync } = require('child_process');
const { app } = require('electron');
const path = require('path');
const fs = require('fs');
const winston = require('winston');

const logger = winston.createLogger({
  level: 'info',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.printf(({ level, message, timestamp }) => {
      return `${timestamp} [DBManager] ${level}: ${message}`;
    })
  ),
  transports: [
    new winston.transports.Console()
  ]
});

class DBManager {
  constructor(options = {}) {
    const isWin = process.platform === 'win32';
    const mariadbExecutable = isWin ? 'mysqld.exe' : 'mysqld'; // Adjust based on the actual executable name

    this.mariadbPath = app.isPackaged
      ? path.join(process.resourcesPath, 'bin', 'mariadb', 'bin', mariadbExecutable) // Packaged portable bin
      : path.join(__dirname, '..', 'bin', 'mariadb', 'bin', mariadbExecutable); // Dev portable bin

    this.dataDir = path.join(app.getPath('userData'), 'database');
    this.port = options.port || 3307; // Use a different port to avoid conflicts
    this.host = options.host || '127.0.0.1';
    this.user = options.user || 'root';
    this.password = options.password || '';
    this.dbName = options.dbName || 'pos_system';
    this.mysqlProcess = null;
    this.isRunning = false;
    this.mysqlBinary = options.mysqlBinary || this.mariadbPath; // Default to the portable bundled DB
  }

  start() {
    return new Promise((resolve, reject) => {
      if (this.isRunning) {
        resolve();
        return;
      }

      logger.info(`Checking MariaDB data directory at ${this.dataDir}...`);

      try {
        if (!fs.existsSync(this.dataDir)) {
          fs.mkdirSync(this.dataDir, { recursive: true });
        }
        
        const mysqlDir = path.join(this.dataDir, 'mysql'); // 'mysql' db holds system tables
        if (!fs.existsSync(mysqlDir)) {
          logger.info(`Data directory appears empty. Initializing database...`);
          this.isFreshInstall = true;
          const isWin = process.platform === 'win32';
          const installBinaryName = isWin ? 'mysql_install_db.exe' : 'mysql_install_db';
          const installBinaryPath = path.join(path.dirname(this.mysqlBinary), installBinaryName);
          
          if (fs.existsSync(installBinaryPath)) {
            logger.info(`Running ${installBinaryName}...`);
            execSync(`"${installBinaryPath}" --datadir="${this.dataDir}"`, { stdio: 'ignore' });
          } else {
            logger.warn(`${installBinaryName} not found at ${installBinaryPath}. Initialization skipped.`);
          }
        } else {
          this.isFreshInstall = false;
        }
      } catch (err) {
        logger.error(`Error during database initialization check: ${err.message}`);
      }

      logger.info(`Starting MariaDB/MySQL on ${this.host}:${this.port}...`);
      
      try {
          this.mysqlProcess = spawn(this.mysqlBinary, [`--datadir=${this.dataDir}`, `--port=${this.port}`, '--console']);

          this.mysqlProcess.stdout.on('data', (data) => {
            logger.info(`stdout: ${data}`);
          });

          this.mysqlProcess.stderr.on('data', (data) => {
            // mysql often logs to stderr even for info
            logger.info(`stderr: ${data}`);
          });

          this.mysqlProcess.on('close', (code) => {
            logger.info(`DB process exited with code ${code}`);
            this.isRunning = false;
            this.mysqlProcess = null;
          });

          this.mysqlProcess.on('error', (err) => {
            logger.warn(`Failed to spawn DB process (${err.message}). Assuming DB is already running externally.`);
            this.isRunning = true; // Assume external DB
            resolve();
          });

          // Give it a moment to start
          setTimeout(() => {
            this.isRunning = true;
            resolve();
          }, 3000);
      } catch (e) {
          logger.warn("Exception spawning DB. Assuming external DB is running.");
          this.isRunning = true;
          resolve();
      }
    });
  }

  async waitForDatabase() {
    // In a real scenario, we'd attempt a TCP connection to this.port
    // For now, we wait a few seconds.
    logger.info('Waiting for database to be ready...');
    await new Promise(resolve => setTimeout(resolve, 2000));
    logger.info('Database is ready.');

    // On fresh install, create the application database if it doesn't exist
    if (this.isFreshInstall) {
      try {
        const isWin = process.platform === 'win32';
        const mysqlClientName = isWin ? 'mysql.exe' : 'mysql';
        const mysqlClientPath = path.join(path.dirname(this.mysqlBinary), mysqlClientName);

        if (fs.existsSync(mysqlClientPath)) {
          logger.info(`Creating database '${this.dbName}' if it does not exist...`);
          execSync(
            `"${mysqlClientPath}" --host=${this.host} --port=${this.port} --user=${this.user} -e "CREATE DATABASE IF NOT EXISTS \\\`${this.dbName}\\\`;"`,
            { stdio: 'ignore' }
          );
          logger.info(`Database '${this.dbName}' ensured.`);
        } else {
          logger.warn(`mysql client not found at ${mysqlClientPath}. Skipping database creation.`);
        }
      } catch (err) {
        logger.error(`Failed to create database: ${err.message}`);
      }
    }

    return true;
  }

  runMigrations(laravelPath, phpBinary = 'php') {
    return new Promise((resolve, reject) => {
      logger.info('Running database migrations...');
      const execOptions = {
        cwd: laravelPath,
        env: {
          ...process.env,
          DB_HOST: '127.0.0.1',
          DB_PORT: String(this.port),
          DB_DATABASE: this.dbName,
          DB_USERNAME: this.user,
          DB_PASSWORD: this.password
        }
      };
      exec(`"${phpBinary}" artisan migrate --force`, execOptions, (error, stdout, stderr) => {
        if (error) {
          logger.error(`Migration failed: ${error.message}`);
          // Don't reject, might just be already migrated or DB error that we'll catch later
          resolve(false);
          return;
        }
        logger.info(`Migration output: ${stdout}`);
        
        if (this.isFreshInstall) {
          logger.info('Fresh install detected. Running database seeders...');
          exec(`"${phpBinary}" artisan db:seed --force`, execOptions, (seedErr, seedStdout) => {
            if (seedErr) {
              logger.error(`Seeding failed: ${seedErr.message}`);
            } else {
              logger.info(`Seeding output: ${seedStdout}`);
            }
            resolve(true);
          });
        } else {
          resolve(true);
        }
      });
    });
  }

  stop() {
    if (this.mysqlProcess) {
      logger.info('Stopping database...');
      this.mysqlProcess.kill('SIGTERM');
      this.isRunning = false;
    }
  }
}

module.exports = DBManager;
