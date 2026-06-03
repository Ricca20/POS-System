'use strict';

const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');
const winston = require('winston');
const DBManager = require('./db-manager');
const ServerManager = require('./server-manager');
const PrinterManager = require('./printer-manager');

const logger = winston.createLogger({
  level: 'info',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.printf(({ level, message, timestamp }) => {
      return `${timestamp} [Main] ${level}: ${message}`;
    })
  ),
  transports: [
    new winston.transports.Console()
  ]
});

// Prevent multiple instances
const gotTheLock = app.requestSingleInstanceLock();
if (!gotTheLock) {
  app.quit();
}

let mainWindow;
const dbManager = new DBManager();
const serverManager = new ServerManager({
    laravelPath: path.join(__dirname, '..') // Point to pos_system root
});
const printerManager = new PrinterManager();

async function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1200,
    height: 800,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false,
      contextIsolation: true,
    },
    show: false // Hide until ready
  });

  try {
    if (app.isPackaged) {
      const indexPath = path.join(__dirname, 'frontend-dist', 'index.html');
      logger.info(`Loading UI from file: ${indexPath}`);
      await mainWindow.loadFile(indexPath);
    } else {
      const devServerUrl = 'http://127.0.0.1:5173';
      logger.info(`Loading UI from dev server: ${devServerUrl}`);
      await mainWindow.loadURL(devServerUrl);
    }
    mainWindow.show();
  } catch (e) {
    logger.error(`Failed to load UI: ${e.message}`);
  }

  mainWindow.on('closed', function () {
    mainWindow = null;
  });
}

app.whenReady().then(async () => {
  logger.info('Electron app is ready. Starting background services...');

  try {
    // 1. Start Database
    await dbManager.start();
    await dbManager.waitForDatabase();

    // 2. Run Migrations (optional/first-run)
    await dbManager.runMigrations(serverManager.laravelPath, serverManager.phpBinary);

    // 3. Start PHP Server
    await serverManager.start();
    await serverManager.waitForServer();

    // 4. Init Printer Manager
    printerManager.init();

    // 5. Create Window
    createWindow();
  } catch (error) {
    logger.error(`Startup sequence failed: ${error.message}`);
    // Show error dialog or fallback
  }

  app.on('activate', function () {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', function () {
  if (process.platform !== 'darwin') app.quit();
});

// Cleanup before quit
app.on('before-quit', () => {
  logger.info('App quitting. Shutting down background services...');
  serverManager.stop();
  dbManager.stop();
});

// --- IPC Handlers ---
ipcMain.handle('get-app-version', () => app.getVersion());
ipcMain.handle('get-api-url', () => `http://127.0.0.1:${serverManager.port}/api/v1`);

