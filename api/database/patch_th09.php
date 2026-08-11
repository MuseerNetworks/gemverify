<?php
/**
 * GemVerify TH-09 Patch Script
 * 
 * Makes 3 surgical edits to user/index.html:
 *  1. Replace doSubmitPayment (lines 1086-1191) with API-routing version
 *  2. Replace ey() NIN component with updated version (gender field, correct mapping)
 *  3. Add PDF Result Modal after the success modal (R.open block)
 *
 * Run once. Safe to re-run — checks for existing patches.
 */
declare(strict_types=1);

$file = 'C:/xampp/htdocs/gemverify/user/index.html';
$content = file_get_contents($file);

echo "=== GemVerify TH-09 Patch ===\n";
echo "File: {$file}\n";
echo "Size: " . number_format(strlen($content)) . " bytes\n\n";

// ── Guard: already patched? ───────────────────────────────────────────────
if (str_contains($content, 'GV_API_SERVICES')) {
    echo "[SKIP] File already patched (GV_API_SERVICES marker found).\n";
    exit(0);
}

$patchCount = 0;
$errors = [];

// ─────────────────────────────────────────────────────────────────────────
// PATCH 1: Replace doSubmitPayment
// ─────────────────────────────────────────────────────────────────────────
$old_doSubmit = <<<'OLDCODE'
let doSubmitPayment = async () => {
    let token = localStorage.getItem("gv_token");
    if (!token) {
      setPayError("Session expired. Please sign in again.");
      return;
    }
    setPayError("");
    setPaySubmitting(true);

    let meta = C.meta || {};
    let serviceSlug = meta.slug || "ipe-clearance";
    let variantKey = meta.variantKey || null;
    let pin = meta.pin || null;
    let isBulk = Boolean(meta.count || meta.items);
    let endpoint = isBulk ? "../api/manual/submit/bulk" : "../api/manual/submit";

    let idempotencyKey = "IDEM-" + Date.now() + "-" + Math.random().toString(36).substring(2, 9);
    // Scan meta for any File objects and extract them
    let files = {};
    let metaCleaned = {};
    Object.entries(meta).forEach(([k, v]) => {
      if (v instanceof File) {
        files[k] = v;
      } else {
        metaCleaned[k] = v;
      }
    });

    let hasFiles = Object.keys(files).length > 0;
    let requestOptions = {
      method: "POST",
      headers: {
        "Authorization": "Bearer " + token
      }
    };

    if (hasFiles) {
      // Send as multipart/form-data
      let formData = new FormData();
      formData.append("service_slug", serviceSlug);
      if (variantKey) formData.append("variant_key", variantKey);
      formData.append("idempotency_key", idempotencyKey);
      if (pin) formData.append("pin", pin);
      formData.append("form_data", JSON.stringify(metaCleaned));
      
      // Append files
      Object.entries(files).forEach(([fieldName, fileObj]) => {
        formData.append(fieldName, fileObj);
      });
      
      requestOptions.body = formData;
    } else {
      // Send as standard application/json
      let body = isBulk ? {
        service_slug: serviceSlug,
        variant_key: variantKey,
        idempotency_key: idempotencyKey,
        pin: pin,
        items: meta.items || []
      } : {
        service_slug: serviceSlug,
        variant_key: variantKey,
        idempotency_key: idempotencyKey,
        pin: pin,
        form_data: metaCleaned
      };
      
      requestOptions.headers["Content-Type"] = "application/json";
      requestOptions.body = JSON.stringify(body);
    }

    try {
      let res = await fetch(endpoint, requestOptions);
      let json = await res.json();
      setPaySubmitting(false);

      if (!res.ok || !json.success) {
        let msg = json.message || "Payment submission failed.";
        setPayError(msg);
        alert("Payment Error: " + msg);
        return;
      }

      if (json.data && json.data.wallet_balance_after !== undefined) {
        setWalletBalance(Number(json.data.wallet_balance_after));
      } else {
        fetchWallet();
        fetchUserRequests();
      }

      E({ open: false });
      f({
        open: true,
        ref: json.data.reference || "",
        service: json.data.service_name || C.service,
        price: json.data.price_paid || C.amount,
        tracking: json.data.reference,
        est: json.data.estimated_time || "1-3 days"
      });
    } catch(err) {
      setPaySubmitting(false);
      setPayError("Connection error: " + err.message);
      alert("Error: " + err.message);
    }
  };
  let Z = doSubmitPayment;
