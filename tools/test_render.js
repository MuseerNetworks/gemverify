const fs = require('fs');
const path = require('path');

const html = fs.readFileSync(path.join(__dirname, '../user/index.html'), 'utf8');

// Dynamically extract the script containing function Eu
const scriptRegex = /<script\b[^>]*>([\s\S]*?)<\/script>/gi;
let match, js = "";
while ((match = scriptRegex.exec(html)) !== null) {
  if (match[1].includes('function Eu(')) {
    js = match[1];
    break;
  }
}

if (!js) {
  console.error("Could not find script containing function Eu");
  process.exit(1);
}

// Expose Eu to window by appending it
js += '\nwindow.Eu = Eu;\n';

// Let's create a simulated browser environment with React mocks
const dom = {
  documentElement: {
    classList: {
      toggle: () => {}
    }
  },
  createElement: () => ({ appendChild: () => {}, style: {}, setAttribute: () => {} }),
  querySelector: () => ({ addEventListener: () => {}, querySelectorAll: () => [], style: {}, appendChild: () => {}, setAttribute: () => {} }),
  querySelectorAll: () => [],
  addEventListener: () => {},
  getElementById: (id) => {
    return { style: {}, classList: { remove: () => {} }, addEventListener: () => {}, setAttribute: () => {} };
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

// React mocks
const mockUseState = (init) => {
  let val = init;
  if (typeof init === 'function') {
    val = init();
  }
  return [val, (newVal) => {}];
};

const ReactMock = {
  useState: mockUseState,
  useEffect: (fn, deps) => {},
  useRef: () => ({ current: dom.querySelector() }),
  default: {
    Fragment: 'Fragment'
  }
};

// JSX mock helpers
const uMock = (type, props) => {
  if (props && props.children) {
    if (Array.isArray(props.children)) {
      props.children.forEach(c => {
        if (typeof c === 'function') c();
      });
    } else if (typeof props.children === 'function') {
      props.children();
    }
  }
  return { type, props };
};

const iMock = (type, props) => {
  if (typeof type === 'function') {
    try {
      type(props || {});
    } catch (e) {
      console.error(`Error rendering component ${type.name || 'anonymous'}:`, e);
      throw e;
    }
  }
  return { type, props };
};

const sandbox = {
  window: {
    history: { length: 2 },
    location: { href: '' },
    addEventListener: () => {},
    getServiceEst: (id, fallback) => fallback || "",
    getServicePrice: (id, variant, fallback) => fallback || 0
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
  JSON: JSON,
  getServiceEst: (id, fallback) => fallback || "",
  getServicePrice: (id, variant, fallback) => fallback || 0,
  I: ReactMock, // I is React
  u: uMock,     // u is uJSX helper
  i: iMock      // i is iJSX helper
};

sandbox.window = sandbox;

const vm = require('vm');
try {
  vm.createContext(sandbox);
  vm.runInContext(js, sandbox);
  console.log('✓ Script initialized.');
  
  console.log('--- Simulating Eu component render ---');
  if (typeof sandbox.window.Eu === 'function') {
    sandbox.window.Eu();
    console.log('✓ Eu rendered successfully without crashing!');
  } else {
    console.error('Eu is still not a function on sandbox.window');
  }
} catch (e) {
  console.error('CRASH DURING RENDER:', e);
}
