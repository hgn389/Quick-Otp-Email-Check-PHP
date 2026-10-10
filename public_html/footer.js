'use strict';
(async () => {
  const label = document.getElementById('appVersion');
  if (!label) return;
  try {
    const response = await fetch('/api/v1/system/status', {headers:{Accept:'application/json'}, cache:'no-store'});
    if (!response.ok) throw new Error('Version unavailable');
    const data = await response.json();
    if (typeof data.version !== 'string' || !data.version) throw new Error('Version unavailable');
    label.textContent = data.version;
  } catch (_) { label.textContent = i18n.t('Phiên bản chưa khả dụng'); }
})();
