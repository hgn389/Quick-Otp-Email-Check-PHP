'use strict';
const $ = id => document.getElementById(id);
let ready = false, busy = false, inspected = false;
function showStatus(id,message,type=''){const el=$(id);el.textContent=message;el.className='backup-status'+(type?' '+type:'');}
function updateControls(){
  for(const id of ['exportBackupBtn','inspectBackupBtn'])$(id).disabled=!ready||busy;
  $('restoreBackupBtn').disabled=!ready||busy||!inspected||!$('restoreConfirm').checked;
  for(const id of ['adminPassword','backupPassword','backupPasswordConfirm','restoreFile','restorePassword','restoreConfirm'])$(id).disabled=busy;
  document.querySelectorAll('#recoveryList button').forEach(button=>button.disabled=!ready||busy);
  for(const id of ['backupForm','restoreForm'])$(id).setAttribute('aria-busy',String(busy));
}
function resetPreview(){inspected=false;$('restoreConfirm').checked=false;$('restorePreview').classList.add('hidden');updateControls();}
async function request(path,body,json=false){
 const response=await fetch(path,{method:'POST',headers:{'X-CSRF-Token':workspace.csrfToken,...(json?{'Content-Type':'application/json'}:{})},body:json?JSON.stringify(body):body,cache:'no-store'});
 if(response.status===401){const data=await response.json();if(data.error==='current password is incorrect')throw new Error('Mật khẩu Admin hiện tại không đúng.');window.location.replace('/login.html');throw new Error('Phiên đăng nhập đã hết hạn.');}
 if(!response.ok){const data=await response.json();throw new Error(data.error||'Không xử lý được yêu cầu.');}
 return response;
}
async function download(response,fallback){
 const blob=await response.blob();const url=URL.createObjectURL(blob);
 const filename=/filename="([a-zA-Z0-9.-]+)"/.exec(response.headers.get('Content-Disposition')||'')?.[1]||fallback;
 const link=document.createElement('a');link.href=url;link.download=filename;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
function upload(confirm=false){const data=new FormData();data.append('current_password',$('adminPassword').value);data.append('backup_password',$('restorePassword').value);data.append('backup',$('restoreFile').files[0]);if(confirm)data.append('confirmation','RESTORE');return data;}
function showPreview(summary){
 const details=$('restoreDetails');details.replaceChildren();
 const rows=[['Thời gian tạo',new Date(summary.created_at).toLocaleString('vi-VN')],['Phiên bản',summary.version],['Tài khoản',summary.counts.users],['Tên miền',summary.counts.domains],['Địa chỉ email',summary.counts.generated_emails],['Thư đã lưu',summary.counts.messages],['IP bị chặn',summary.blocked_ips]];
 for(const [label,value]of rows){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=String(value);details.append(dt,dd);}
 inspected=true;$('restorePreview').classList.remove('hidden');
}
$('backupForm').addEventListener('submit',async event=>{
 event.preventDefault();if(busy||!ready||!$('adminPassword').reportValidity())return;
 if($('backupPassword').value!==$('backupPasswordConfirm').value){showStatus('exportStatus','Hai mật khẩu backup chưa khớp.','error');$('backupPasswordConfirm').focus();return;}
 busy=true;updateControls();showStatus('exportStatus','Đang tạo file backup…');
 try{await download(await request('/api/v1/backups/export',{current_password:$('adminPassword').value,backup_password:$('backupPassword').value},true),'quickotp-backup.qotp');showStatus('exportStatus','Đã tải backup. Hãy giữ file và mật khẩu ở nơi riêng tư.','success');$('backupPassword').value='';$('backupPasswordConfirm').value='';}
 catch(error){showStatus('exportStatus',error.message,'error');}finally{busy=false;updateControls();}
});
$('restoreForm').addEventListener('submit',async event=>{
 event.preventDefault();if(busy||!ready||!$('adminPassword').reportValidity())return;
 resetPreview();const file=$('restoreFile').files[0];if(!file||file.size>32*1024*1024){showStatus('restoreStatus','Chọn file .qotp không quá 32 MiB.','error');return;}
 const data=upload();busy=true;updateControls();showStatus('restoreStatus','Đang giải mã và kiểm tra backup…');
 try{const response=await request('/api/v1/backups/inspect',data);showPreview(await response.json());showStatus('restoreStatus','Backup hợp lệ. Xem nội dung và xác nhận trước khi khôi phục.','success');}
 catch(error){showStatus('restoreStatus',error.message,'error');}finally{busy=false;updateControls();}
});
$('restoreBackupBtn').addEventListener('click',async()=>{
 if(busy||!ready||!inspected||!$('restoreConfirm').checked||!$('adminPassword').reportValidity())return;
 const data=upload(true);busy=true;updateControls();showStatus('restoreStatus','Đang tạo bản dự phòng và khôi phục dữ liệu…');
 try{const response=await request('/api/v1/backups/restore',data);const result=await response.json();showStatus('restoreStatus','Đã khôi phục. Bản dự phòng: '+result.recovery_file+'. Đăng nhập lại bằng tài khoản trong backup.','success');$('adminPassword').value='';$('restorePassword').value='';setTimeout(()=>window.location.replace('/login.html?restored=1'),1800);}
 catch(error){showStatus('restoreStatus',error.message+' Nếu kết nối bị gián đoạn, hãy kiểm tra phiên đăng nhập trước khi thử khôi phục lại.','error');busy=false;updateControls();}
});
for(const id of ['restoreFile','restorePassword','adminPassword'])$(id).addEventListener(id==='restoreFile'?'change':'input',()=>{resetPreview();showStatus('restoreStatus','');});
$('restoreConfirm').addEventListener('change',updateControls);
async function loadRecovery(){
 try{
  const data=await workspace.api('/api/v1/backups');const list=$('recoveryList');list.replaceChildren();
  if(!data.recovery_files.length){const li=document.createElement('li');li.textContent='Chưa có bản dự phòng. Bản đầu tiên sẽ được tạo trước khi restore.';list.append(li);}
  for(const file of data.recovery_files){
   const li=document.createElement('li'),text=document.createElement('span'),small=document.createElement('small'),button=document.createElement('button');text.textContent=file.name;small.textContent=new Date(file.created_at).toLocaleString('vi-VN')+' · '+Math.ceil(file.size/1024)+' KiB';text.append(small);button.type='button';button.className='secondary-button';button.textContent='Tải xuống';button.setAttribute('aria-label','Tải '+file.name);
   button.addEventListener('click',async()=>{if(busy||!$('adminPassword').reportValidity())return;busy=true;updateControls();showStatus('recoveryStatus','Đang tải bản dự phòng…');try{await download(await request('/api/v1/backups/recovery',{name:file.name,current_password:$('adminPassword').value},true),file.name);showStatus('recoveryStatus','Đã tải bản dự phòng.','success');}catch(error){showStatus('recoveryStatus',error.message,'error');}finally{busy=false;updateControls();}});
   li.append(text,button);list.append(li);
  }
 }catch(error){showStatus('recoveryStatus',error.message,'error');$('recoveryList').replaceChildren();}
}
workspace.ready.then(async value=>{ready=value;if(ready)await loadRecovery();updateControls();});
