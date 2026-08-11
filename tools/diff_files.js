const fs = require('fs');
const path = require('path');

const pathCurrent = path.join(__dirname, '../user/index.html');
const pathBackup = path.join(__dirname, '../user/index.html.bak_th09_20260809071749');

const srcCurrent = fs.readFileSync(pathCurrent, 'utf8');
const srcBackup = fs.readFileSync(pathBackup, 'utf8');

if (srcCurrent === srcBackup) {
  console.log('Files are identical');
  process.exit(0);
}

const linesCurrent = srcCurrent.split('\n');
const linesBackup = srcBackup.split('\n');

console.log('--- Diff between current and backup user/index.html ---');
console.log('Current line count:', linesCurrent.length);
console.log('Backup line count:', linesBackup.length);

// Let's print lines that differ
const maxLines = Math.max(linesCurrent.length, linesBackup.length);
let diffCount = 0;
for (let i = 0; i < maxLines; i++) {
  const c = linesCurrent[i] || '';
  const b = linesBackup[i] || '';
  if (c !== b) {
    diffCount++;
    console.log(`Line ${i + 1} differs:`);
    console.log(`  Backup:  ${b.trim().substring(0, 140)}`);
    console.log(`  Current: ${c.trim().substring(0, 140)}`);
    if (diffCount > 30) {
      console.log('Too many diffs, stopping...');
      break;
    }
  }
}