OLDCODE;

$new_doSubmit = <<<'NEWCODE'
/* GV_API_SERVICES — TechHub routing patch */
  // Services handled by TechHub API (sync PDF or async ticket)
  let GV_API_SERVICES = ["nin-verification","bvn-verification","personalization","bvn-retrieval","ipe-clearance-single"];
  // Map frontend method names → backend input_method values
  let GV_METHOD_MAP = {nin:"by_nin", phone:"by_phone", demographic:"by_demo"};

  // PDF result modal state
  let [pdfModal, setPdfModal] = I.useState({open:false, pdf:null, gvRef:"", service:"", price:0});

  let doSubmitPayment = async () => {
    let token = localStorage.getItem("gv_token");
    if (!token) {
      setPayError("Session expired. Please sign in again.");
      return;
    }
    setPayError("");
    setPaySubmitting(true);

    let meta = C.meta || {};
    let serviceSlug = meta.slug || "ipe-clearance";
    let variantKey = meta.variantKey || null;
    let pin = meta.pin || null;
    let idempotencyKey = "IDEM-" + Date.now() + "-" + Math.random().toString(36).substring(2, 9);

    // ── Route: TechHub API service ──────────────────────────────────────
    if (GV_API_SERVICES.includes(serviceSlug)) {
      // Map frontend method → backend input_method
      let inputMethod = meta.method ? (GV_METHOD_MAP[meta.method] || meta.method) : null;

      // Build flat JSON payload — spread all non-meta fields
      let payload = {
        service_slug: serviceSlug,
        variant_key: variantKey,
        idempotency_key: idempotencyKey,
        input_method: inputMethod,
      };
      // Copy data fields from meta (exclude internal routing keys)
      let skipKeys = new Set(["slug","variantKey","method","pin","count","items"]);
      Object.entries(meta).forEach(([k, v]) => {
        if (!skipKeys.has(k) && !(v instanceof File)) payload[k] = v;
      });
      if (pin) payload.pin = pin;

      try {
        let res = await fetch("../api/api-services/submit", {
          method: "POST",
          headers: {
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json"
          },
          body: JSON.stringify(payload)
        });
        let json = await res.json();
        setPaySubmitting(false);

        if (!res.ok || !json.success) {
          let msg = json.message || "Request failed.";
          let detail = json.data?.error_code ? " [" + json.data.error_code + "]" : "";
          setPayError(msg + detail);
          return;
        }

        let data = json.data || {};

        // Update wallet balance
        if (data.wallet_balance_after !== undefined) {
          setWalletBalance(Number(data.wallet_balance_after));
        } else {
          fetchWallet();
        }

        // Close checkout modal
        E({ open: false });

        // ── Sync response: PDF available immediately ──
        if (data.pdf_base64) {
          setPdfModal({
            open: true,
            pdf: data.pdf_base64,
            gvRef: data.gv_reference || "",
            service: C.service || serviceSlug,
            price: data.price_paid || C.amount,
          });
        } else {
          // ── Async response: ticket issued, show standard success modal ──
          f({
            open: true,
            ref: data.gv_reference || "",
            service: C.service || serviceSlug,
            price: data.price_paid || C.amount,
            tracking: data.ticket_id || data.gv_reference || "",
            est: "Processing — check requests for status"
          });
        }

      } catch(err) {
        setPaySubmitting(false);
        setPayError("Connection error: " + err.message);
      }
      return; // ← Exit: TechHub branch handled
    }

    // ── Route: Manual / bulk service (existing flow) ────────────────────
    let isBulk = Boolean(meta.count || meta.items);
    let endpoint = isBulk ? "../api/manual/submit/bulk" : "../api/manual/submit";

    let files = {};
    let metaCleaned = {};
    Object.entries(meta).forEach(([k, v]) => {
      if (v instanceof File) { files[k] = v; }
      else { metaCleaned[k] = v; }
    });

    let hasFiles = Object.keys(files).length > 0;
    let requestOptions = { method: "POST", headers: { "Authorization": "Bearer " + token } };

    if (hasFiles) {
      let formData = new FormData();
      formData.append("service_slug", serviceSlug);
      if (variantKey) formData.append("variant_key", variantKey);
      formData.append("idempotency_key", idempotencyKey);
      if (pin) formData.append("pin", pin);
      formData.append("form_data", JSON.stringify(metaCleaned));
      Object.entries(files).forEach(([fk, fv]) => formData.append(fk, fv));
      requestOptions.body = formData;
    } else {
      let body = isBulk ? {
        service_slug: serviceSlug, variant_key: variantKey,
        idempotency_key: idempotencyKey, pin, items: meta.items || []
      } : {
        service_slug: serviceSlug, variant_key: variantKey,
        idempotency_key: idempotencyKey, pin, form_data: metaCleaned
      };
      requestOptions.headers["Content-Type"] = "application/json";
      requestOptions.body = JSON.stringify(body);
    }

    try {
      let res = await fetch(endpoint, requestOptions);
      let json = await res.json();
      setPaySubmitting(false);

      if (!res.ok || !json.success) {
        let msg = json.message || "Payment submission failed.";
        setPayError(msg);
        alert("Payment Error: " + msg);
        return;
      }

      if (json.data && json.data.wallet_balance_after !== undefined) {
        setWalletBalance(Number(json.data.wallet_balance_after));
      } else {
        fetchWallet();
        fetchUserRequests();
      }

      E({ open: false });
      f({
        open: true,
        ref: json.data.reference || "",
        service: json.data.service_name || C.service,
        price: json.data.price_paid || C.amount,
        tracking: json.data.reference,
        est: json.data.estimated_time || "1-3 days"
      });
    } catch(err) {
      setPaySubmitting(false);
      setPayError("Connection error: " + err.message);
      alert("Error: " + err.message);
    }
  };
  let Z = doSubmitPayment;
