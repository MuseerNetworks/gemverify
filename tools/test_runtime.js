const fs = require('fs');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '../user/index.html'), 'utf8');
const scriptMatch = html.match(/<script>([\s\S]*?)<\/script>/);
const js = scriptMatch[1];

// Let's create a simulated browser environment
const dom = {
  documentElement: {
    classList: {
      toggle: (cls, state) => console.log(`classList.toggle(${cls}, ${state})`)
    }
  },
  createElement: () => ({ appendChild: () => {}, style: {} }),
  querySelector: () => ({ addEventListener: () => {}, querySelectorAll: () => [], style: {}, appendChild: () => {} }),
  querySelectorAll: () => [],
  addEventListener: () => {},
  getElementById: (id) => {
    console.log(`document.getElementById(${id}) called`);
    return { style: {}, classList: { remove: () => {} }, addEventListener: () => {} };
  }
};

const ls = {
  getItem: (key) => {
    if (key === 'gv_token') return 'mock-token';
    if (key === 'gv_user') return JSON.stringify({ business_name: 'Test Business' });
    return null;
  },
  setItem: () => {}
};

const sandbox = {
  window: {
    history: { length: 2 },
    location: { href: '' },
    addEventListener: () => {}
  },
  document: dom,
  localStorage: ls,
  navigator: {},
  console: {
    log: console.log,
    error: console.error,
    warn: console.warn
  },
  setTimeout: setTimeout,
  setInterval: setInterval,
  clearTimeout: clearTimeout,
  clearInterval: clearInterval,
  parseFloat: parseFloat,
  Number: Number,
  Date: Date,
  String: String,
  Array: Array,
  Object: Object,
  JSON: JSON
};

// Bind window to sandbox itself for globals
sandbox.window = sandbox;

const vm = require('vm');
try {
  vm.createContext(sandbox);
  // Execute the script
  vm.runInContext(js, sandbox);
  console.log('✓ Script executed successfully.');
} catch (e) {
  console.error('Runtime Error:', e);
}
