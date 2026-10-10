'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
for (const language of ['en', 'vi']) {
  const context = vm.createContext({
    localStorage: {getItem: () => language},
    NodeFilter: {SHOW_TEXT: 4},
    document: {documentElement:{}, createTreeWalker: () => ({nextNode:()=>false}), querySelectorAll:()=>[]},
    window: {addEventListener() {}},
  });
  vm.runInContext(fs.readFileSync('public_html/i18n.js', 'utf8'), context);
  function translate(text) { return vm.runInContext(`i18n.t(${JSON.stringify(text)})`, context); }
  assert.equal(translate('Settings'), language === 'en' ? 'Settings' : 'Cài đặt');
  assert.equal(translate('HỌ TÊN'), language === 'en' ? 'FULL NAME' : 'HỌ TÊN');
  assert.equal(translate('  Đang tải… '), language === 'en' ? '  Loading… ' : '  Đang tải… ');
  assert.equal(translate('Hiển thị 1–20 / 25'), language === 'en' ? 'Showing 1–20 / 25' : 'Hiển thị 1–20 / 25');
  assert.equal(translate('toString'), 'toString');
  assert.equal(translate('person@example.com'), 'person@example.com');
  const folder = 'Inbox (archive) $& ${data.messages}';
  const text = `Kiểm tra thành công: ${folder} có 12 thư. Cấu hình chưa lưu cần bấm Lưu hoặc Thêm Email Config.`;
  const expected = language === 'en' ? `Test successful: ${folder} contains 12 messages. Click Save or Add Email Config to save changes.` : text;
  assert.equal(translate(text), expected);
  assert.equal(vm.runInContext('i18n.locale', context), language === 'en' ? 'en-US' : 'vi-VN');
}
console.log('PASS: English/Vietnamese labels, dynamic pagination, whitespace and interpolated values, safe unknown keys and browser locale.');
