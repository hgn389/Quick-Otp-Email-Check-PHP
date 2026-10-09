'use strict';
const $ = id => document.getElementById(id);
const state = { busyMail: false, busySettings: false, busyPassword: false, ready: false, savedMailPassword: '', domains: [], domainPage: 1 };
const tabs = [...document.querySelectorAll('[role="tab"]')];
function activateTab(id, updateHash = false) {
  const active = tabs.find(tab => tab.getAttribute('aria-controls') === id) || tabs[0];
  for (const tab of tabs) {
    const selected = tab === active;
    tab.setAttribute('aria-selected', String(selected));
    tab.tabIndex = selected ? 0 : -1;
    $(tab.getAttribute('aria-controls')).hidden = !selected;
  }
  if (updateHash) history.replaceState(null, '', '#' + active.getAttribute('aria-controls'));
}
for (const [index, tab] of tabs.entries()) {
  tab.addEventListener('click', () => activateTab(tab.getAttribute('aria-controls'), true));
  tab.addEventListener('keydown', event => {
    let next;
    if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
    if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    if (next === undefined) return;
    event.preventDefault(); tabs[next].focus();
    activateTab(tabs[next].getAttribute('aria-controls'), true);
  });
}
window.addEventListener('hashchange', () => activateTab(window.location.hash.slice(1)));
activateTab(window.location.hash.slice(1));
function renderDomains(selected = $('settingDomain').value) {
  $('settingDomain').replaceChildren(...state.domains.map(domain => {
    const option = document.createElement('option');
    option.value = domain; option.textContent = domain;
    return option;
  }));
  $('settingDomain').value = state.domains.includes(selected) ? selected : state.domains[0];
  const totalPages = Math.max(1, Math.ceil(state.domains.length / pagination.pageSize));
  state.domainPage = Math.min(state.domainPage, totalPages);
  const start = (state.domainPage - 1) * pagination.pageSize;
  $('domainList').replaceChildren(...state.domains.slice(start, start + pagination.pageSize).map(domain => {
    const item = document.createElement('li');
    const label = document.createElement('span'); label.textContent = domain;
    const remove = document.createElement('button');
    remove.type = 'button'; remove.className = 'domain-remove'; remove.textContent = '×';
    remove.setAttribute('aria-label', 'Xóa domain ' + domain);
    remove.disabled = state.domains.length === 1;
    remove.addEventListener('click', () => {
      state.domains = state.domains.filter(value => value !== domain);
      renderDomains(); markGeneratorDirty();
    });
    item.append(label, remove); return item;
  }));
  pagination.render($('domainPagination'), {
    page: state.domainPage, total: state.domains.length, totalPages,
    onChange: page => { state.domainPage = page; renderDomains(); }
  });
}
function markGeneratorDirty() {
  $('settingsSavedAt').textContent = 'Có thay đổi chưa lưu.';
}
function addDomain() {
  if (!state.ready || state.busySettings) return;
  const input = $('newDomain');
  const domain = input.value.trim().replace(/^@/, '').toLowerCase();
  const valid = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(domain) && domain.length <= 253;
  if (!valid) { $('domainResult').textContent = 'Nhập tên miền hợp lệ, ví dụ example.com.'; input.focus(); return; }
  if (state.domains.includes(domain)) { $('domainResult').textContent = 'Tên miền này đã có trong danh sách.'; input.focus(); return; }
  if (state.domains.length >= 100) { $('domainResult').textContent = 'Danh sách tối đa 100 tên miền.'; return; }
  state.domains.push(domain);
  state.domainPage = Math.ceil(state.domains.length / pagination.pageSize);
  renderDomains(); input.value = '';
  $('domainResult').textContent = 'Đã thêm ' + domain + '. Bấm Lưu để sử dụng trong Quick OTP.';
  markGeneratorDirty(); input.focus();
}
$('addDomainBtn').addEventListener('click', addDomain);
$('newDomain').addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); addDomain(); } });
function generatorForm(settings) {
  state.domains = [...(settings.default_domains?.length ? settings.default_domains : [settings.default_domain])];
  renderDomains(settings.default_domain);
  $('settingGenerator').value = settings.generator_type;
  $('settingPrefix').value = settings.default_prefix || '';
  $('settingAutoFill').checked = settings.auto_fill_watch;
  $('settingAutoWatch').checked = settings.auto_start_watch;
  togglePrefix();
}
function togglePrefix() {
  const custom = $('settingGenerator').value === 'custom_prefix';
  $('settingPrefixField').hidden = !custom; $('settingPrefix').required = custom;
}
function mailForm(config, password = '') {
  $('mailProvider').value = config.provider;
  $('mailHost').value = config.host;
  $('mailPort').value = config.port;
  $('mailUsername').value = config.username;
  $('mailFolder').value = config.folder;
  state.savedMailPassword = password;
  $('mailPassword').value = password;
  updateMailPasswordHint();
  $('mailResult').textContent = config.configured ? 'Đã lưu cấu hình. Bấm Kiểm tra kết nối để xác minh tài khoản.' : 'Chưa có kết nối email. Nhập hộp thư chính nhận alias bên trên.';
  $('mailResult').className = 'form-result';
}
function updateMailPasswordHint() {
  const saved = workspace.mail;
  const canReuse = saved?.has_password && saved.provider === $('mailProvider').value
    && saved.host === $('mailHost').value.trim().toLowerCase()
    && saved.port === Number($('mailPort').value)
    && saved.username.toLowerCase() === $('mailUsername').value.trim().toLowerCase();
  if (!canReuse && state.savedMailPassword) {
    if ($('mailPassword').value === state.savedMailPassword) $('mailPassword').value = '';
    state.savedMailPassword = '';
  }
  $('mailPassword').placeholder = canReuse ? '••••••••••••' : 'Nhập mật khẩu ứng dụng';
  $('mailPassword').required = !canReuse;
  $('mailPassword').dataset.savedPassword = String(Boolean(canReuse));
  $('passwordHint').textContent = canReuse
    ? $('mailPassword').value
      ? 'Đã lưu mật khẩu. Mật khẩu được điền lại và che bằng dấu chấm; nhập mật khẩu mới để thay thế.'
      : 'Đã lưu mật khẩu. Giữ trống để tiếp tục sử dụng mật khẩu cũ; nhập mật khẩu mới để thay thế.'
    : saved?.has_password ? 'Máy chủ hoặc tài khoản đã thay đổi. Nhập App Password cho kết nối này.' : 'Mật khẩu được mã hóa khi lưu.';
}
function mailPayload() {
  return { provider:$('mailProvider').value, host:$('mailHost').value.trim(), port:Number($('mailPort').value), username:$('mailUsername').value.trim(), folder:$('mailFolder').value.trim(), password:$('mailPassword').value };
}
function setMailBusy(busy) {
  state.busyMail = busy;
  for (const el of $('mailForm').elements) el.disabled = busy;
}
async function mailAction(test) {
  if (!state.ready || state.busyMail || !$('mailForm').reportValidity()) return;
  const payload = mailPayload();
  setMailBusy(true); $('mailResult').className = 'form-result';
  $('mailResult').textContent = test ? 'Đang kiểm tra DNS, TLS, đăng nhập và folder…' : 'Đang lưu cấu hình…';
  try {
    const data = await workspace.api(test ? '/api/v1/settings/mail/test' : '/api/v1/settings/mail', { method:test?'POST':'PUT', body:JSON.stringify(payload) });
    if (test) $('mailResult').textContent = `Kiểm tra thành công: ${data.folder} có ${data.messages} thư. Cấu hình chưa lưu cần bấm Lưu Email Config.`;
    else { workspace.mail = data; mailForm(data, payload.password || state.savedMailPassword); $('mailResult').textContent = 'Đã lưu Email Config. Có thể kiểm tra kết nối bằng mật khẩu đã lưu.'; }
    $('mailResult').className = 'form-result success';
  } catch (error) { $('mailResult').textContent = error.message; $('mailResult').className = 'form-result error'; }
  finally { setMailBusy(false); }
}
$('mailForm').addEventListener('submit', event => { event.preventDefault(); mailAction(false); });
$('testMailBtn').addEventListener('click', () => mailAction(true));
$('mailProvider').addEventListener('change', () => {
  if ($('mailProvider').value === 'yandex') { $('mailHost').value = 'imap.yandex.com'; $('mailPort').value = 993; $('mailFolder').value = 'INBOX'; }
  updateMailPasswordHint();
});
$('mailForm').addEventListener('input', () => { updateMailPasswordHint(); $('mailResult').className='form-result'; $('mailResult').textContent='Có thay đổi chưa lưu. Hãy kiểm tra hoặc lưu cấu hình mới.'; });
$('settingGenerator').addEventListener('change', togglePrefix);
$('settingAppearance').addEventListener('change', () => {
  workspace.appearance($('settingAppearance').value);
  $('generalSavedAt').textContent = 'Có thay đổi chưa lưu.';
});
$('settingsForm').addEventListener('input', markGeneratorDirty);
async function saveSettings(general) {
  if (!state.ready || state.busySettings) return;
  if (!general && $('newDomain').value.trim()) {
    $('domainResult').textContent = 'Bấm + để thêm tên miền đã nhập trước khi lưu.';
    $('newDomain').focus(); return;
  }
  const settings = general
    ? {...workspace.settings, appearance: $('settingAppearance').value}
    : {...workspace.settings, default_domain:$('settingDomain').value, default_domains:[...state.domains], generator_type:$('settingGenerator').value, default_prefix:$('settingPrefix').value, auto_fill_watch:$('settingAutoFill').checked, auto_start_watch:$('settingAutoWatch').checked};
  const status = $(general ? 'generalSavedAt' : 'settingsSavedAt');
  state.busySettings = true;
  const controls = [...$('generalForm').elements, ...$('settingsForm').elements];
  const previouslyDisabled = controls.map(control => control.disabled);
  controls.forEach(control => { control.disabled = true; });
  status.textContent = 'Đang lưu…';
  try {
    const data=await workspace.api('/api/v1/settings',{method:'PUT',body:JSON.stringify(settings)});
    workspace.settings = data;
    if (general) workspace.appearance(data.appearance);
    else { generatorForm(data); $('domainResult').textContent = ''; }
    status.textContent = 'Đã lưu. Dashboard và Quick OTP sẽ dùng cấu hình này.';
    workspace.toast(general ? 'Đã lưu cài đặt chung.' : 'Đã lưu cấu hình username và domain.');
  } catch(error) { status.textContent=error.message;workspace.toast(error.message); }
  finally {
    controls.forEach((control, index) => { control.disabled = previouslyDisabled[index]; });
    state.busySettings = false;
  }
}
$('settingsForm').addEventListener('submit', event => { event.preventDefault(); saveSettings(false); });
$('generalForm').addEventListener('submit', event => { event.preventDefault(); saveSettings(true); });
const passwordErrors = {
  'new password must contain at least 10 characters': 'Mật khẩu mới cần ít nhất 10 ký tự.',
  'new password must not exceed 72 bytes': 'Mật khẩu mới quá dài. Vui lòng dùng mật khẩu ngắn hơn.',
  'new password must be different from the current password': 'Mật khẩu mới phải khác mật khẩu hiện tại.',
  'password changed; please sign in again': 'Đã đổi mật khẩu. Vui lòng đăng nhập lại.',
  'could not change password': 'Không thể đổi mật khẩu. Vui lòng thử lại.'
};
$('userPasswordForm').addEventListener('input', () => {
  $('userConfirmPassword').setCustomValidity('');
  $('userPasswordResult').textContent = ''; $('userPasswordResult').className = 'form-result';
});
$('userPasswordForm').addEventListener('submit', async event => {
  event.preventDefault();
  if (!state.ready || state.busyPassword) return;
  const current = $('userCurrentPassword').value;
  const next = $('userNewPassword').value;
  const result = $('userPasswordResult');
  function showError(message) { result.textContent = message; result.className = 'form-result error'; }
  if (next !== $('userConfirmPassword').value) {
    const message = 'Hai mật khẩu mới không khớp.';
    $('userConfirmPassword').setCustomValidity(message); $('userConfirmPassword').reportValidity(); showError(message); return;
  }
  if ([...next].length < 10) { showError('Mật khẩu mới cần ít nhất 10 ký tự.'); return; }
  if (new TextEncoder().encode(next).length > 72) { showError('Mật khẩu mới quá dài. Vui lòng dùng mật khẩu ngắn hơn.'); return; }
  if (next === current) { showError('Mật khẩu mới phải khác mật khẩu hiện tại.'); return; }
  state.busyPassword = true;
  const controls = [...$('userPasswordForm').elements];
  controls.forEach(control => { control.disabled = true; });
  result.textContent = 'Đang đổi mật khẩu…'; result.className = 'form-result';
  try {
    await workspace.api('/api/v1/auth/password', {method:'POST', body:JSON.stringify({current_password:current, new_password:next})});
    $('userPasswordForm').reset();
    $('userUsername').value = workspace.session.username;
    // The server rotates both the session cookie and CSRF token after a change.
    try {
      workspace.session = await workspace.api('/api/v1/auth/session');
      workspace.csrfToken = workspace.session.csrf_token;
    } catch (_) {
      result.textContent = 'Đã đổi mật khẩu. Vui lòng đăng nhập lại.';
      window.location.replace('/login.html'); return;
    }
    result.textContent = 'Đã đổi mật khẩu thành công. Các phiên đăng nhập khác đã được đăng xuất.';
    result.className = 'form-result success'; workspace.toast('Đã đổi mật khẩu.');
  } catch (error) { showError(passwordErrors[error.message] || error.message); }
  finally { state.busyPassword = false; controls.forEach(control => { control.disabled = false; }); }
});
workspace.ready.then(async ready => {
  if (!ready) return;
  state.ready=true;generatorForm(workspace.settings);mailForm(workspace.mail);
  $('settingAppearance').value = workspace.settings.appearance;
  $('userUsername').value = workspace.session.username;
  $('changeUserPasswordBtn').disabled = false;
  $('saveGeneralBtn').disabled=false;$('saveSettingsBtn').disabled=false;$('saveMailBtn').disabled=false;$('testMailBtn').disabled=false;
  $('generalSavedAt').textContent='Cấu hình được lưu trên máy chủ.';
  $('settingsSavedAt').textContent='Cấu hình được lưu trên máy chủ.';
  if (workspace.mail.has_password) {
    setMailBusy(true);
    try {
      const {password, ...config} = await workspace.api('/api/v1/settings/mail/password', {method:'POST'});
      workspace.mail = config;
      mailForm(config, password);
    } catch (error) {
      $('passwordHint').textContent = 'Chưa tải được mật khẩu đã lưu. Bạn vẫn có thể giữ trống ô để dùng mật khẩu cũ.';
      $('mailResult').textContent = error.message;
      $('mailResult').className = 'form-result error';
    } finally { setMailBusy(false); }
  }
});