NEWCODE;

if (str_contains($content, $old_doSubmit)) {
    $content = str_replace($old_doSubmit, $new_doSubmit, $content);
    echo "[PATCH 1] doSubmitPayment replaced ✓\n";
    $patchCount++;
} else {
    $errors[] = "PATCH 1 FAILED: doSubmitPayment old marker not found exactly";
}

// ─────────────────────────────────────────────────────────────────────────
// PATCH 2: Replace ey() NIN component
//   Changes:
//   a) Fix label "Slip Type — Compact Chips (FIX)" → "Slip Type"
//   b) Add gender field to demographic section
//   c) Add gender state variable
//   d) Pass gender + correct field names in onProceed
// ─────────────────────────────────────────────────────────────────────────
$old_ey = 'function ey({onProceed:e}){let[A,n]=I.useState("nin"),updatedUc=uc.map(x=>({...x,price:window.getServicePrice("nin-verification",x.id,x.price)})),[l,o]=I.useState(updatedUc[0]),[t,r]=I.useState(""),[Q,c]=I.useState(""),[q,U]=I.useState(""),[C,E]=I.useState(""),[R,f]=I.useState(""),[a,w]=I.useState("");let currentPrice=updatedUc.find(x=>x.id===l.id)?.price||l.price,isConfigured=currentPrice>0;return u("div",{children:[i(v,{breadcrumb:["NIN Services","Verification"],title:"NIN Verification",est:"Instant",price:isConfigured?currentPrice:0}),u("div",{className:"grid lg:grid-cols-[1fr_340px] gap-6",children:[i("div",{className:"space-y-5",children:u(D,{className:"p-5",children:[i(h,{children:"Verification Method"}),i("div",{className:"mt-2 flex flex-wrap gap-2",children:[{id:"nin",label:"NIN Number"},{id:"phone",label:"Phone Number"},{id:"demographic",label:"Demographic Search"}].map((d)=>i("button",{onClick:()=>n(d.id),className:`h-9 px-4 rounded-xl border text-[13px] font-medium transition ${A===d.id?"bg-[#0050FF] text-white border-[#0050FF]":"bg-white dark:bg-white/5 border-slate-200 dark:border-white/10"}`,children:d.label},d.id))}),u("div",{className:"mt-5",children:[i(h,{children:"Slip Type \u2014 Compact Chips (FIX)"}),i("div",{className:"mt-2 flex flex-wrap gap-2",children:updatedUc.map((d)=>u("button",{onClick:()=>o(d),className:`h-10 px-3.5 rounded-xl border text-[12px] font-medium flex items-center gap-2 transition ${l.id===d.id?"bg-[#0A1931] text-white border-[#0A1931] dark:bg-white dark:text-black":"bg-white dark:bg-white/5 border-slate-200 dark:border-white/10 hover:border-[#0050FF]/30"}`,children:[i("span",{className:`w-3.5 h-3.5 rounded-full border grid place-items-center ${l.id===d.id?"border-white":"border-slate-300"}`,children:i("span",{className:`w-1.5 h-1.5 rounded-full ${l.id===d.id?"bg-white":"bg-transparent"}`})}),i("span",{children:d.label}),i("span",{className:`ml-1 text-[10px] px-1.5 py-0.5 rounded-md font-bold ${l.id===d.id?"bg-white/20 text-white":"bg-slate-900 text-white dark:bg-white dark:text-black"}`,children:k(d.price)})]},d.id))})]}),u("div",{className:"mt-6 grid md:grid-cols-2 gap-4",children:[A==="nin"&&u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"NIN Number (11 digits)"}),i(y,{value:t,onChange:r,placeholder:"Enter 11-digit NIN"})]}),A==="phone"&&u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"Phone Number"}),i(y,{value:Q,onChange:c,placeholder:"080..."})]}),A==="demographic"&&u(Be,{children:[u("div",{children:[i(h,{req:!0,children:"First Name"}),i(y,{value:q,onChange:U,placeholder:"First name"})]}),u("div",{children:[i(h,{req:!0,children:"Last Name"}),i(y,{value:C,onChange:E,placeholder:"Last name"})]}),u("div",{children:[i(h,{req:!0,children:"Date of Birth"}),i(y,{type:"date",value:R,onChange:f})]})]}),u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"Transaction PIN"}),i(y,{type:"password",value:a,onChange:w,placeholder:"Enter 4-digit PIN"})]})]}),u("button",{disabled:!isConfigured,onClick:()=>e("NIN Verification",currentPrice,{slug:"nin-verification",variantKey:l.id,pin:a,method:A,nin:t,phone:Q,first:q,last:C,dob:R}),className:"mt-6 w-full h-12 rounded-xl bg-[#0050FF] text-white font-semibold"+(!isConfigured?" opacity-50 cursor-not-allowed pointer-events-none":""),children:isConfigured?["Verify NIN — ",k(currentPrice)]:"Price not configured"})]})}),u("div",{className:"space-y-4",children:[u(D,{className:"p-4",children:[i("div",{className:"text-sm font-semibold",children:"Service Details"}),u("div",{className:"mt-3 text-xs space-y-2",children:[i(He,{label:"Method",value:A}),i(He,{label:"Slip",value:l.label}),i(He,{label:"Fee",value:k(l.price),bold:!0})]})]}),i(D,{className:"p-4",children:i("div",{className:"text-xs opacity-60",children:"Supports NIN, phone & demographic search. Slip delivered instantly as PDF."})})]})]})]})}}';

