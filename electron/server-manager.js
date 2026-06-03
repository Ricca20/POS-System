const { spawn, execSync } = require('child_process');
const { app } = require('electron');
const path = require('path');
const fs = require('fs');
const winston = require('winston');

const logger = winston.createLogger({
  level: 'info',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.printf(({ level, message, timestamp }) => {
      return `${timestamp} [ServerManager] ${level}: ${message}`;
    })
  ),
  transports: [
    new winston.transports.Console()
  ]
});

class ServerManager {
  constructor(options = {}) {
    this.port = options.port || 8000;
    this.host = options.host || '127.0.0.1';
    this.phpProcess = null;
    this.isRunning = false;
    // Assume laravel root is parent directory of electron/
    // If we're packaged, use resourcesPath. Otherwise, use the provided config (which is __dirname/..)
    this.laravelPath = app.isPackaged 
      ? path.join(process.resourcesPath, 'laravel')
      : (options.laravelPath || path.join(__dirname, '..'));

    const isWin = process.platform === 'win32';
    const phpExecutable = isWin ? 'php.exe' : 'php';

    this.phpBinary = app.isPackaged
      ? path.join(process.resourcesPath, 'bin', 'php', phpExecutable)
      : options.phpBinary || 'php'; // Fallback to system PHP in dev
  }

  start() {
    return new Promise((resolve, reject) => {
      if (this.isRunning) {
        resolve();
        return;
      }

      // Check and scaffold .env if needed
      const envPath = path.join(this.laravelPath, '.env');
      const envExamplePath = path.join(this.laravelPath, '.env.example');
      
      if (!fs.existsSync(envPath) && fs.existsSync(envExamplePath)) {
        logger.info('.env file missing, creating from .env.example...');
        fs.copyFileSync(envExamplePath, envPath);
        try {
          logger.info('Generating app key...');
          execSync(`"${this.phpBinary}" artisan key:generate`, { cwd: this.laravelPath });
        } catch (err) {
          logger.error(`Failed to generate app key: ${err.message}`);
        }
      }

      // Pre-create Laravel storage directories in UserData to avoid permission errors
      const storagePath = path.join(app.getPath('userData'), 'laravel_storage');
      const requiredDirs = [
        'app/public',
        'framework/cache/data',
        'framework/sessions',
        'framework/testing',
        'framework/views',
        'logs'
      ];
      
      requiredDirs.forEach(dir => {
        const fullPath = path.join(storagePath, dir);
        if (!fs.existsSync(fullPath)) {
          fs.mkdirSync(fullPath, { recursive: true });
        }
      });

      logger.info(`Starting PHP server on ${this.host}:${this.port}...`);
      
      this.phpProcess = spawn(this.phpBinary, ['artisan', 'serve', `--host=${this.host}`, `--port=${this.port}`], {
        cwd: this.laravelPath,
        env: {
          ...process.env,
          DB_HOST: '127.0.0.1',
          DB_PORT: '3307',
          DB_DATABASE: 'pos_system',
          DB_USERNAME: 'root',
          DB_PASSWORD: '',
          LARAVEL_STORAGE_PATH: storagePath
        }
      });

      this.phpProcess.stdout.on('data', (data) => {
        logger.info(`stdout: ${data}`);
      });

      this.phpProcess.stderr.on('data', (data) => {
        logger.error(`stderr: ${data}`);
      });

      this.phpProcess.on('close', (code) => {
        logger.info(`PHP process exited with code ${code}`);
        this.isRunning = false;
        this.phpProcess = null;
      });

      // Give artisan serve a moment to bind to the port
      setTimeout(() => {
        this.isRunning = true;
        resolve();
      }, 2000);
    });
  }

  async waitForServer() {
    const maxRetries = 15;
    const interval = 1000;
    let retries = 0;

    logger.info('Waiting for PHP server to be ready...');

    while (retries < maxRetries) {
      try {
        const response = await fetch(`http://${this.host}:${this.port}/api/v1/health`);
        if (response.ok) {
          logger.info('PHP Server is healthy and ready!');
          return true;
        }
      } catch (error) {
        // Fetch failed, server not ready yet
      }

      retries++;
      await new Promise(resolve => setTimeout(resolve, interval));
    }

    throw new Error('PHP server failed to start or is not responding to health check.');
  }

  stop() {
    if (this.phpProcess) {
      logger.info('Stopping PHP server...');
      this.phpProcess.kill('SIGTERM');
      this.isRunning = false;
    }
  }
}

module.exports = ServerManager;
