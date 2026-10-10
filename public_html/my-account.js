'use strict';
const $ = id => document.getElementById(id);
const accountState = {ready:false, busyPassword:false, busyProfile:false};
function fillProfile(profile) {
  $('profileUsername').value = profile.username;
  $('profileFullName').value = profile.full_name;
  $('profileEmail').value = profile.email;
  $('profileTelegram').value = profile.telegram_contact;
  workspace.profile = profile; workspace.renderAvatar();
}
$('profileForm').addEventListener('submit', async event => {
  event.preventDefault();
  if (!accountState.ready || accountState.busyProfile || accountState.busyPassword) return;
  accountState.busyProfile = true;
  const controls = [...$('profileForm').elements, ...$('userPasswordForm').elements];
  controls.forEach(control => { control.disabled = true; });
  const result = $('profileResult'); result.className = 'form-result'; result.textContent = i18n.t('Đang lưu…');
  try {
    const profile = await workspace.api('/api/v1/auth/profile', {method:'PUT', body:JSON.stringify({full_name:$('profileFullName').value, email:$('profileEmail').value, telegram_contact:$('profileTelegram').value})});
    fillProfile(profile); result.textContent = i18n.t('Đã lưu thông tin tài khoản.'); result.className = 'form-result success'; workspace.toast(i18n.t('Đã lưu thông tin tài khoản.'));
  } catch (error) { result.textContent = error.message; result.className = 'form-result error'; }
  finally { accountState.busyProfile = false; controls.forEach(control => { control.disabled = false; }); }
});
const passwordErrors = {
  'new password must contain at least 10 characters': i18n.t('Mật khẩu mới cần ít nhất 10 ký tự.'),
  'new password must not exceed 72 bytes': i18n.t('Mật khẩu mới quá dài. Vui lòng dùng mật khẩu ngắn hơn.'),
  'new password must be different from the current password': i18n.t('Mật khẩu mới phải khác mật khẩu hiện tại.'),
  'password changed; please sign in again': i18n.t('Đã đổi mật khẩu. Vui lòng đăng nhập lại.'),
  'could not change password': i18n.t('Không thể đổi mật khẩu. Vui lòng thử lại.')
};
$('userPasswordForm').addEventListener('input', () => {
  $('userConfirmPassword').setCustomValidity('');
  $('userPasswordResult').textContent = ''; $('userPasswordResult').className = 'form-result';
});
$('userPasswordForm').addEventListener('submit', async event => {
  event.preventDefault();
  if (!accountState.ready || accountState.busyPassword || accountState.busyProfile) return;
  const current = $('userCurrentPassword').value;
  const next = $('userNewPassword').value;
  const result = $('userPasswordResult');
  function showError(message) { result.textContent = message; result.className = 'form-result error'; }
  if (next !== $('userConfirmPassword').value) {
    const message = i18n.t('Hai mật khẩu mới không khớp.');
    $('userConfirmPassword').setCustomValidity(message); $('userConfirmPassword').reportValidity(); showError(message); return;
  }
  if ([...next].length < 10) { showError(i18n.t('Mật khẩu mới cần ít nhất 10 ký tự.')); return; }
  if (new TextEncoder().encode(next).length > 72) { showError(i18n.t('Mật khẩu mới quá dài. Vui lòng dùng mật khẩu ngắn hơn.')); return; }
  if (next === current) { showError(i18n.t('Mật khẩu mới phải khác mật khẩu hiện tại.')); return; }
  accountState.busyPassword = true;
  const controls = [...$('userPasswordForm').elements, ...$('profileForm').elements];
  controls.forEach(control => { control.disabled = true; });
  result.textContent = i18n.t('Đang đổi mật khẩu…'); result.className = 'form-result';
  try {
    await workspace.api('/api/v1/auth/password', {method:'POST', body:JSON.stringify({current_password:current, new_password:next})});
    $('userPasswordForm').reset();
    $('userUsername').value = workspace.session.username;
    // The server rotates both the session cookie and CSRF token after a change.
    try {
      workspace.session = await workspace.api('/api/v1/auth/session');
      workspace.csrfToken = workspace.session.csrf_token;
    } catch (_) {
      result.textContent = i18n.t('Đã đổi mật khẩu. Vui lòng đăng nhập lại.');
      window.location.replace('/login.html'); return;
    }
    result.textContent = i18n.t('Đã đổi mật khẩu thành công. Các phiên đăng nhập khác đã được đăng xuất.');
    result.className = 'form-result success'; workspace.toast(i18n.t('Đã đổi mật khẩu.'));
  } catch (error) { showError(passwordErrors[error.message] || error.message); }
  finally { accountState.busyPassword = false; controls.forEach(control => { control.disabled = false; }); }
});
workspace.ready.then(ready => {
  if (!ready) return;
  $('userUsername').value = workspace.session.username;
  $('changeUserPasswordBtn').disabled = false;
  accountState.ready = true;
  if (workspace.profile) {
    fillProfile(workspace.profile); $('saveProfileBtn').disabled = false;
    $('profileResult').textContent = '';
  } else {
    $('profileResult').textContent = i18n.t('Không tải được thông tin tài khoản. Hãy tải lại trang.');
    $('profileResult').className = 'form-result error';
  }
});
