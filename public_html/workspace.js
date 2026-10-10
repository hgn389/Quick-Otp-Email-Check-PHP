'use strict';
const workspace = {
  csrfToken: '', session: null, settings: null, mail: null,
  async api(path, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.method && !['GET','HEAD'].includes(options.method)) headers['X-CSRF-Token'] = workspace.csrfToken;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers, cache: 'no-store' });
    if (response.status === 401) {
      const data = await response.json();
      // A wrong current password is a form error; the session is still valid.
      if (['/api/v1/auth/password', '/api/v1/system/update/install'].includes(path) && data.error === 'current password is incorrect') throw new Error('Mật khẩu hiện tại không đúng.');
      window.location.replace('/login.html'); throw new Error('Phiên đăng nhập đã hết hạn.');
    }
    if (response.status === 204) return null;
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Không xử lý được yêu cầu.');
    return data;
  },
  toast(message) {
    const el = document.getElementById('toast'); el.textContent = message; el.classList.add('show');
    clearTimeout(workspace.toastTimer); workspace.toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
  },
  appearance(value) {
    value = ['dark', 'light', 'system'].includes(value) ? value : 'dark';
    workspace.theme = value;
    document.body.classList.toggle('dark', value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches));
    try { localStorage.setItem('quickotp-theme', value); } catch (_) {}
  },
  async copy(value) {
    try {
      if (window.isSecureContext && navigator.clipboard) await navigator.clipboard.writeText(value);
      else {
        const input = document.createElement('textarea'); input.value = value; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.appendChild(input); input.focus(); input.select(); input.setSelectionRange(0, value.length);
        const copied = document.execCommand('copy'); input.remove(); if (!copied) throw new Error();
      }
      workspace.toast('Đã Copy');
    } catch (_) { workspace.toast('Không copy được. Hãy nhấn giữ địa chỉ để sao chép.'); }
  }
};
try { workspace.appearance(localStorage.getItem('quickotp-theme') || 'dark'); } catch (_) { workspace.appearance('dark'); }
document.getElementById('menuToggle').addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));
document.querySelector('.profile .dots').addEventListener('click', () => document.getElementById('sidebar').classList.remove('open'));
document.addEventListener('click', event => { const sidebar = document.getElementById('sidebar'); if (window.innerWidth <= 760 && !sidebar.contains(event.target) && !document.getElementById('menuToggle').contains(event.target)) sidebar.classList.remove('open'); });
document.getElementById('themeToggle').addEventListener('click', () => workspace.appearance(document.body.classList.contains('dark') ? 'light' : 'dark'));
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (workspace.theme === 'system') workspace.appearance('system'); });
window.addEventListener('storage', event => { if (event.key === 'quickotp-theme') workspace.appearance(event.newValue || 'dark'); });
document.getElementById('logoutBtn').addEventListener('click', async () => { try { await workspace.api('/api/v1/auth/logout', {method:'POST'}); } finally { window.location.replace('/login.html'); } });
workspace.ready = (async () => {
  try {
    const session = await workspace.api('/api/v1/auth/session');
    if (session.must_change_password) { window.location.replace('/change-password.html'); return false; }
    workspace.session = session; workspace.csrfToken = session.csrf_token;
    [workspace.settings, workspace.mail] = await Promise.all([workspace.api('/api/v1/settings'), workspace.api('/api/v1/settings/mail')]);
    workspace.appearance(workspace.settings.appearance);
    return true;
  } catch (error) { workspace.toast(error.message); return false; }
})();