$new_ey = 'function ey({onProceed:e}){let[A,n]=I.useState("nin"),updatedUc=uc.map(x=>({...x,price:window.getServicePrice("nin-verification",x.id,x.price)})),[l,o]=I.useState(updatedUc[0]),[t,r]=I.useState(""),[Q,c]=I.useState(""),[q,U]=I.useState(""),[C,E]=I.useState(""),[R,f]=I.useState(""),[Gdr,setGdr]=I.useState("M"),[a,w]=I.useState("");let currentPrice=updatedUc.find(x=>x.id===l.id)?.price||l.price,isConfigured=currentPrice>0;return u("div",{children:[i(v,{breadcrumb:["NIN Services","Verification"],title:"NIN Verification",est:"Instant",price:isConfigured?currentPrice:0}),u("div",{className:"grid lg:grid-cols-[1fr_340px] gap-6",children:[i("div",{className:"space-y-5",children:u(D,{className:"p-5",children:[i(h,{children:"Verification Method"}),i("div",{className:"mt-2 flex flex-wrap gap-2",children:[{id:"nin",label:"NIN Number"},{id:"phone",label:"Phone Number"},{id:"demographic",label:"Demographic Search"}].map((d)=>i("button",{onClick:()=>n(d.id),className:`h-9 px-4 rounded-xl border text-[13px] font-medium transition ${A===d.id?"bg-[#0050FF] text-white border-[#0050FF]":"bg-white dark:bg-white/5 border-slate-200 dark:border-white/10"}`,children:d.label},d.id))}),u("div",{className:"mt-5",children:[i(h,{children:"Slip Type"}),i("div",{className:"mt-2 flex flex-wrap gap-2",children:updatedUc.map((d)=>u("button",{onClick:()=>o(d),className:`h-10 px-3.5 rounded-xl border text-[12px] font-medium flex items-center gap-2 transition ${l.id===d.id?"bg-[#0A1931] text-white border-[#0A1931] dark:bg-white dark:text-black":"bg-white dark:bg-white/5 border-slate-200 dark:border-white/10 hover:border-[#0050FF]/30"}`,children:[i("span",{className:`w-3.5 h-3.5 rounded-full border grid place-items-center ${l.id===d.id?"border-white":"border-slate-300"}`,children:i("span",{className:`w-1.5 h-1.5 rounded-full ${l.id===d.id?"bg-white":"bg-transparent"}`})}),i("span",{children:d.label}),i("span",{className:`ml-1 text-[10px] px-1.5 py-0.5 rounded-md font-bold ${l.id===d.id?"bg-white/20 text-white":"bg-slate-900 text-white dark:bg-white dark:text-black"}`,children:k(d.price)})]},d.id))})]}),u("div",{className:"mt-6 grid md:grid-cols-2 gap-4",children:[A==="nin"&&u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"NIN Number (11 digits)"}),i(y,{value:t,onChange:r,placeholder:"Enter 11-digit NIN"})]}),A==="phone"&&u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"Phone Number"}),i(y,{value:Q,onChange:c,placeholder:"080..."})]}),A==="demographic"&&u(Be,{children:[u("div",{children:[i(h,{req:!0,children:"First Name"}),i(y,{value:q,onChange:U,placeholder:"First name"})]}),u("div",{children:[i(h,{req:!0,children:"Last Name"}),i(y,{value:C,onChange:E,placeholder:"Last name"})]}),u("div",{children:[i(h,{req:!0,children:"Date of Birth"}),i(y,{type:"date",value:R,onChange:f})]}),u("div",{children:[i(h,{req:!0,children:"Gender"}),u("select",{value:Gdr,onChange:(ev)=>setGdr(ev.target.value),className:"w-full h-11 px-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white dark:bg-white/5 text-[13px] outline-none focus:ring-2 focus:ring-[#0050FF]/20 focus:border-[#0050FF]",children:[i("option",{value:"M",children:"Male"}),i("option",{value:"F",children:"Female"})]})]})]}),u("div",{className:"md:col-span-2",children:[i(h,{req:!0,children:"Transaction PIN"}),i(y,{type:"password",value:a,onChange:w,placeholder:"Enter 4-digit PIN"})]})]}),u("button",{disabled:!isConfigured,onClick:()=>e("NIN Verification",currentPrice,{slug:"nin-verification",variantKey:l.id,pin:a,method:A,nin:t,phone:Q,firstname:q,lastname:C,dob:R,gender:Gdr}),className:"mt-6 w-full h-12 rounded-xl bg-[#0050FF] text-white font-semibold"+(!isConfigured?" opacity-50 cursor-not-allowed pointer-events-none":""),children:isConfigured?["Verify NIN — ",k(currentPrice)]:"Price not configured"})]})}),u("div",{className:"space-y-4",children:[u(D,{className:"p-4",children:[i("div",{className:"text-sm font-semibold",children:"Service Details"}),u("div",{className:"mt-3 text-xs space-y-2",children:[i(He,{label:"Method",value:A==="nin"?"By NIN":A==="phone"?"By Phone":"Demographic"}),i(He,{label:"Slip",value:l.label}),i(He,{label:"Fee",value:k(currentPrice),bold:!0})]})]}),i(D,{className:"p-4",children:i("div",{className:"text-xs opacity-60",children:"Supports NIN, phone & demographic search. Slip delivered instantly as PDF."})})]})]})]})}}';

