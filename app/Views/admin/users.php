<div class="card mb"><div class="bd" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <strong>Administration</strong>
  <button class="btn ghost tab" data-tab="users">Users</button>
  <button class="btn ghost tab" data-tab="roles">Roles &amp; Permissions</button>
  <button class="btn" id="addBtn" style="margin-left:auto">+ New User</button>
</div></div>

<section id="users">
  <div class="card"><div class="bd" style="overflow:auto"><table>
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Scope</th><th>PIN</th><th>Status</th><th></th></tr></thead>
    <tbody id="uBody"><tr><td class="muted">Loading…</td></tr></tbody></table>
  </div></div>
</section>

<section id="roles" class="hidden">
  <div class="card"><div class="hd">Role → Permission matrix (read-only)</div>
    <div class="bd" style="overflow:auto"><div id="rolesBox" class="muted">Loading…</div></div>
  </div>
  <p class="muted mt" style="font-size:13px">Editing role permissions is intentionally not exposed here (high blast radius); manage via seed/migration.</p>
</section>

<div id="modal" class="hidden" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;display:flex;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="width:100%;max-width:520px;max-height:92vh;overflow:auto">
    <div class="hd"><span id="mTitle">New User</span><button class="btn-icon" id="mClose">✕</button></div>
    <div class="bd" id="mBody"></div>
  </div>
</div>

<script>
const OUTLETS = <?= json_encode(array_map(fn($o)=>['id'=>(int)$o['id'],'name'=>$o['name']],$outlets)) ?>;
const KIOSKS  = <?= json_encode(array_map(fn($k)=>['id'=>(int)$k['id'],'name'=>$k['name'],'outlet_id'=>(int)$k['outlet_id']],$kiosks)) ?>;
let ROLES = [], EDIT = null;

function tab(n){ ['users','roles'].forEach(s=>document.getElementById(s).classList.toggle('hidden',s!==n));
  document.getElementById('addBtn').style.display = n==='users'?'':'none'; if(n==='roles') loadRoles(); }
document.querySelectorAll('.tab').forEach(b=>b.addEventListener('click',()=>tab(b.dataset.tab)));
document.getElementById('mClose').addEventListener('click',()=>document.getElementById('modal').classList.add('hidden'));

async function loadRolesData(){ if(ROLES.length) return ROLES;
  const {data}=await FAOS.get('/api/admin/roles'); ROLES=data.roles; window.__PERMS=data.permissions; return ROLES; }

async function loadUsers(){
  try{
    const {data}=await FAOS.get('/api/admin/users');
    document.getElementById('uBody').innerHTML = data.length? data.map(u=>`
      <tr style="${Number(u.is_active)?'':'opacity:.55'}">
        <td>${FAOS.esc(u.username)}</td><td>${FAOS.esc(u.full_name)}</td>
        <td><span class="badge b-info">${FAOS.esc(u.role_name)}</span></td>
        <td>${FAOS.esc(u.outlet_name||'HQ-wide')}${u.kiosk_id?' · kiosk #'+u.kiosk_id:''}</td>
        <td>${Number(u.has_pin)?'✓':'—'}</td>
        <td>${Number(u.is_active)?'<span class="badge b-ok">active</span>':'<span class="badge b-dng">inactive</span>'}
            ${u.locked_until?' <span class="badge b-warn">locked</span>':''}</td>
        <td class="right">
          <button class="btn ghost" data-edit="${u.id}" style="padding:4px 8px">Edit</button>
          <button class="btn ghost" data-pw="${u.id}" style="padding:4px 8px">Pwd</button>
          <button class="btn ghost" data-pin="${u.id}" style="padding:4px 8px">PIN</button>
          ${u.locked_until?`<button class="btn ghost" data-unlock="${u.id}" style="padding:4px 8px">Unlock</button>`:''}
        </td></tr>`).join('') : '<tr><td class="muted">No users</td></tr>';
  }catch(e){ FAOS.toast(e.message,'err'); }
}

async function loadRoles(){
  await loadRolesData();
  document.getElementById('rolesBox').innerHTML = '<table><thead><tr><th>Role</th><th>Permissions</th></tr></thead><tbody>'+
    ROLES.map(r=>`<tr><td><b>${FAOS.esc(r.name)}</b><br><small class="muted">${FAOS.esc(r.code)}</small></td>
      <td>${r.permissions.length? r.permissions.map(p=>`<span class="badge b-gray" style="margin:2px">${FAOS.esc(p.code)}</span>`).join(' '):'<span class="muted">none</span>'}</td></tr>`).join('')+
    '</tbody></table>';
}

