<?php
/**
 * TH-09 Patch 2 Fix — Replace ey() NIN component
 * The em-dash in "Slip Type — Compact Chips (FIX)" was encoded as \u2014 in JS
 */
$file = 'C:/xampp/htdocs/gemverify/user/index.html';
$content = file_get_contents($file);

echo "=== TH-09 Patch 2 Fix ===\n";
echo "Size: " . number_format(strlen($content)) . " bytes\n\n";

// Guard: already has the new ey?
if (str_contains($content, 'setGdr') || str_contains($content, 'firstname:q,lastname:C')) {
    echo "[SKIP] Patch 2 already applied.\n";
    exit(0);
}

// Find the exact bytes of the problematic part
$start = strpos($content, 'function ey({onProceed:e}){');
$end   = strpos($content, 'function Ay({onProceed:e}){');

if ($start === false || $end === false) {
    echo "[ERROR] Cannot find ey() boundaries\n";
    exit(1);
}

echo "ey() found: bytes $start to $end (length " . ($end - $start) . ")\n";
$old_ey_exact = substr($content, $start, $end - $start);

// Show the problematic label area
$labelPos = strpos($old_ey_exact, 'Slip Type');
echo "Label area: " . substr($old_ey_exact, $labelPos, 50) . "\n";
echo "Label hex: ";
for ($i = $labelPos; $i < $labelPos+30 && $i < strlen($old_ey_exact); $i++) {
    echo sprintf('%02X ', ord($old_ey_exact[$i]));
}
echo "\n";

// Build the new ey() component
$new_ey = 'function ey({onProceed:e}){let[A,n]=I.useState("nin"),updatedUc=uc.map(x=>({...x,price:window.getServicePrice("nin-verification",x.id,x.price)})),[l,o]=I.useState(updatedUc[0]),[t,r]=I.useState(""),[Q,c]=I.useState(""),[q,U]=I.useState(""),[C,E]=I.useState(""),[R,f]=I.useState(""),[Gdr,setGdr]=I.useState("M"),[a,w]=I.useState("");let currentPrice=updatedUc.find(x=>x.id===l.id)?.price||l.price,isConfigured=currentPrice>0;return u("div",{children:[i(v,{breadcrumb:["NIN Services","Verification"],title:"NIN Verification",est:"Instant",price:isConfigured?currentPrice:0}),u("div",{className:"grid lg:grid-cols-[1fr_340px] gap-6",children:[i("div",{className:"space-y-5",children:u(D,{className:"p-5",children:[i(h,{children:"Verification Method"}),i("div",{className:"mt-2 flex flex-wrap gap-2",children:[{id:"nin",label:"NIN Number"},{id:"phone",label:"Phone Number"},{id:"demographic",label:"Demographic Search"}].map((d)=>i("button",{onClick:()=>n(d.id),className:`h-9 px-4 rounded-xl border text-[13px] font-medium transition ${A===d.id?"bg-[#0050FF] text-white border-[#0050FF]":"bg-white dark:bg-white/5 border-slate-200 dark:border-white/10"}`,children:d.label},d.id))}),u("div",{className:"mt-5",children:[i(h,{children:"Slip Type"}),i("div",{className:"mt-2 flex flex-wrap gap-2",children:updatedUc.map((d)=>u("button",{onClick:()=>o(d),className:`h-10 px-3.5 rounded-xl border text-[12px] font-medium flex items-center gap-2 transition ${l.id===d.id?"bg-[#0A1931] text-white border-[#0A1931] dark:bg-white dark:text-black":"bg-white dark:bg-white/5 border-slate-200 dark:border-white/10 hover:border-[#0050FF]/30"}`,children:[i("span",{className:`w-3.5 h-3.5 rounded-full border grid place-items-center ${l.id===d.id?"border-white":"border-slate-300"}`,children:i("span",{className:`w-1.5 h-1.5 rounded-full ${l.id===d.id?"bg-white":"bg-transparent"}`})}),i("span",{children:d.label}),i("span",{className:`ml-1 text-[10px] px-1.5 py-0.5 rounded-md font-bold ${l.id===d.id?"bg-white/20 text-white":"bg-slate-900 text-white dark:bg-white dark:text-black"}`,children:k(d.price)})]},d.id))})]}),u("div",{className:"mt-6 grid md:grid-cols-2 gap-4",children:[A==="nin"&&u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"NIN Number (11 digits)"}),i(y,{value:t,onChange:r,placeholder:"Enter 11-digit NIN"})]}),A==="phone"&&u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"Phone Number"}),i(y,{value:Q,onChange:c,placeholder:"080..."})]}),A==="demographic"&&u(Be,{children:[u("div",{children:[i(h,{req:!0,children:"First Name"}),i(y,{value:q,onChange:U,placeholder:"First name"})]}),u("div",{children:[i(h,{req:!0,children:"Last Name"}),i(y,{value:C,onChange:E,placeholder:"Last name"})]}),u("div",{children:[i(h,{req:!0,children:"Date of Birth"}),i(y,{type:"date",value:R,onChange:f})]}),u("div",{children:[i(h,{req:!0,children:"Gender"}),u("select",{value:Gdr,onChange:(ev)=>setGdr(ev.target.value),className:"w-full h-11 px-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white dark:bg-white/5 text-[13px] outline-none focus:ring-2 focus:ring-[#0050FF]/20 focus:border-[#0050FF]",children:[i("option",{value:"M",children:"Male"}),i("option",{value:"F",children:"Female"})]})]})]}),u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"Transaction PIN"}),i(y,{type:"password",value:a,onChange:w,placeholder:"Enter 4-digit PIN"})]})]}),u("button",{disabled:!isConfigured,onClick:()=>e("NIN Verification",currentPrice,{slug:"nin-verification",variantKey:l.id,pin:a,method:A,nin:t,phone:Q,firstname:q,lastname:C,dob:R,gender:Gdr}),className:"mt-6 w-full h-12 rounded-xl bg-[#0050FF] text-white font-semibold"+(!isConfigured?" opacity-50 cursor-not-allowed pointer-events-none":""),children:isConfigured?["Verify NIN \u2014 ",k(currentPrice)]:"Price not configured"})]})}),u("div",{className:"space-y-4",children:[u(D,{className:"p-4",children:[i("div",{className:"text-sm font-semibold",children:"Service Details"}),u("div",{className:"mt-3 text-xs space-y-2",children:[i(He,{label:"Method",value:A==="nin"?"By NIN":A==="phone"?"By Phone":"Demographic"}),i(He,{label:"Slip",value:l.label}),i(He,{label:"Fee",value:k(currentPrice),bold:!0})]})]}),i(D,{className:"p-4",children:i("div",{className:"text-xs opacity-60",children:"Supports NIN, phone & demographic search. Slip delivered instantly as PDF."})})]})]})]})}}';