if (str_contains($content, $old_ey)) {
    $content = str_replace($old_ey, $new_ey, $content);
    echo "[PATCH 2] ey() NIN component replaced ✓\n";
    $patchCount++;
} else {
    $errors[] = "PATCH 2 FAILED: ey() old marker not found exactly";
}

// ─────────────────────────────────────────────────────────────────────────
// PATCH 3: Add PDF Result Modal + pdfModal state render
// Insert BEFORE the showSetPinModal block
// ─────────────────────────────────────────────────────────────────────────
$pdf_modal_html = <<<'PDFMODAL'
pdfModal.open&&u("div",{className:"fixed inset-0 z-[105] bg-black/70 backdrop-blur-sm flex items-end md:items-center justify-center p-0 md:p-4",children:u("div",{className:"w-full md:max-w-[640px] max-h-[95vh] md:rounded-[24px] rounded-t-[24px] shadow-2xl border border-white/10 flex flex-col overflow-hidden",style:{background:"linear-gradient(135deg,#0a1931,#102044)"},children:[u("div",{className:"flex items-center justify-between p-5 border-b border-white/10 shrink-0",children:[u("div",{className:"flex items-center gap-3",children:[i("div",{className:"w-10 h-10 rounded-xl bg-gradient-to-br from-[#0050FF] to-[#00C8E6] grid place-items-center text-lg",children:"📄"}),u("div",{children:[i("div",{className:"text-white font-bold",children:"Verification Complete"}),i("div",{className:"text-[11px] text-white/50 mt-0.5",children:pdfModal.gvRef})]})]}),i("button",{onClick:()=>setPdfModal({open:false}),className:"w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 text-white grid place-items-center text-sm transition",children:"✕"})]}),u("div",{className:"flex-1 overflow-hidden flex flex-col p-5 gap-4",children:[u("div",{className:"rounded-2xl bg-white/5 border border-white/10 p-4 flex items-center justify-between shrink-0",children:[u("div",{className:"text-sm",children:[i("div",{className:"text-white/60 text-xs",children:"Service"}),i("div",{className:"text-white font-semibold mt-0.5",children:pdfModal.service})]}),u("div",{className:"text-right text-sm",children:[i("div",{className:"text-white/60 text-xs",children:"Amount Paid"}),i("div",{className:"text-[#00C8E6] font-bold mt-0.5",children:k(pdfModal.price)})]}),i("div",{className:"px-3 py-1.5 rounded-full bg-emerald-500/20 text-emerald-400 text-xs font-semibold border border-emerald-500/30",children:"✓ PDF Ready"})]}),i("div",{className:"text-xs text-white/50 text-center",children:"Preview below or download for your records"}),u("div",{className:"rounded-2xl overflow-hidden border border-white/10 flex-1 min-h-[280px]",children:[pdfModal.pdf&&i("iframe",{src:"data:application/pdf;base64,"+pdfModal.pdf,title:"Verification Slip",className:"w-full h-full min-h-[280px]",style:{border:"none"}}),!pdfModal.pdf&&i("div",{className:"w-full h-full min-h-[280px] flex items-center justify-center text-white/30 text-sm",children:"PDF not available"})]}),u("div",{className:"flex gap-3 shrink-0",children:[i("button",{onClick:()=>{let a=document.createElement("a");a.href="data:application/pdf;base64,"+pdfModal.pdf;a.download=(pdfModal.gvRef||"verification")+".pdf";a.click();},className:"flex-1 h-11 rounded-xl bg-gradient-to-r from-[#0050FF] to-[#00C8E6] text-white text-sm font-semibold flex items-center justify-center gap-2 hover:opacity-90 transition",children:["⬇ Download PDF"]}),i("button",{onClick:()=>setPdfModal({open:false}),className:"h-11 px-5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-medium transition",children:"Close"})]})]})]})}),
PDFMODAL;

