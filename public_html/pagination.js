'use strict';
const pagination = {
  pageSize: 20,
  render(container, {page, total, totalPages, onChange, disabled = false, pageSize = pagination.pageSize}) {
    const focusedLabel = container.contains(document.activeElement) ? document.activeElement.getAttribute('aria-label') : null;
    const controls = document.createElement('div'); controls.className = 'pagination-controls';
    function button(label, target, unavailable, ariaLabel) {
      const el = document.createElement('button');
      el.type = 'button'; el.textContent = label; el.disabled = unavailable;
      if (disabled) el.setAttribute('aria-disabled', 'true');
      el.setAttribute('aria-label', ariaLabel);
      if (target === page && /^\d+$/.test(label)) el.setAttribute('aria-current', 'page');
      el.addEventListener('click', () => { if (!disabled && target !== page) onChange(target); });
      controls.append(el);
    }
    button('Trước', page - 1, page <= 1, 'Trang trước');
    const pages = totalPages <= 7
      ? Array.from({length: totalPages}, (_, index) => index + 1)
      : [...new Set([1, page - 1, page, page + 1, totalPages])].filter(value => value >= 1 && value <= totalPages).sort((a, b) => a - b);
    let previous = 0;
    for (const number of pages) {
      if (previous && number - previous > 1) {
        const gap = document.createElement('span'); gap.textContent = '…'; gap.setAttribute('aria-hidden', 'true'); controls.append(gap);
      }
      button(String(number), number, false, 'Trang ' + number);
      previous = number;
    }
    button('Sau', page + 1, page >= totalPages, 'Trang sau');
    const summary = document.createElement('span'); summary.className = 'pagination-summary'; summary.setAttribute('role', 'status');
    summary.textContent = total ? `Hiển thị ${(page - 1) * pageSize + 1}–${Math.min(page * pageSize, total)} / ${total}` : 'Chưa có dữ liệu';
    container.replaceChildren(controls, summary);
    if (focusedLabel) {
      const match = [...controls.querySelectorAll('button')].find(el => el.getAttribute('aria-label') === focusedLabel && !el.disabled);
      (match || controls.querySelector('[aria-current="page"]'))?.focus();
    }
  }
};
