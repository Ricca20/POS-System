'use strict';

/**
 * Electron main-process entry point for the POS Desktop App.
 *
 * This file is intentionally a stub for task 1.1. The real lifecycle
 * (DB → server → license → window) is wired up in subsequent tasks
 * (server-manager, db-manager, license-validator, printer-manager,
 * updater, ipc-handlers).
 *
 * Validates: R1.1 (the Electron shell exists as a buildable artifact).
 */

const { app } = require('electron');

app.whenReady().then(() => {
  // No-op until task 2.x wires up the supervised processes and BrowserWindow.
});
