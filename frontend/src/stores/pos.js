import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const usePosStore = defineStore('pos', () => {
  const cart = ref([]);
  const selectedCustomer = ref(null);
  const selectedLocation = ref(null);
  const discountType = ref('percentage');
  const discountAmount = ref(0);

  const subTotal = computed(() => {
    return cart.value.reduce((total, item) => {
      return total + (item.unit_price_inc_tax * item.quantity);
    }, 0);
  });

  const finalTotal = computed(() => {
    let total = subTotal.value;
    if (discountType.value === 'percentage') {
      total = total - (total * (discountAmount.value / 100));
    } else {
      total = total - discountAmount.value;
    }
    return Math.max(0, total);
  });

  function addToCart(product, variation, quantity = 1) {
    const existingIndex = cart.value.findIndex(item => item.variation_id === variation.id);
    if (existingIndex >= 0) {
      cart.value[existingIndex].quantity += quantity;
    } else {
      cart.value.push({
        product_id: product.id,
        variation_id: variation.id,
        name: product.name,
        variation_name: variation.name,
        quantity: quantity,
        unit_price: variation.sell_price_inc_tax, // Default fallback
        unit_price_inc_tax: variation.sell_price_inc_tax
      });
    }
  }

  function removeFromCart(index) {
    cart.value.splice(index, 1);
  }

  function clearCart() {
    cart.value = [];
    selectedCustomer.value = null;
    discountAmount.value = 0;
  }

  return { 
    cart, 
    selectedCustomer, 
    selectedLocation, 
    discountType, 
    discountAmount, 
    subTotal, 
    finalTotal, 
    addToCart, 
    removeFromCart, 
    clearCart 
  };
});
