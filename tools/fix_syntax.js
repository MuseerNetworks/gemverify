const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '../user/index.html');
let src = fs.readFileSync(filePath, 'utf8');

// 1. Fix the start of nesting
const targetStart = 'setPinSubmitting?"Setting PIN...":"Set PIN"})]})]}),showTopUpModal&&i("div"';
const replacementStart = 'setPinSubmitting?"Setting PIN...":"Set PIN"})]})]})}),showTopUpModal&&i("div"';

if (src.includes(targetStart)) {
  src = src.replace(targetStart, replacementStart);
  console.log('✓ Fixed start of showTopUpModal nesting');
} else {
  console.log('Start block might be already replaced, verifying...');
}

// 2. Fix the end of nesting dynamically by finding the second "Proceed to Pay"
const searchStr = 'Proceed to Pay"';
const idx1 = src.indexOf(searchStr);
const idx2 = src.indexOf(searchStr, idx1 + 1);

if (idx2 !== -1) {
  console.log('Found second Proceed to Pay at:', idx2);
  const trailingPart = src.substring(idx2 + 15, idx2 + 35);
  console.log('Trailing characters:', trailingPart);
  
  // We want to replace the trailing part of the top-up modal which is:
  // "})]})]})})})])]})}" (closes button, form, inner card, top-up modal, Set PIN modal, main container)
  // with:
  // "})]})]})})])]})}" (closes button, form, inner card, top-up modal, main container)
  // This removes the extra `})` of the Set PIN modal.
  
  const targetTrailing = '})]})]})})})])]})}';
  const replacementTrailing = '})]})]})})])]})}';
  
  if (trailingPart.startsWith(targetTrailing)) {
    src = src.substring(0, idx2 + 15) + replacementTrailing + src.substring(idx2 + 15 + targetTrailing.length);
    console.log('✓ Fixed end of showTopUpModal nesting dynamically');
  } else {
    // If it's already replaced (e.g. on a subsequent run), we should check
    if (trailingPart.startsWith(replacementTrailing)) {
      console.log('✓ End nesting is already correct');
    } else {
      console.error('Trailing characters do not match target trailing:', targetTrailing);
      process.exit(1);
    }
  }
} else {
  console.error('Second Proceed to Pay not found');
  process.exit(1);
}

fs.writeFileSync(filePath, src);
console.log('Surgical fix applied successfully.');
