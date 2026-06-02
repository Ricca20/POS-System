const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  // Methods for interacting with the main process can go here
  // For example, reading app version, showing native dialogs, etc.
  // Expose print APIs
  getPrinters: () => ipcRenderer.invoke('get-printers'),
  printReceipt: (html, printerName) => ipcRenderer.invoke('print-receipt', { html, printerName }),
  
  // Expose a safe way to listen to main process events
  onUpdateAvailable: (callback) => ipcRenderer.on('update-available', (_event, value) => callback(value)),
});
