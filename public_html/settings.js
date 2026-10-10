'use strict';
const $ = id => document.getElementById(id);
const state = { busyMail: false, busySettings: false, ready: false, savedMailPassword: '', domains: [], domainPage: 1, accounts: [], accountPage: 1, selectedMail: null, mailStatuses: new Map() };
const tabs = [...document.querySelectorAll('[role="tab"]')];
function activateTab(id, updateHash = false) {
  if (id === 'user') { window.location.replace('/my-account.html'); return; }
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
    remove.type = 'button'; remove.className = 'domain-remove'; remove.textContent = i18n.t('×');
    remove.setAttribute('aria-label', i18n.t('Xóa domain ') + domain);
    remove.disabled = state.domains.length === 1 || state.accounts.some(account => account.domain === domain);
    if (state.accounts.some(account => account.domain === domain)) remove.title = i18n.t('Domain đang có kết nối email. Xóa kết nối trước khi bỏ domain.');
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
  $('settingsSavedAt').textContent = i18n.t('Có thay đổi chưa lưu.');
}
function addDomain() {
  if (!state.ready || state.busySettings || state.busyMail) return;
  const input = $('newDomain');
  const domain = input.value.trim().replace(/^@/, '').toLowerCase();
  const valid = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(domain) && domain.length <= 253;
  if (!valid) { $('domainResult').textContent = i18n.t('Nhập tên miền hợp lệ, ví dụ example.com.'); input.focus(); return; }
  if (state.domains.includes(domain)) { $('domainResult').textContent = i18n.t('Tên miền này đã có trong danh sách.'); input.focus(); return; }
  if (state.domains.length >= 100) { $('domainResult').textContent = i18n.t('Danh sách tối đa 100 tên miền.'); return; }
  state.domains.push(domain);
  state.domainPage = Math.ceil(state.domains.length / pagination.pageSize);
  renderDomains(); input.value = '';
  $('domainResult').textContent = i18n.t('Đã thêm ') + domain + i18n.t('. Bấm Lưu để sử dụng trong Quick OTP.');
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
  $('mailResult').textContent = config.configured ? 'Đã lưu cấu hình. Bấm Kiểm tra kết nối để xác minh tài khoản.' : i18n.t('Chưa có kết nối email. Nhập hộp thư chính nhận alias bên trên.');
  $('mailResult').className = 'form-result';
  $('saveMailBtn').textContent = config.id ? 'Lưu thay đổi Email Config' : i18n.t('Thêm Email Config');
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
  $('mailPassword').placeholder = canReuse ? '••••••••••••' : i18n.t('Nhập mật khẩu ứng dụng');
  $('mailPassword').required = !canReuse;
  $('mailPassword').dataset.savedPassword = String(Boolean(canReuse));
  $('passwordHint').textContent = canReuse
    ? $('mailPassword').value
      ? 'Đã lưu mật khẩu. Mật khẩu được điền lại và che bằng dấu chấm; nhập mật khẩu mới để thay thế.'
      : 'Đã lưu mật khẩu. Giữ trống để tiếp tục sử dụng mật khẩu cũ; nhập mật khẩu mới để thay thế.'
    : saved?.has_password ? 'Máy chủ hoặc tài khoản đã thay đổi. Nhập App Password cho kết nối này.' : i18n.t('Mật khẩu được mã hóa khi lưu.');
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
  $('settingsSavedAt').textContent = i18n.t('Domain của mailbox đã được lưu tự động. Các thay đổi khác trong form vẫn cần bấm Lưu.');
}
function renderMailAccounts() {
  const pages = Math.max(1, Math.ceil(state.accounts.length / pagination.pageSize));
  state.accountPage = Math.min(state.accountPage, pages);
  const start = (state.accountPage - 1) * pagination.pageSize;
  $('mailAccountsList').replaceChildren(...state.accounts.slice(start, start + pagination.pageSize).map(account => {
    const item = document.createElement('li');
    const details = document.createElement('div'); details.className = 'mail-account-details';
    const domain = document.createElement('strong'); domain.textContent = account.domain || i18n.t('Mailbox cũ chưa có domain');
    const username = document.createElement('span'); username.textContent = account.username;
    const server = document.createElement('small'); server.textContent = `${account.host}:${account.port} · ${account.folder} · TLS`;
    const status = document.createElement('small');
    const checked = state.mailStatuses.get(account.id);
    status.textContent = checked?.message || i18n.t('Đã lưu cấu hình · Chưa kiểm tra kết nối trong phiên này');
    status.className = checked?.error ? 'mail-account-status error' : 'mail-account-status';
    details.append(domain, username, server, status);
    const actions = document.createElement('div'); actions.className = 'mail-account-actions';
    for (const [label, action] of [[i18n.t('Sửa'), () => editMail(account)], [i18n.t('Kiểm tra'), () => testSavedMail(account)], [i18n.t('Xóa'), () => deleteMail(account)]]) {
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
    $('passwordHint').textContent = i18n.t('Chưa tải được mật khẩu. Giữ trống để tiếp tục dùng mật khẩu đã lưu của tài khoản này.');
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
  setMailBusy(true); $('mailAccountsResult').textContent = i18n.t('Đang kiểm tra ') + account.username + '…';
  try {
    const data = await workspace.api('/api/v1/settings/mail/test', {method:'POST', body:JSON.stringify({...account, password:''})});
    const message = i18n.t(`Kết nối thành công · ${data.folder}: ${data.messages} thư · ${new Date().toLocaleTimeString(i18n.locale)}`);
    state.mailStatuses.set(account.id, {message}); $('mailAccountsResult').textContent = account.username + ': ' + message;
  } catch (error) {
    state.mailStatuses.set(account.id, {error:true, message:error.message}); $('mailAccountsResult').textContent = account.username + ': ' + error.message;
  } finally { setMailBusy(false); }
}
async function deleteMail(account) {
  if (!state.ready || state.busyMail || !window.confirm(i18n.t('Xóa kết nối ') + account.username + i18n.t('? Domain và lịch sử địa chỉ email vẫn được giữ lại.'))) return;
  setMailBusy(true);
  try {
    const data = await workspace.api('/api/v1/settings/mail/accounts', {method:'DELETE', body:JSON.stringify({id:account.id})});
    state.accounts = data.items; state.mailStatuses.delete(account.id);
    workspace.mail = state.accounts[0] || emptyMail();
    if (state.selectedMail?.id === account.id) mailForm(emptyMail());
    renderDomains(); $('mailAccountsResult').textContent = i18n.t('Đã xóa kết nối ') + account.username + '.';
  } catch (error) { $('mailAccountsResult').textContent = error.message; }
  finally { setMailBusy(false); }
}
async function mailAction(test) {
  if (!state.ready || state.busyMail || state.busySettings || !$('mailForm').reportValidity()) return;
  const payload = mailPayload();
  const create = !payload.id;
  setMailBusy(true); $('mailResult').className = 'form-result';
  $('mailResult').textContent = test ? 'Đang kiểm tra DNS, TLS, đăng nhập và folder…' : i18n.t('Đang lưu cấu hình…');
  try {
    const path = test ? '/api/v1/settings/mail/test' : create ? '/api/v1/settings/mail/accounts' : '/api/v1/settings/mail';
    const data = await workspace.api(path, {method:test || create ? 'POST' : 'PUT', body:JSON.stringify(payload)});
    if (test) {
      $('mailResult').textContent = i18n.t(`Kiểm tra thành công: ${data.folder} có ${data.messages} thư. Cấu hình chưa lưu cần bấm Lưu hoặc Thêm Email Config.`);
    } else {
      const {settings, ...config} = data;
      state.accounts = [...state.accounts.filter(account => account.id !== config.id), config].sort((a,b) => a.id - b.id);
      state.mailStatuses.delete(config.id);
      workspace.mail = state.accounts[0];
      mailForm(config, payload.password || state.savedMailPassword);
      syncMailDomains(settings);
      state.accountPage = Math.floor(state.accounts.findIndex(account => account.id === config.id) / pagination.pageSize) + 1;
      $('mailResult').textContent = i18n.t('Đã lưu tài khoản. Domain ') + config.domain + i18n.t(' đã được thêm vào DOMAIN MẶC ĐỊNH.');
      $('mailAccountsResult').textContent = state.accounts.length + i18n.t(' tài khoản email đã lưu. Bấm Kiểm tra để xác minh kết nối.');
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
$('mailForm').addEventListener('input', () => { updateMailPasswordHint(); $('mailResult').className='form-result'; $('mailResult').textContent=i18n.t('Có thay đổi chưa lưu. Hãy kiểm tra hoặc lưu cấu hình mới.'); });
$('settingGenerator').addEventListener('change', togglePrefix);
$('settingAppearance').addEventListener('change', () => {
  workspace.appearance($('settingAppearance').value);
  $('generalSavedAt').textContent = i18n.t('Có thay đổi chưa lưu.');
});
$('settingsForm').addEventListener('input', markGeneratorDirty);
async function saveSettings(general) {
  if (!state.ready || state.busySettings || state.busyMail) return;
  if (!general && $('newDomain').value.trim()) {
    $('domainResult').textContent = i18n.t('Bấm + để thêm tên miền đã nhập trước khi lưu.');
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
  status.textContent = i18n.t('Đang lưu…');
  try {
    const data=await workspace.api('/api/v1/settings',{method:'PUT',body:JSON.stringify(settings)});
    workspace.settings = data;
    if (general) workspace.appearance(data.appearance);
    else { generatorForm(data); $('domainResult').textContent = ''; }
    status.textContent = i18n.t('Đã lưu. Dashboard và Quick OTP sẽ dùng cấu hình này.');
    workspace.toast(general ? 'Đã lưu cài đặt chung.' : i18n.t('Đã lưu cấu hình username và domain.'));
  } catch(error) { status.textContent=error.message;workspace.toast(error.message); }
  finally {
    controls.forEach((control, index) => { control.disabled = previouslyDisabled[index]; });
    state.busySettings = false;
  }
}
$('settingsForm').addEventListener('submit', event => { event.preventDefault(); saveSettings(false); });
$('generalForm').addEventListener('submit', event => { event.preventDefault(); saveSettings(true); });
workspace.ready.then(async ready => {
  if (!ready) return;
  state.ready=true;generatorForm(workspace.settings);mailForm(workspace.mail);
  $('settingAppearance').value = workspace.settings.appearance;
  $('saveGeneralBtn').disabled=false;$('saveSettingsBtn').disabled=false;$('saveMailBtn').disabled=false;$('testMailBtn').disabled=false;
  $('generalSavedAt').textContent=i18n.t('Cấu hình được lưu trên máy chủ.');
  $('settingsSavedAt').textContent=i18n.t('Cấu hình được lưu trên máy chủ.');
  setMailBusy(true);
  try {
    const data = await workspace.api('/api/v1/settings/mail/accounts');
    state.accounts = data.items; renderDomains();
    $('mailAccountsResult').textContent = state.accounts.length ? state.accounts.length + ' tài khoản email đã lưu.' : i18n.t('Chưa có tài khoản. Nhập mailbox rồi bấm Thêm Email Config.');
  } catch (error) {
    $('mailAccountsResult').textContent = error.message;
  } finally { setMailBusy(false); }
  if (workspace.mail.id) await editMail(workspace.mail);
});
