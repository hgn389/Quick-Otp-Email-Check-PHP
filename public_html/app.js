'use strict';
const $ = id => document.getElementById(id);
const state = { email:'', watchedEmail:'', watching:false, watchTimer:null, otp:'', history: null, historyRevision: 0 };
const styleLabels = {vietnamese_name_number:i18n.t('Tên Việt Nam'),usa_name_number:i18n.t('Tên USA'),canada_name_number:i18n.t('Tên Canada'),random_username:i18n.t('Username ngẫu nhiên'),random_letters_number:i18n.t('Chữ cái + số'),random_crypto:i18n.t('Chuỗi kiểu ví Crypto — 16 ký tự'),custom_prefix:i18n.t('Tiền tố riêng')};
function renderDefaults(){
  const config=workspace.settings;
  $('generatorDefaults').textContent=config.default_domain+' · '+styleLabels[config.generator_type]+(config.generator_type==='custom_prefix'?' · '+config.default_prefix:'');
  $('mailConnectionStatus').textContent=workspace.mail.configured?'Email: đã cấu hình IMAP':i18n.t('Email: chưa cấu hình');
}
function addRecent(email,time){
  const list=$('recentList');if(list.classList.contains('empty-list')){list.className='recent-items';list.replaceChildren();}
  const row=document.createElement('div');row.className='recent-item';
  const address=document.createElement('span');address.textContent=email;
  const timestamp=document.createElement('small');timestamp.textContent=new Date(time).toLocaleString(i18n.locale,{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
  const copy=document.createElement('button');copy.textContent=i18n.t('Copy');copy.addEventListener('click',()=>workspace.copy(email));
  row.append(address,timestamp,copy);list.append(row);
}
function renderHistoryPagination(disabled = false) {
  if (!state.history) return;
  const data = state.history;
  pagination.render($('historyPagination'), {page:data.page, pageSize:data.page_size, total:data.total, totalPages:data.total_pages, disabled,
    onChange: page => loadHistory(page).catch(error => workspace.toast(error.message))});
}
async function loadHistory(page = 1){
  const revision = ++state.historyRevision;
  const pageSize = Number($('historyPageSize').value);
  $('historyPageSize').disabled = true;
  renderHistoryPagination(true); $('recentList').setAttribute('aria-busy', 'true');
  $('historyStatus').textContent = i18n.t('Đang tải danh sách…');
  const start = new Date(); start.setHours(0,0,0,0);
  const end = new Date(start); end.setDate(end.getDate() + 1);
  const params = new URLSearchParams({page, page_size:pageSize, day_start:start.toISOString(), day_end:end.toISOString()});
  try {
    const data=await workspace.api('/api/v1/history/page?' + params);
    if (revision !== state.historyRevision) return;
    state.history = data;
    $('historyPageSize').value = String(data.page_size);
    $('recentList').className='empty-list';$('recentList').replaceChildren();
    if(!data.items.length){const empty=document.createElement('strong');empty.textContent=i18n.t('Chưa có địa chỉ');$('recentList').append(empty);}
    for(const item of data.items)addRecent(item.email,item.created_at);
    $('addressStat').textContent=data.today_total;
    $('historyStatus').textContent = '';
  } catch (error) {
    if (revision === state.historyRevision) {
      $('historyStatus').textContent = i18n.t('Không tải được danh sách. ') + error.message;
      $('historyPageSize').value = String(state.history?.page_size || 10);
    }
    throw error;
  } finally {
    if (revision === state.historyRevision) { renderHistoryPagination(); $('recentList').setAttribute('aria-busy', 'false'); $('historyPageSize').disabled = false; }
  }
}
async function generate(){
  const button=$('generateBtn');button.disabled=true;button.textContent=i18n.t('Đang tạo…');
  try{
    const config=workspace.settings;
    const result=await workspace.api('/api/v1/generator/email',{method:'POST',body:JSON.stringify({domain:config.default_domain,type:config.generator_type,prefix:config.default_prefix||''})});
    state.email=result.email;$('generatedEmail').textContent=result.email;$('copyEmail').disabled=false;$('copyAddressBtn').disabled=false;
    if(!state.watching&&(config.auto_fill_watch||config.auto_start_watch)){$('watchEmail').value=result.email;clearOTP();}
    await loadHistory();workspace.toast(i18n.t('Đã tạo địa chỉ email.'));
    if(config.auto_start_watch&&!state.watching)await startWatch();
  }catch(error){workspace.toast(error.message);}finally{button.disabled=false;button.textContent=i18n.t('Tạo email');}
}
function clearOTP(){state.otp='';$('otpResult').querySelector('.otp-empty').textContent=i18n.t('Chưa có mã');$('copyOtp').disabled=true;}
async function checkNow(quiet=false){
  const email=(state.watching?state.watchedEmail:$('watchEmail').value).trim().toLowerCase();
  if(!email){if(!quiet){workspace.toast(i18n.t('Nhập địa chỉ email trước.'));$('watchEmail').focus();}return;}
  if(!$('watchEmail').checkValidity()){$('watchEmail').reportValidity();return;}
  $('checkBtn').disabled=true;
  try{
    const data=await workspace.api('/api/v1/otp/latest?email='+encodeURIComponent(email));
    if(email!==$('watchEmail').value.trim().toLowerCase())return;
    state.otp=data.otp||'';$('otpResult').querySelector('.otp-empty').textContent=state.otp||i18n.t('Chưa có mã');$('copyOtp').disabled=!state.otp;
    $('watchStatus').querySelector('strong').textContent=state.otp?'Đã tìm thấy OTP':state.watching?'Đang theo dõi hộp thư IMAP':i18n.t('Chưa có OTP');
    $('watchStatus').querySelector('small').textContent=data.mail_error||(data.mail_connection==='not_configured'?'Chưa cấu hình IMAP. Vào Settings > Kết nối email.':data.mail_connection==='syncing'?'Đang kiểm tra hộp thư; sẽ thử lại.':i18n.t('Đã kiểm tra thư IMAP · Theo dõi mỗi 5 giây'));
    if(!quiet)workspace.toast(state.otp?'Đã tìm thấy mã OTP.':i18n.t('Chưa có mã OTP cho địa chỉ này.'));
  }catch(error){if(!quiet)workspace.toast(error.message);}finally{$('checkBtn').disabled=false;}
}
function scheduleWatch(){
  clearTimeout(state.watchTimer);
  if(state.watching&&!document.hidden)state.watchTimer=setTimeout(async()=>{await checkNow(true);scheduleWatch();},5000);
}
async function startWatch(){
  const email=$('watchEmail').value.trim().toLowerCase();
  if(!email||!$('watchEmail').checkValidity()){$('watchEmail').reportValidity();workspace.toast(i18n.t('Nhập địa chỉ email hợp lệ.'));return;}
  try{
    await workspace.api('/api/v1/watches',{method:'POST',body:JSON.stringify({email})});
    state.watching=true;state.watchedEmail=email;$('watchEmail').disabled=true;$('watchBtn').classList.add('hidden');$('stopBtn').classList.remove('hidden');$('watchStatus').classList.add('watching');
    await checkNow(true);scheduleWatch();
  }catch(error){workspace.toast(error.message);}
}
async function stopWatch(){
  try{await workspace.api('/api/v1/watches?email='+encodeURIComponent(state.watchedEmail),{method:'DELETE'});}
  catch(error){workspace.toast(error.message);}
  state.watching=false;state.watchedEmail='';clearTimeout(state.watchTimer);$('watchEmail').disabled=false;$('watchBtn').classList.remove('hidden');$('stopBtn').classList.add('hidden');$('watchStatus').classList.remove('watching');$('watchStatus').querySelector('strong').textContent=i18n.t('Đã dừng theo dõi');
}
$('generateBtn').addEventListener('click',generate);
$('historyPageSize').addEventListener('change',()=>loadHistory().catch(error=>workspace.toast(error.message)));
for(const id of ['copyEmail','copyAddressBtn'])$(id).addEventListener('click',()=>workspace.copy(state.email));
$('copyOtp').addEventListener('click',()=>workspace.copy(state.otp));
$('pasteEmail').addEventListener('click',()=>{if(state.email&&!state.watching){$('watchEmail').value=state.email;clearOTP();}else if(!state.email)workspace.toast(i18n.t('Tạo địa chỉ email trước.'));});
$('watchEmail').addEventListener('input',clearOTP);
$('checkBtn').addEventListener('click',()=>checkNow());
$('watchBtn').addEventListener('click',startWatch);$('stopBtn').addEventListener('click',stopWatch);
$('refreshBtn').addEventListener('click',async()=>{
  try{[workspace.settings,workspace.mail]=await Promise.all([workspace.api('/api/v1/settings'),workspace.api('/api/v1/settings/mail')]);renderDefaults();await loadHistory();workspace.toast(i18n.t('Đã làm mới Dashboard.'));}
  catch(error){workspace.toast(error.message);}
});
document.addEventListener('visibilitychange',scheduleWatch);
$('currentDate').textContent=new Date().toLocaleDateString(i18n.locale,{weekday:'long',day:'numeric',month:'long',year:'numeric'});
workspace.ready.then(async ready=>{
  if(!ready)return;renderDefaults();for(const id of ['generateBtn','checkBtn','watchBtn'])$(id).disabled=false;
  try{await loadHistory();}catch(error){workspace.toast(error.message);}
});
