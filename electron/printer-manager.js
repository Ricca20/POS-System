const { BrowserWindow, ipcMain } = require('electron');
const winston = require('winston');

const logger = winston.createLogger({
  level: 'info',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.printf(({ level, message, timestamp }) => {
      return `${timestamp} [PrinterManager] ${level}: ${message}`;
    })
  ),
  transports: [
    new winston.transports.Console()
  ]
});

class PrinterManager {
  constructor() {
    this.hiddenWindow = null;
  }

  init() {
    logger.info('Initializing Printer Manager...');
    
    // Create a hidden window for rendering receipts
    this.hiddenWindow = new BrowserWindow({
      show: false,
      webPreferences: {
        nodeIntegration: false,
        contextIsolation: true,
      }
    });

    this.registerIpcHandlers();
  }

  registerIpcHandlers() {
    // 1. Get available printers
    ipcMain.handle('get-printers', async (event) => {
      if (!this.hiddenWindow) return [];
      try {
        const printers = await this.hiddenWindow.webContents.getPrintersAsync();
        return printers.map(p => ({
          name: p.name,
          displayName: p.displayName || p.name,
          description: p.description,
          isDefault: p.isDefault
        }));
      } catch (error) {
        logger.error(`Failed to get printers: ${error.message}`);
        return [];
      }
    });

    // 2. Print receipt
    ipcMain.handle('print-receipt', async (event, { html, printerName }) => {
      if (!this.hiddenWindow) {
        logger.error('Hidden window is not initialized');
        return { success: false, error: 'Printer manager not initialized' };
      }

      try {
        // Load the HTML content into the hidden window
        const dataUrl = 'data:text/html;charset=utf-8,' + encodeURIComponent(html);
        await this.hiddenWindow.loadURL(dataUrl);

        // Prepare print options
        const printOptions = {
          silent: true,
          printBackground: true,
          deviceName: printerName || '' // if empty, prints to default printer
        };

        return new Promise((resolve) => {
          this.hiddenWindow.webContents.print(printOptions, (success, failureReason) => {
            if (!success) {
              logger.error(`Print failed: ${failureReason}`);
              resolve({ success: false, error: failureReason });
            } else {
              logger.info(`Successfully sent print job to ${printerName || 'default printer'}`);
              resolve({ success: true });
            }
          });
        });

      } catch (error) {
        logger.error(`Error during print process: ${error.message}`);
        return { success: false, error: error.message };
      }
    });
  }
}

module.exports = PrinterManager;
