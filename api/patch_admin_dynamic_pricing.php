<?php
$path = 'C:/xampp/htdocs/gemverify/admin/index.html';
$c = file_get_contents($path);

// 1. Replace const services list with let services and fetch function
$pos_start = strpos($c, 'const services=[');
if ($pos_start !== false) {
    // Find matching ending bracket
    $idx = $pos_start + 15;
    while ($c[$idx] !== ']' && $idx < strlen($c)) {
        $idx++;
    }
    $idx++;
    if ($c[$idx] === ';') $idx++;
    $old_list = substr($c, $pos_start, $idx - $pos_start);
    
    $new_list = 'let services = [];

async function fetchAdminServices() {
  let token = localStorage.getItem("gv_admin_token");
  if (!token) return;
  try {
    const res = await fetch(\'../api/admin/services\', {
      headers: { \'Authorization\': \'Bearer \' + token }
    });
    const json = await res.json();
    if (json.success && json.data) {
      services = json.data.map(s => {
        let displayPrice = s.pricing && s.pricing.length > 0 ? Number(s.pricing[0].price) : 0;
        return {
          id: s.id,
          cat: s.category,
          name: s.name,
          slug: s.slug,
          price: displayPrice,
          visible: s.is_active === 1 || s.is_active === \'1\' || s.is_active === true,
          active: s.is_active === 1 || s.is_active === \'1\' || s.is_active === true,
          type: s.is_manual ? \'Manual\' : \'API-Based\',
          pricing: s.pricing || []
        };
      });
      renderServices();
    }
  } catch (e) {
    console.error("Failed to load services from backend", e);
  }
}';
    $c = str_replace($old_list, $new_list, $c);
    echo "1. Replaced hardcoded services list array in admin HTML\n";
} else {
    echo "1. FAILED to find const services list array\n";
}

// 2. Add fetchAdminServices to go() function
$old_go = 'if(page === "rejected") renderRequestsQueue("rejected", "rejected");';
$new_go = 'if(page === "rejected") renderRequestsQueue("rejected", "rejected");
 if(page === "services") fetchAdminServices();';
if (strpos($c, $old_go) !== false) {
    $c = str_replace($old_go, $new_go, $c);
    echo "2. Added fetchAdminServices hook in go() function\n";
} else {
    echo "2. FAILED to find go() hook location\n";
}

// 3. Replace renderServices() with fetchAdminServices() in initial page setup
$old_init = 'fetchDashboardData();
    renderServices();';
$new_init = 'fetchDashboardData();
    fetchAdminServices();';
if (strpos($c, $old_init) !== false) {
    $c = str_replace($old_init, $new_init, $c);
    echo "3. Replaced renderServices with fetchAdminServices in init block\n";
} else {
    echo "3. FAILED to find init block location\n";
}