$pin_modal_marker = 'showSetPinModal&&i("div",{className:"fixed inset-0 z-[110]';
if (str_contains($content, $pin_modal_marker)) {
    $content = str_replace($pin_modal_marker, $pdf_modal_html . $pin_modal_marker, $content);
    echo "[PATCH 3] PDF Result Modal inserted ✓\n";
    $patchCount++;
} else {
    $errors[] = "PATCH 3 FAILED: showSetPinModal marker not found";
}

// ─────────────────────────────────────────────────────────────────────────
// Results
// ─────────────────────────────────────────────────────────────────────────
if (!empty($errors)) {
    echo "\n=== ERRORS ===\n";
    foreach ($errors as $e) echo "  {$e}\n";
}

if ($patchCount > 0) {
    // Backup original
    $backup = $file . '.bak_th09_' . date('YmdHis');
    copy($file, $backup);
    echo "\n[BACKUP] Created: {$backup}\n";

    // Write patched file
    file_put_contents($file, $content);
    echo "[WRITE] Patched file saved ({$patchCount}/3 patches applied)\n";
    echo "\nNew size: " . number_format(strlen($content)) . " bytes\n";
} else {
    echo "\n[ABORT] No patches applied — original file unchanged.\n";
}

echo "\n=== Done: {$patchCount}/3 patches applied, " . count($errors) . " errors ===\n";
exit(count($errors) > 0 ? 1 : 0);
