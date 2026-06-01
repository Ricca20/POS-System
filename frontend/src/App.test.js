import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import App from './App.vue';

/**
 * Smoke test: the SPA root component mounts and renders the brand text.
 * This also exercises the vitest + happy-dom + @vue/test-utils toolchain
 * end-to-end so subsequent tasks can layer real component tests on top.
 */
describe('App.vue', () => {
  it('renders the POS Desktop heading', () => {
    const wrapper = mount(App, {
      global: {
        stubs: ['router-view'],
      },
    });
    expect(wrapper.text()).toContain('POS Desktop');
  });
});
