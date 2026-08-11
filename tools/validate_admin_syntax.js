const fs = require('fs');
const path = require('path');
const vm = require('vm');

console.log('=== Admin Syntax Check ===');
const file = path.join(__dirname, '../admin/index.html');
const c = fs.readFileSync(file, 'utf8');

const regex = /<script>([\s\S]*?)<\/script>/gi;
let match, count = 0;
while ((match = regex.exec(c)) !== null) {
  count++;
  try {
    new vm.Script(match[1], { filename: `admin-script-${count}.js` });
    console.log(`✅ Admin Script #${count} syntax is valid!`);
  } catch (e) {
    console.error(`❌ Admin Script #${count} syntax error:`, e.stack);
    process.exit(1);
  }
}
console.log('=== Admin HTML syntax verification complete! ===');
