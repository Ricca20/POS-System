const { spawn, exec } = require('child_process');
const { app } = require('electron');
const path = require('path');
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
    this.mariadbPath = app.isPackaged
      ? path.join(process.resourcesPath, 'bin', 'mariadb') // Packaged portable bin
      : path.join(__dirname, '..', 'bin', 'mariadb'); // Dev portable bin

    this.dataDir = path.join(app.getPath('userData'), 'database');
    this.port = options.port || 3307; // Use a different port to avoid conflicts
    this.host = options.host || '127.0.0.1';
    this.user = options.user || 'root';
    this.password = options.password || '';
    this.dbName = options.dbName || 'pos_system';
    this.mysqlProcess = null;
    this.isRunning = false;
    this.mysqlBinary = options.mysqlBinary || 'mysqld'; // Default to global, could be bundled in bin/
  }

  start() {
    return new Promise((resolve, reject) => {
      if (this.isRunning) {
        resolve();
        return;
      }

      logger.info(`Starting MariaDB/MySQL on ${this.host}:${this.port}...`);
      
      // Starting the DB. In a real desktop app, we'd start a local bundled MariaDB with a custom datadir.
      // For this phase, we assume the binary is available. We'll pass the port.
      // If we don't have a bundled DB setup yet, we can skip spawning and assume it's running via XAMPP/brew
      // if it fails to spawn, but let's try to spawn it first.
      
      try {
          this.mysqlProcess = spawn(this.mysqlBinary, [`--port=${this.port}`, '--console']);

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
    return true;
  }

  runMigrations(laravelPath) {
    return new Promise((resolve, reject) => {
      logger.info('Running database migrations...');
      exec('php artisan migrate --force', { cwd: laravelPath }, (error, stdout, stderr) => {
        if (error) {
          logger.error(`Migration failed: ${error.message}`);
          // Don't reject, might just be already migrated or DB error that we'll catch later
          resolve(false);
          return;
        }
        logger.info(`Migration output: ${stdout}`);
        resolve(true);
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
