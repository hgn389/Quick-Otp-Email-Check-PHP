'use strict';
const $ = id => document.getElementById(id);
const state = { csrf: '', address: '', otp: '', ready: false, connected: false, loading: false, timer: null, toastTimer: null, request: null, revision: 0, retryDelay: 5000 };
const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
let appearance = 'dark';
function applyTheme(value) {
  appearance = ['dark', 'light', 'system'].includes(value) ? value : 'dark';
  const dark = appearance === 'dark' || (appearance === 'system' && systemTheme.matches);
  document.body.classList.toggle('dark', dark);
  document.documentElement.classList.toggle('dark', dark);
}
try { applyTheme(localStorage.getItem('quickotp-theme') || 'dark'); } catch (_) { applyTheme('dark'); }
systemTheme.addEventListener('change', () => applyTheme(appearance));
window.addEventListener('storage', event => { if (event.key === 'quickotp-theme') applyTheme(event.newValue); });
function toast(text) {
  $('toast').textContent = text;
  $('toast').classList.add('show');
  clearTimeout(state.toastTimer);
  state.toastTimer = setTimeout(() => $('toast').classList.remove('show'), 2500);
}
async function api(path, options = {}) {
  const response = await fetch(path, { ...options, headers: { Accept: 'application/json', ...(options.headers || {}) }, cache: 'no-store' });
  if (response.status === 401) { window.location.replace('/login.html'); const error = new Error('Phiên đăng nhập đã hết hạn.'); error.status = 401; throw error; }
  const data = await response.json();
  if (!response.ok) { const error = new Error(data.error || 'Không thể xử lý yêu cầu.'); error.status = response.status; throw error; }
  return data;
}
async function copy(value) {
  if (!value) return;
  try {
    if (window.isSecureContext && navigator.clipboard) await navigator.clipboard.writeText(value);
    else {
      const input = document.createElement('textarea');
      input.value = value; input.setAttribute('readonly', ''); input.style.position = 'fixed'; input.style.opacity = '0';
      document.body.appendChild(input); input.focus(); input.select(); input.setSelectionRange(0, value.length);
      const copied = document.execCommand('copy'); input.remove();
      if (!copied) throw new Error();
    }
    toast('Đã Copy');
  } catch (_) { toast('Không copy được. Hãy nhấn giữ để sao chép.'); }
}
function resetMessage() {
  state.revision++;
  if (state.request) state.request.abort();
  clearTimeout(state.timer);
  state.connected = false;
  state.retryDelay = 5000;
  state.otp = '';
  $('copyOtp').disabled = true;
  $('mailContent').hidden = true;
  $('mailEmpty').hidden = false;
  $('mailEmpty').querySelector('p').textContent = 'Thư mới nhất sẽ hiển thị tại đây.';
  $('mailStatus').textContent = 'Nhấn Xem / Làm mới để kiểm tra địa chỉ.';
  $('mailStatus').classList.remove('error');
}
function renderMessage(message) {
  $('mailEmpty').hidden = true;
  $('mailContent').hidden = false;
  // Mail is untrusted: never inject HTML, scripts, images or remote content.
  $('mailSubject').textContent = message.subject || '(Không có tiêu đề)';
  $('mailSender').textContent = message.sender;
  $('mailRecipient').textContent = message.recipient;
  $('mailTime').textContent = new Date(message.received_at).toLocaleString('vi-VN');
  $('mailBody').textContent = message.body_text || '(Thư không có nội dung văn bản)';
  state.otp = message.otp || '';
  $('mailOtp').textContent = state.otp;
  $('otpBlock').hidden = !state.otp;
  $('copyOtp').disabled = !state.otp;
}
async function loadMail() {
  const email = $('viewEmail').value.trim().toLowerCase();
  if (!state.ready) return;
  if (!email) { toast('Nhập hoặc tạo địa chỉ email trước.'); $('viewEmail').focus(); return; }
  if (!$('viewEmail').checkValidity()) { $('viewEmail').reportValidity(); return; }
  clearTimeout(state.timer);
  if (state.request) state.request.abort();
  const controller = new AbortController();
  state.request = controller;
  const revision = ++state.revision;
  let nextDelay = 5000;
  let retry = false;
  $('refreshMail').disabled = true;
  $('mailStatus').textContent = 'Đang kiểm tra thư…';
  $('mailStatus').classList.remove('error');
  try {
    const data = await api('/api/v1/messages/latest?email=' + encodeURIComponent(email), { signal: controller.signal });
    if (revision !== state.revision || email !== $('viewEmail').value.trim().toLowerCase()) return;
    if (data.message) renderMessage(data.message);
    else {
      $('mailContent').hidden = true; $('mailEmpty').hidden = false; state.otp = ''; $('copyOtp').disabled = true;
      $('mailEmpty').querySelector('p').textContent = 'Chưa có thư cho địa chỉ này.';
    }
    state.connected = data.mail_connection !== 'not_configured';
    retry = state.connected;
    state.retryDelay = 5000;
    if (data.mail_connection === 'syncing') nextDelay = 2000;
    if (data.mail_connection === 'not_configured') {
      $('mailStatus').textContent = 'Chưa cấu hình IMAP. Vào Settings > Kết nối email để thiết lập.';
    } else if (data.mail_connection === 'error') {
      $('mailStatus').textContent = data.mail_error || 'Không đồng bộ được thư IMAP. Kiểm tra cấu hình kết nối.';
      $('mailStatus').classList.add('error');
    } else if (data.mail_connection === 'syncing') {
      $('mailStatus').textContent = 'Đang có yêu cầu đọc thư khác; sẽ tự kiểm tra lại.';
    } else {
      $('mailStatus').textContent = 'Đã kiểm tra hộp thư IMAP: ' + new Date().toLocaleTimeString('vi-VN') + ' · Tự kiểm tra mỗi 5 giây';
    }
  } catch (error) {
    if (error.name !== 'AbortError' && revision === state.revision) {
      retry = ![400, 401, 403, 404, 422].includes(error.status);
      state.connected = retry;
      nextDelay = state.retryDelay;
      state.retryDelay = Math.min(30000, state.retryDelay * 2);
      $('mailStatus').textContent = (error.message || 'Không kết nối được máy chủ.') + (retry ? ' · Sẽ tự thử lại.' : '');
      $('mailStatus').classList.add('error');
    }
  } finally {
    if (revision === state.revision) {
      state.request = null; $('refreshMail').disabled = !state.ready;
      if (retry && !document.hidden) state.timer = setTimeout(loadMail, nextDelay);
    }
  }
}
async function generate(event) {
  event.preventDefault();
  if (!state.ready || state.loading) return;
  state.loading = true; $('generateButton').disabled = true; $('generateButton').textContent = 'Đang tạo…';
  try {
    const data = await api('/api/v1/generator/email', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
      body: JSON.stringify({ domain: $('domain').value.trim(), type: $('generatorType').value, prefix: $('prefix').value })
    });
    state.address = data.email;
    $('generatedEmail').textContent = data.email;
    $('copyEmail').disabled = false;
    $('viewEmail').value = data.email;
    resetMessage();
    await loadMail();
    toast('Đã tạo địa chỉ mới.');
  } catch (error) { toast(error.message || 'Không tạo được địa chỉ.'); }
  finally { state.loading = false; $('generateButton').disabled = false; $('generateButton').textContent = 'Tạo mới'; }
}
$('generateForm').addEventListener('submit', generate);
$('generatorType').addEventListener('change', () => {
  const custom = $('generatorType').value === 'custom_prefix';
  $('prefixField').hidden = !custom; $('prefix').required = custom;
});
$('copyEmail').addEventListener('click', () => copy(state.address));
$('copyOtp').addEventListener('click', () => copy(state.otp));
$('refreshMail').addEventListener('click', loadMail);
$('viewEmail').addEventListener('input', () => { resetMessage(); $('refreshMail').disabled = !state.ready || !$('viewEmail').value.trim(); });
$('viewEmail').addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); loadMail(); } });
document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(state.timer); else if (state.connected && state.ready) loadMail(); });
async function initialize() {
  try {
    const session = await api('/api/v1/auth/session');
    if (session.must_change_password) { window.location.replace('/change-password.html'); return; }
    state.csrf = session.csrf_token;
    const settings = await api('/api/v1/settings');
    const domains = settings.default_domains?.length ? settings.default_domains : [settings.default_domain];
    $('domain').replaceChildren(...domains.map(domain => {
      const option = document.createElement('option');
      option.value = domain; option.textContent = domain;
      return option;
    }));
    $('domain').value = settings.default_domain;
    $('domain').disabled = false;
    $('generatorType').value = settings.generator_type;
    $('prefix').value = settings.default_prefix || '';
    $('generatorType').dispatchEvent(new Event('change'));
    applyTheme(settings.appearance);
    state.ready = true;
    $('generateButton').disabled = false;
    $('refreshMail').disabled = false;
  } catch (error) { $('mailStatus').textContent = 'Không tải được cấu hình. Hãy tải lại trang.'; toast(error.message); }
}
initialize();
