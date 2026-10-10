'use strict';
const $ = id => document.getElementById(id);
let latest = null;
let installing = false;
function status(message, type = '') { $('updateState').textContent = message; $('updateState').className = 'update-state' + (type ? ' ' + type : ''); }
function releaseLink(release) {
  $('releaseLink').hidden = true;
  if (!release) return;
  try {
    const url = new URL(release.release_url);
    if (url.origin !== 'https://github.com' || !/^\/hgn389\/Quick-Otp-Email-Check-PHP\/releases\/tag\/v\d+\.\d+\.\d+(?:-beta[-_][1-9]\d*)?$/.test(url.pathname) || url.search || url.hash || url.username || url.password) return;
    $('releaseLink').href = url.href;
    $('releaseLink').hidden = false;
  } catch (_) {}
}
async function checkUpdates() {
  if (installing) return;
  $('checkUpdateBtn').disabled = true; $('installUpdateBtn').disabled = true; latest = null;
  status(i18n.t('Đang kiểm tra bản PHP trên GitHub…'));
  try {
    const data = await workspace.api('/api/v1/system/update');
    latest = data.latest;
    releaseLink(latest);
    if (data.update_available && latest) {
      status(data.can_install ? `Có bản PHP mới ${latest.version}. Nhập mật khẩu Admin và xác nhận để cập nhật.` : i18n.t(`Có bản PHP mới ${latest.version}. ${data.install_reason}`), data.can_install ? 'success' : 'error');
      $('installUpdateBtn').disabled = !data.can_install;
      $('installUpdateBtn').textContent = i18n.t('Cập nhật ngay');
    } else {
      status(i18n.t(`Đang dùng ${data.current}. Chưa có bản PHP mới hơn.`), 'success');
      $('installUpdateBtn').textContent = i18n.t('Chưa có bản mới hơn');
    }
  } catch (error) { status(error.message, 'error'); }
  finally { $('checkUpdateBtn').disabled = false; }
}
$('checkUpdateBtn').addEventListener('click', checkUpdates);
$('updateInstallForm').addEventListener('submit', async event => {
  event.preventDefault();
  if (installing || !latest || $('installUpdateBtn').disabled || !$('updateConfirm').checked) return;
  const version = latest.number;
  installing = true;
  $('installUpdateBtn').disabled = true;
  $('checkUpdateBtn').disabled = true;
  $('updatePassword').disabled = true;
  $('updateConfirm').disabled = true;
  status(i18n.t('Đang tải và kiểm tra gói cập nhật. Website chỉ tạm bảo trì khi sao lưu và thay mã nguồn…'));
  try {
    const result = await workspace.api('/api/v1/system/update/install', {method: 'POST', body: JSON.stringify({version, confirmation: 'UPDATE', current_password: $('updatePassword').value})});
    status(i18n.t(`Đã cập nhật ${result.version}. Bản phục hồi: quickotp-private/${result.recovery_directory}. Đang tải lại trang…`), 'success');
    setTimeout(() => window.location.reload(), 1500);
  } catch (error) {
    // A timeout may hide a completed update. Check status without repeating the POST.
    try {
      const current = await workspace.api('/api/v1/system/status');
      if (current.version === `v${version}-php`) {
        status(i18n.t(`Đã cập nhật ${current.version}. Đang tải lại trang…`), 'success');
        setTimeout(() => window.location.reload(), 1500);
        return;
      }
    } catch (_) {}
    status(error.message, 'error');
    installing = false;
    $('checkUpdateBtn').disabled = false;
    $('updatePassword').disabled = false;
    $('updateConfirm').disabled = false;
    $('updateConfirm').checked = false;
    // Require a fresh version check before another installation attempt.
    latest = null;
  } finally {
    $('updatePassword').value = '';
  }
});
workspace.ready.then(async ready => {
  if (!ready) return;
  try {
    const data = await workspace.api('/api/v1/system/status');
    $('systemVersion').textContent = data.version;
    $('systemRuntime').textContent = `PHP ${data.php_version} · ${data.database} ${data.database_version}`;
    $('systemUptime').textContent = new Date(data.installed_at).toLocaleString(i18n.locale);
    $('systemRepository').textContent = data.github_repository;
    await checkUpdates();
  } catch (error) { status(error.message, 'error'); }
});
