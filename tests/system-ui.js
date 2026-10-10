'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = new Map();
const context = vm.createContext({
  URL,
  i18n: {t: value => value},
  document: {getElementById(id) {
    if (!elements.has(id)) elements.set(id, {hidden: true, href: '', addEventListener() {}});
    return elements.get(id);
  }},
  workspace: {ready: Promise.resolve(false)},
});
vm.runInContext(fs.readFileSync('public_html/system.js', 'utf8'), context);
const base = 'https://github.com/hgn389/Quick-Otp-Email-Check-PHP/releases/tag/';
for (const version of ['v1.0.0', 'v1.0.0-beta-3', 'v1.0.0-beta_1', 'v1.0.0-beta-10']) {
  const url = base + version;
  vm.runInContext(`releaseLink(${JSON.stringify({release_url: url})})`, context);
  assert.equal(elements.get('releaseLink').hidden, false, version);
  assert.equal(elements.get('releaseLink').href, url);
}
for (const url of [
  base + 'v1.0.0-beta-0', base + 'v1.0.0-beta-01', base + 'v1.0.0-rc1',
  base + 'v1.0.0-beta-3?redirect=1', base + 'v1.0.0-beta-3#fragment',
  base.replace('github.com', 'github.com.example') + 'v1.0.0-beta-3',
  base.replace('https://', 'https://user:password@') + 'v1.0.0-beta-3',
  base.replace('hgn389/', 'another-owner/') + 'v1.0.0-beta-3',
  'javascript:alert(1)', '',
]) {
  vm.runInContext(`releaseLink(${JSON.stringify({release_url: url})})`, context);
  assert.equal(elements.get('releaseLink').hidden, true, url);
}
vm.runInContext('releaseLink(null)', context);
assert.equal(elements.get('releaseLink').hidden, true);
console.log('PASS: update links accept stable and both beta spellings and reject invalid or external release URLs.');
