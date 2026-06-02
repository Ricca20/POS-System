const { spawn } = require('child_process');
const { app } = require('electron');
const path = require('path');
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

    this.phpBinary = app.isPackaged
      ? path.join(process.resourcesPath, 'bin', 'php', 'php') // Assuming a structure like bin/php/php
      : options.phpBinary || 'php'; // Fallback to system PHP in dev
  }

  start() {
    return new Promise((resolve, reject) => {
      if (this.isRunning) {
        resolve();
        return;
      }

      logger.info(`Starting PHP server on ${this.host}:${this.port}...`);
      
      this.phpProcess = spawn(this.phpBinary, ['artisan', 'serve', `--host=${this.host}`, `--port=${this.port}`], {
        cwd: this.laravelPath
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