// Perform the replacement using byte positions
$before = substr($content, 0, $start);
$after  = substr($content, $end);
$newContent = $before . $new_ey . $after;

// Verify the replacement happened
if (!str_contains($newContent, 'setGdr')) {
    echo "[ERROR] Replacement verification failed!\n";
    exit(1);
}

// Write
file_put_contents($file, $newContent);
echo "[PATCH 2] ey() NIN component replaced successfully ✓\n";
echo "New size: " . number_format(strlen($newContent)) . " bytes\n";

// Verify all 3 patches present
$final = file_get_contents($file);
echo "\n=== Verification ===\n";
echo "GV_API_SERVICES marker: " . (str_contains($final, 'GV_API_SERVICES') ? 'FOUND ✓' : 'MISSING ✗') . "\n";
echo "setGdr (gender state):  " . (str_contains($final, 'setGdr') ? 'FOUND ✓' : 'MISSING ✗') . "\n";
echo "firstname:q payload:    " . (str_contains($final, 'firstname:q') ? 'FOUND ✓' : 'MISSING ✗') . "\n";
echo "pdf_base64 check:       " . (str_contains($final, 'data.pdf_base64') ? 'FOUND ✓' : 'MISSING ✗') . "\n";
echo "pdfModal state:         " . (str_contains($final, 'setPdfModal') ? 'FOUND ✓' : 'MISSING ✗') . "\n";
echo "api-services/submit:    " . (str_contains($final, 'api-services/submit') ? 'FOUND ✓' : 'MISSING ✗') . "\n";
echo "Gender select UI:       " . (str_contains($final, 'value:"M"') && str_contains($final, 'value:"F"') ? 'FOUND ✓' : 'MISSING ✗') . "\n";
echo "Slip Type label fixed:  " . (!str_contains($final, 'Compact Chips (FIX)') ? 'FIXED ✓' : 'STILL HAS FIX MARKER ✗') . "\n";
echo "\n=== All checks complete ===\n";