// 4. Overwrite services functions block
$pos_render = strpos($c, 'function renderServices()');
if ($pos_render !== false) {
    // Find the end of saveService
    $pos_save = strpos($c, 'function saveService(', $pos_render);
    if ($pos_save !== false) {
        $brace_count = 1;
        $idx = $pos_save;
        while ($c[$idx] !== '{' && $idx < strlen($c)) {
            $idx++;
        }
        $idx++;
        while ($brace_count > 0 && $idx < strlen($c)) {
            if ($c[$idx] === '{') $brace_count++;
            if ($c[$idx] === '}') $brace_count--;
            $idx++;
        }
        $old_funcs = substr($c, $pos_render, $idx - $pos_render);
        
        $new_funcs = 'function renderServices(){
 const q=(document.getElementById(\'serviceSearch\')?.value||\'\').toLowerCase(),cat=document.getElementById(\'catFilter\')?.value||\'\';
 const arr=services.filter(s=>(!q||s.name.toLowerCase().includes(q))&&(!cat||s.cat===cat));
 document.getElementById(\'serviceGrid\').innerHTML=arr.map(s=>`
 <div class="service">
  <div class="service-top"><div class="ico">${s.cat===\'NIN\'?\'N\':s.cat===\'BVN\'?\'B\':s.cat===\'JAMB\'?\'J\':s.cat===\'CAC\'?\'C\':s.cat===\'TIN\'?\'T\':s.cat===\'GemPrint\'?\'G\':\'A\'}</div>
  <button class="toggle ${s.visible?\'on\':\'\'}" onclick="toggleVisible(\'${s.id}\')"><i></i></button></div>
  <h3>${s.name}</h3><p>${s.cat} · ${s.type} · ${s.visible?\'Visible to users\':\'Hidden from users\'}</p>
  <div class="service-row"><div><small style="color:var(--muted);font-size:9px">USER PRICE</small><div class="price">${money(s.price)}</div></div><button class="secondary" onclick="openService(\'${s.id}\')">Edit</button></div>
 </div>`).join(\'\');
}

async function toggleVisible(id){
  const s = services.find(x => x.id == id);
  if (!s) return;
  s.visible = !s.visible;
  s.active = s.visible;
  await saveServiceSettings(s);
}

async function saveServiceSettings(s) {
  let token = localStorage.getItem("gv_admin_token");
  try {
    const res = await fetch(`../api/admin/services/${s.id}`, {
      method: \'PATCH\',
      headers: {
        \'Authorization\': \'Bearer \' + token,
        \'Content-Type\': \'application/json\'
      },
      body: JSON.stringify({
        name: s.name,
        active: s.visible ? 1 : 0,
        is_manual: s.type === \'Manual\' ? 1 : 0
      })
    });
    const json = await res.json();
    if (json.success) {
      await fetchAdminServices();
    }
  } catch(e) {
    console.error(e);
  }
}

function openService(id){
 const s=services.find(x=>x.id==id);
 if(!s) return;
 
 let pricingHtml = \'\';
 if (s.pricing && s.pricing.length > 0) {
     pricingHtml = s.pricing.map(p => {
         let label = p.variant_key ? `Price for \'${p.variant_key}\' (₦)` : \'Price (₦)\';
         return `<div class="field"><label>${label}</label><input class="mVariantPrice" data-pid="${p.pricing_id}" type="number" value="${p.price}"></div>`;
     }).join(\'\');
 } else {
     pricingHtml = `<div class="field"><label>Price (₦)</label><input class="mVariantPrice" data-pid="" type="number" value="${s.price}"></div>`;
 }
 
 document.getElementById(\'modalTitle\').textContent=\'Edit Service\';
 document.getElementById(\'modalBody\').innerHTML=`
 <div class="field"><label>Service Name</label><input id="mName" value="${s.name}"></div>
 <div class="field"><label>Category</label><select id="mCat" disabled><option value="${s.cat}">${s.cat}</option></select></div>
 ${pricingHtml}
 <div class="field"><label>Service Type</label><select id="mType"><option value="Manual" ${s.type===\'Manual\'?\'selected\':\'\'}>Manual</option><option value="API-Based" ${s.type===\'API-Based\'?\'selected\':\'\'}>API-Based</option></select></div>
 <div class="field-row"><label><input type="checkbox" id="mVisible" ${s.visible?\'checked\':\'\'}> Visible to users</label></div>
 `;
 document.getElementById(\'modalFoot\').innerHTML=`
  <button class="secondary" onclick="closeModal()">Cancel</button>
  <button class="primary" onclick="saveService(\'${s.id}\')">Save Changes</button>
 `;
 document.getElementById(\'modal\').classList.add(\'show\');
}

async function saveService(id){
  let s=services.find(x=>x.id==id);
  if(!s) return;
  
  let newName = document.getElementById(\'mName\').value.trim()||s.name;
  let newType = document.getElementById(\'mType\').value;
  let newVisible = document.getElementById(\'mVisible\').checked;
  
  let token = localStorage.getItem("gv_admin_token");
  
  try {
    // 1. Update service settings (name, is_manual, is_active)
    await fetch(`../api/admin/services/${s.id}`, {
      method: \'PATCH\',
      headers: {
        \'Authorization\': \'Bearer \' + token,
        \'Content-Type\': \'application/json\'
      },
      body: JSON.stringify({
        name: newName,
        active: newVisible ? 1 : 0,
        is_manual: newType === \'Manual\' ? 1 : 0
      })
    });
    
    // 2. Update variant prices
    const priceInputs = document.querySelectorAll(\'.mVariantPrice\');
    for (let input of priceInputs) {
      let pid = input.getAttribute(\'data-pid\');
      let val = Number(input.value) || 0;
      if (val > 0 && pid) {
        await fetch(`../api/admin/services/${s.id}/pricing/${pid}`, {
          method: \'PATCH\',
          headers: {
            \'Authorization\': \'Bearer \' + token,
            \'Content-Type\': \'application/json\'
          },
          body: JSON.stringify({ price: val })
        });
      }
    }
    
    closeModal();
    await fetchAdminServices();
  } catch(e) {
    console.error("Failed to save service", e);
  }
}';
        
        $c = str_replace($old_funcs, $new_funcs, $c);
        echo "4. Overwrote service manager functions in admin HTML successfully\n";
    } else {
        echo "4. FAILED to find saveService block\n";
    }
} else {
    echo "4. FAILED to find renderServices block\n";
}

file_put_contents($path, $c);
