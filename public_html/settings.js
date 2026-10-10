'use strict';
const $ = id => document.getElementById(id);
const state = { busyMail: false, busySettings: false, busyPassword: false, ready: false, savedMailPassword: '', domains: [], domainPage: 1, accounts: [], accountPage: 1, selectedMail: null, mailStatuses: new Map() };
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
    remove.disabled = state.domains.length === 1 || state.accounts.some(account => account.domain === domain);
    if (state.accounts.some(account => account.domain === domain)) remove.title = 'Domain đang có kết nối email. Xóa kết nối trước khi bỏ domain.';
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
  if (!state.ready || state.busySettings || state.busyMail) return;
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
  state.selectedMail = config;
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
  $('saveMailBtn').textContent = config.id ? 'Lưu thay đổi Email Config' : 'Thêm Email Config';
  $('cancelMailBtn').hidden = Boolean(config.id) || state.accounts.length === 0;
}
function updateMailPasswordHint() {
  const saved = state.selectedMail;
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
  return { ...(state.selectedMail?.id ? {id:state.selectedMail.id} : {}), provider:$('mailProvider').value, host:$('mailHost').value.trim(), port:Number($('mailPort').value), username:$('mailUsername').value.trim(), folder:$('mailFolder').value.trim(), password:$('mailPassword').value };
}
function setMailBusy(busy) {
  state.busyMail = busy;
  for (const el of $('mailForm').elements) el.disabled = busy;
  $('addMailBtn').disabled = busy || !state.ready;
  renderMailAccounts();
}
function emptyMail() {
  return {id:0, domain:'', provider:'yandex', host:'imap.yandex.com', port:993, username:'', folder:'INBOX', has_password:false, configured:false};
}
function syncMailDomains(settings) {
  workspace.settings = settings;
  const selected = $('settingDomain').value;
  state.domains = state.domains.length === 1 && state.domains[0] === 'yourdomain.com'
    ? [...settings.default_domains] : [...new Set([...state.domains, ...settings.default_domains])];
  renderDomains(settings.default_domains.includes(selected) ? selected : settings.default_domain);
  $('settingsSavedAt').textContent = 'Domain của mailbox đã được lưu tự động. Các thay đổi khác trong form vẫn cần bấm Lưu.';
}
function renderMailAccounts() {
  const pages = Math.max(1, Math.ceil(state.accounts.length / pagination.pageSize));
  state.accountPage = Math.min(state.accountPage, pages);
  const start = (state.accountPage - 1) * pagination.pageSize;
  $('mailAccountsList').replaceChildren(...state.accounts.slice(start, start + pagination.pageSize).map(account => {
    const item = document.createElement('li');
    const details = document.createElement('div'); details.className = 'mail-account-details';
    const domain = document.createElement('strong'); domain.textContent = account.domain || 'Mailbox cũ chưa có domain';
    const username = document.createElement('span'); username.textContent = account.username;
    const server = document.createElement('small'); server.textContent = `${account.host}:${account.port} · ${account.folder} · TLS`;
    const status = document.createElement('small');
    const checked = state.mailStatuses.get(account.id);
    status.textContent = checked?.message || 'Đã lưu cấu hình · Chưa kiểm tra kết nối trong phiên này';
    status.className = checked?.error ? 'mail-account-status error' : 'mail-account-status';
    details.append(domain, username, server, status);
    const actions = document.createElement('div'); actions.className = 'mail-account-actions';
    for (const [label, action] of [['Sửa', () => editMail(account)], ['Kiểm tra', () => testSavedMail(account)], ['Xóa', () => deleteMail(account)]]) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary-button';
      button.textContent = label; button.disabled = state.busyMail;
      button.setAttribute('aria-label', label + ' ' + account.username);
      button.addEventListener('click', action); actions.append(button);
    }
    item.append(details, actions); return item;
  }));
  $('mailAccountsList').setAttribute('aria-busy', String(state.busyMail));
  pagination.render($('mailAccountsPagination'), {page:state.accountPage, total:state.accounts.length, totalPages:pages, disabled:state.busyMail,
    onChange: page => {state.accountPage = page; renderMailAccounts();}});
}
async function editMail(account) {
  if (state.busyMail) return;
  setMailBusy(true); mailForm(account);
  try {
    const {password, ...config} = await workspace.api('/api/v1/settings/mail/password', {method:'POST', body:JSON.stringify({id:account.id})});
    mailForm(config, password);
  } catch (error) {
    $('mailResult').textContent = error.message; $('mailResult').className = 'form-result error';
    $('passwordHint').textContent = 'Chưa tải được mật khẩu. Giữ trống để tiếp tục dùng mật khẩu đã lưu của tài khoản này.';
  } finally { setMailBusy(false); }
}
function newMail() {
  if (!state.ready || state.busyMail) return;
  mailForm(emptyMail()); $('mailUsername').focus();
}
$('addMailBtn').addEventListener('click', newMail);
$('cancelMailBtn').addEventListener('click', () => { if (state.accounts.length) editMail(state.accounts[0]); });
async function testSavedMail(account) {
  if (!state.ready || state.busyMail) return;
  setMailBusy(true); $('mailAccountsResult').textContent = 'Đang kiểm tra ' + account.username + '…';
  try {
    const data = await workspace.api('/api/v1/settings/mail/test', {method:'POST', body:JSON.stringify({...account, password:''})});
    const message = `Kết nối thành công · ${data.folder}: ${data.messages} thư · ${new Date().toLocaleTimeString('vi-VN')}`;
    state.mailStatuses.set(account.id, {message}); $('mailAccountsResult').textContent = account.username + ': ' + message;
  } catch (error) {
    state.mailStatuses.set(account.id, {error:true, message:error.message}); $('mailAccountsResult').textContent = account.username + ': ' + error.message;
  } finally { setMailBusy(false); }
}
async function deleteMail(account) {
  if (!state.ready || state.busyMail || !window.confirm('Xóa kết nối ' + account.username + '? Domain và lịch sử địa chỉ email vẫn được giữ lại.')) return;
  setMailBusy(true);
  try {
    const data = await workspace.api('/api/v1/settings/mail/accounts', {method:'DELETE', body:JSON.stringify({id:account.id})});
    state.accounts = data.items; state.mailStatuses.delete(account.id);
    workspace.mail = state.accounts[0] || emptyMail();
    if (state.selectedMail?.id === account.id) mailForm(emptyMail());
    renderDomains(); $('mailAccountsResult').textContent = 'Đã xóa kết nối ' + account.username + '.';
  } catch (error) { $('mailAccountsResult').textContent = error.message; }
  finally { setMailBusy(false); }
}
async function mailAction(test) {
  if (!state.ready || state.busyMail || state.busySettings || !$('mailForm').reportValidity()) return;
  const payload = mailPayload();
  const create = !payload.id;
  setMailBusy(true); $('mailResult').className = 'form-result';
  $('mailResult').textContent = test ? 'Đang kiểm tra DNS, TLS, đăng nhập và folder…' : 'Đang lưu cấu hình…';
  try {
    const path = test ? '/api/v1/settings/mail/test' : create ? '/api/v1/settings/mail/accounts' : '/api/v1/settings/mail';
    const data = await workspace.api(path, {method:test || create ? 'POST' : 'PUT', body:JSON.stringify(payload)});
    if (test) {
      $('mailResult').textContent = `Kiểm tra thành công: ${data.folder} có ${data.messages} thư. Cấu hình chưa lưu cần bấm Lưu hoặc Thêm Email Config.`;
    } else {
      const {settings, ...config} = data;
      state.accounts = [...state.accounts.filter(account => account.id !== config.id), config].sort((a,b) => a.id - b.id);
      state.mailStatuses.delete(config.id);
      workspace.mail = state.accounts[0];
      mailForm(config, payload.password || state.savedMailPassword);
      syncMailDomains(settings);
      state.accountPage = Math.floor(state.accounts.findIndex(account => account.id === config.id) / pagination.pageSize) + 1;
      $('mailResult').textContent = 'Đã lưu tài khoản. Domain ' + config.domain + ' đã được thêm vào DOMAIN MẶC ĐỊNH.';
      $('mailAccountsResult').textContent = state.accounts.length + ' tài khoản email đã lưu. Bấm Kiểm tra để xác minh kết nối.';
    }
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
  if (!state.ready || state.busySettings || state.busyMail) return;
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
  setMailBusy(true);
  try {
    const data = await workspace.api('/api/v1/settings/mail/accounts');
    state.accounts = data.items; renderDomains();
    $('mailAccountsResult').textContent = state.accounts.length ? state.accounts.length + ' tài khoản email đã lưu.' : 'Chưa có tài khoản. Nhập mailbox rồi bấm Thêm Email Config.';
  } catch (error) {
    $('mailAccountsResult').textContent = error.message;
  } finally { setMailBusy(false); }
  if (workspace.mail.id) await editMail(workspace.mail);
});
