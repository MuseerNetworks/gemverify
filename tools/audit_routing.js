const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '../user/index.html'), 'utf8');

const idx = src.indexOf('Proceed to Pay"');
const idx2 = src.indexOf('Proceed to Pay"', idx + 1);

if (idx2 !== -1) {
  const trailingPart = src.substring(idx2 + 15, idx2 + 35);
  console.log('trailingPart:', trailingPart);
  for (let i = 0; i < trailingPart.length; i++) {
    console.log(i, trailingPart[i], trailingPart.charCodeAt(i));
  }
}
