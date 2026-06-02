<template>
  <div class="max-w-2xl mx-auto py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Hardware Settings</h1>
    
    <div class="bg-white shadow rounded-lg p-6 mb-6">
      <h2 class="text-lg font-medium text-gray-900 mb-4">Receipt Printer Configuration</h2>
      
      <div v-if="printers.length === 0" class="text-gray-500 mb-4">
        No printers detected or running in a standard web browser.
      </div>
      
      <div v-else class="space-y-4">
        <div>
          <label for="printer" class="block text-sm font-medium text-gray-700">Select Receipt Printer</label>
          <select v-model="selectedPrinter" id="printer" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
            <option value="">-- Default System Printer --</option>
            <option v-for="printer in printers" :key="printer.name" :value="printer.name">
              {{ printer.displayName }}
            </option>
          </select>
        </div>
        
        <button @click="testPrint" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md shadow-sm">
          Print Test Page
        </button>
        
        <div v-if="printResult" :class="printResult.success ? 'text-green-600' : 'text-red-600'" class="mt-2 text-sm">
          {{ printResult.message }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const printers = ref([]);
const selectedPrinter = ref('');
const printResult = ref(null);

onMounted(async () => {
  // Check if we're running inside Electron
  if (window.electronAPI && window.electronAPI.getPrinters) {
    try {
      printers.value = await window.electronAPI.getPrinters();
    } catch (e) {
      console.error('Failed to fetch printers:', e);
    }
  }
});

async function testPrint() {
  if (!window.electronAPI) {
    alert("Printing is only supported in the Desktop App.");
    return;
  }
  
  printResult.value = null;
  const testHtml = `
    <html>
      <body style="font-family: monospace; width: 300px; padding: 10px; text-align: center;">
        <h2>POS System</h2>
        <p>This is a test receipt.</p>
        <p>Printer: ${selectedPrinter.value || 'Default'}</p>
        <p>--------------------------------</p>
        <p>Status: OK</p>
      </body>
    </html>
  `;
  
  try {
    const response = await window.electronAPI.printReceipt(testHtml, selectedPrinter.value);
    if (response.success) {
      printResult.value = { success: true, message: 'Print job sent successfully!' };
    } else {
      printResult.value = { success: false, message: 'Print failed: ' + response.error };
    }
  } catch (e) {
    printResult.value = { success: false, message: 'Print error: ' + e.message };
  }
}
</script>
