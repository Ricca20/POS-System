<template>
  <div class="h-full flex flex-col md:flex-row gap-6">
    <!-- Products Section -->
    <div class="flex-1 bg-white rounded-lg shadow-sm border border-gray-100 p-4 flex flex-col">
      <div class="mb-4">
        <input type="text" placeholder="Search products..." class="w-full px-4 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
      </div>
      <div class="flex-1 grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 overflow-y-auto content-start">
        <!-- Placeholder Products -->
        <div v-for="i in 8" :key="i" class="border rounded-lg p-4 cursor-pointer hover:shadow-md transition-shadow text-center">
          <div class="w-full h-24 bg-gray-100 rounded-md mb-2"></div>
          <h4 class="font-medium text-gray-800 text-sm">Product {{ i }}</h4>
          <p class="text-blue-600 font-bold mt-1">$10.00</p>
        </div>
      </div>
    </div>

    <!-- Cart Section -->
    <div class="w-full md:w-96 bg-white rounded-lg shadow-sm border border-gray-100 flex flex-col">
      <div class="p-4 border-b">
        <h2 class="text-lg font-bold text-gray-800">Current Order</h2>
      </div>
      
      <!-- Cart Items Placeholder -->
      <div class="flex-1 p-4 overflow-y-auto">
        <div class="text-center text-gray-400 mt-10">
          Cart is empty
        </div>
      </div>

      <!-- Totals & Actions -->
      <div class="p-4 border-t bg-gray-50 rounded-b-lg">
        <div class="flex justify-between mb-2">
          <span class="text-gray-600">Subtotal</span>
          <span class="font-medium">$0.00</span>
        </div>
        <div class="flex justify-between mb-4">
          <span class="text-gray-600">Tax</span>
          <span class="font-medium">$0.00</span>
        </div>
        <div class="flex justify-between mb-6 text-lg font-bold text-gray-900">
          <span>Total</span>
          <span>$0.00</span>
        </div>
        
        <button @click="checkout" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-md shadow">
          Checkout
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { usePosStore } from '../stores/pos';

const posStore = usePosStore();

async function checkout() {
  if (posStore.cart && posStore.cart.length === 0) {
    alert("Cart is empty");
    return;
  }
  
  // Here we would normally make an API call to save the order
  // For the Electron wrapper, we will also trigger a print
  
  if (window.electronAPI && window.electronAPI.printReceipt) {
    const selectedPrinter = localStorage.getItem('selectedPrinter') || '';
    
    // Generate a simple receipt HTML
    const date = new Date().toLocaleString();
    let itemsHtml = '';
    
    // If the cart logic isn't fully implemented in posStore, we just mock it for now
    const cartItems = posStore.cart || [{ name: 'Test Product', quantity: 1, price: 10.00 }];
    const total = posStore.total || 10.00;
    
    cartItems.forEach(item => {
      itemsHtml += `
        <tr>
          <td style="text-align: left;">${item.name} x${item.quantity || 1}</td>
          <td style="text-align: right;">$${Number(item.price).toFixed(2)}</td>
        </tr>
      `;
    });

    const receiptHtml = `
      <div style="font-family: monospace; width: 300px; margin: 0 auto; text-align: center;">
        <h2>Store Receipt</h2>
        <p>${date}</p>
        <hr style="border: 1px dashed black;" />
        <table style="width: 100%; font-size: 14px;">
          ${itemsHtml}
        </table>
        <hr style="border: 1px dashed black;" />
        <h3 style="text-align: right;">Total: $${Number(total).toFixed(2)}</h3>
        <p>Thank you for your purchase!</p>
      </div>
    `;

    try {
      const result = await window.electronAPI.printReceipt(receiptHtml, selectedPrinter);
      if (!result.success) {
        console.error("Print failed:", result.error);
        alert("Failed to print receipt: " + result.error);
      } else {
        console.log("Receipt printed successfully!");
        alert("Checkout complete and receipt printed.");
      }
    } catch (e) {
      console.error("Error invoking print:", e);
    }
  } else {
    alert("Checkout completed. (Printing not supported in browser)");
  }
}
</script>
