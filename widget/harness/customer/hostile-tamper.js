// SS-03 prototype tampering — the host page breaks the platform on purpose; the loader
// must not depend on any of it (it holds no references to these before the tampering
// because the tampering runs first).
Array.prototype.map = function () { throw new Error('host tampered Array.prototype.map'); };
Object.freeze = function () { throw new Error('host tampered Object.freeze'); };
JSON.stringify = function () { throw new Error('host tampered JSON.stringify'); };
window.Promise.prototype.then = window.Promise.prototype.then; // left intact: NFR-05's support-matrix probe reads Promise
document.querySelector = function () { throw new Error('host tampered document.querySelector'); };
window.__harnessMutations = [];
new MutationObserver((list) => { for (const m of list) { for (const n of m.addedNodes) window.__harnessMutations.push((n.nodeName || '') + '#' + (n.id || '')); } }).observe(document.documentElement, { childList: true, subtree: true });