function scopeSelects(outletId,kioskId){
  const oOpt = '<option value="">— HQ-wide —</option>'+OUTLETS.map(o=>`<option value="${o.id}" ${o.id==outletId?'selected':''}>${FAOS.esc(o.name)}</option>`).join('');
  const kOpt = '<option value="">— none —</option>'+KIOSKS.map(k=>`<option value="${k.id}" data-o="${k.outlet_id}" ${k.id==kioskId?'selected':''}>${FAOS.esc(k.name)}</option>`).join('');
  return {oOpt,kOpt};
}

async function openModal(user){
  await loadRolesData();
  EDIT = user ? user.id : null;
  document.getElementById('mTitle').textContent = user ? 'Edit '+user.username : 'New User';
  const sc = scopeSelects(user?user.outlet_id:'', user?user.kiosk_id:'');
  document.getElementById('mBody').innerHTML = `
    ${user?'':`<label>Username</label><input id="f_username">`}
    <label>Full name</label><input id="f_full" value="${FAOS.esc(user?user.full_name:'')}">
    <label>Email</label><input id="f_email" value="${FAOS.esc(user?user.email:'')}">
    <label>Phone</label><input id="f_phone" value="${FAOS.esc(user?user.phone:'')}">
    <label>Role</label><select id="f_role">${ROLES.map(r=>`<option value="${r.id}" ${user&&user.role_id==r.id?'selected':''}>${FAOS.esc(r.name)}</option>`).join('')}</select>
    <div class="row"><div><label>Outlet</label><select id="f_outlet">${sc.oOpt}</select></div>
      <div><label>Kiosk</label><select id="f_kiosk">${sc.kOpt}</select></div></div>
    ${user?`<label style="display:flex;gap:8px;align-items:center;color:var(--ink)"><input type="checkbox" id="f_active" ${Number(user.is_active)?'checked':''} style="width:auto"> Active</label>`
          :`<label>Password</label><input id="f_pw" type="password"><label>PIN (optional, 4-8 digits)</label><input id="f_pin">`}
    <button class="btn ok lg mt" id="mSave">Save</button>`;
  document.getElementById('modal').classList.remove('hidden');
  document.getElementById('mSave').addEventListener('click', save);
}

async function save(){
  const body = {
    full_name: document.getElementById('f_full').value,
    email: document.getElementById('f_email').value,
    phone: document.getElementById('f_phone').value,
    role_id: +document.getElementById('f_role').value,
    outlet_id: document.getElementById('f_outlet').value || null,
    kiosk_id: document.getElementById('f_kiosk').value || null,
  };
  try{
    if(EDIT){
      body.is_active = document.getElementById('f_active').checked ? 1 : 0;
      await FAOS.put('/api/admin/users/'+EDIT, body);
    }else{
      body.username = document.getElementById('f_username').value;
      body.password = document.getElementById('f_pw').value;
      const pin = document.getElementById('f_pin').value; if(pin) body.pin = pin;
      await FAOS.post('/api/admin/users', body);
    }
    FAOS.toast('Saved','ok'); document.getElementById('modal').classList.add('hidden'); loadUsers();
  }catch(e){ FAOS.toast(e.message + (e.payload&&e.payload.errors?': '+JSON.stringify(e.payload.errors):''),'err'); }
}

document.getElementById('addBtn').addEventListener('click',()=>openModal(null));
document.getElementById('uBody').addEventListener('click', async e=>{
  const id = e.target.dataset.edit||e.target.dataset.pw||e.target.dataset.pin||e.target.dataset.unlock;
  if(!id) return;
  try{
    if(e.target.dataset.edit){ const {data}=await FAOS.get('/api/admin/users/'+id); openModal(data); }
    else if(e.target.dataset.pw){ const p=prompt('New password (min 6 chars):'); if(p){ await FAOS.post('/api/admin/users/'+id+'/password',{password:p}); FAOS.toast('Password reset','ok'); } }
    else if(e.target.dataset.pin){ const p=prompt('New PIN (4-8 digits, blank to clear):'); if(p!==null){ await FAOS.post('/api/admin/users/'+id+'/pin',{pin:p}); FAOS.toast('PIN updated','ok'); loadUsers(); } }
    else if(e.target.dataset.unlock){ await FAOS.post('/api/admin/users/'+id+'/unlock',{}); FAOS.toast('Unlocked','ok'); loadUsers(); }
  }catch(x){ FAOS.toast(x.message,'err'); }
});
loadUsers();
</script>
