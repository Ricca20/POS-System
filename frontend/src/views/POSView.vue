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
        
        <button class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-md shadow">
          Checkout
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { usePosStore } from '../stores/pos';

const posStore = usePosStore();
</script>
