const form=document.getElementById('loginForm');
const message=document.getElementById('message');
const submitButton=document.getElementById('submitButton');
const setupSuccess=document.getElementById('setupSuccess');
if(setupSuccess&&new URLSearchParams(window.location.search).get('installed')==='1'){
  const websitePort=window.location.port||(window.location.protocol==='https:'?'443':'80');
  setupSuccess.textContent=i18n.t(`Cài đặt thành công.\n${window.location.protocol}//${window.location.hostname}:${websitePort}/\nTên đăng nhập: admin\nMật khẩu: mật khẩu bạn vừa đặt.`);
  setupSuccess.hidden=false;
}
function showError(text){message.textContent=i18n.t(text);message.classList.add('show');}
async function checkExistingSession(){try{const response=await fetch('/api/v1/auth/session',{headers:{Accept:'application/json'}});if(!response.ok)return;const session=await response.json();window.location.replace(session.must_change_password?'/change-password.html':'/');}catch(_){}}
form.addEventListener('submit',async(event)=>{event.preventDefault();message.classList.remove('show');submitButton.disabled=true;submitButton.textContent=i18n.t('Đang đăng nhập…');try{const response=await fetch('/api/v1/auth/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({username:form.username.value.trim(),password:form.password.value})});const data=await response.json();if(!response.ok)throw new Error(data.error||i18n.t('Không thể đăng nhập'));window.location.replace(data.must_change_password?'/change-password.html':'/');}catch(error){showError(error.message||i18n.t('Không thể kết nối tới máy chủ'));form.password.value='';form.password.focus();}finally{submitButton.disabled=false;submitButton.textContent=i18n.t('Đăng nhập');}});
checkExistingSession();
