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

  // Check if we are running in dev mode or production
  // For now, assume production and load from local PHP server
  // The PHP server serves the Vue frontend if configured, or just the API
  // In our case, the frontend might be running on a dev server (Vite: 5173) or built.
  // We'll point it to the PHP server port which will serve the API and the built Vue app.
  
  // Actually, we'll just point it to the PHP server:
  const serverUrl = `http://127.0.0.1:${serverManager.port}`;
  
  try {
    logger.info(`Loading UI from ${serverUrl}`);
    await mainWindow.loadURL(serverUrl);
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
    await dbManager.runMigrations(path.join(__dirname, '..'));

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

