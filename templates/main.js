// 落地页脚本 —— 点击复制联系方式
(function () {
  'use strict';

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(showToast, function () { fallbackCopy(text); });
    } else {
      fallbackCopy(text);
    }
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); showToast(); } catch (e) {}
    document.body.removeChild(ta);
  }

  function showToast() {
    var toast = document.getElementById('copyToast');
    if (!toast) return;
    toast.style.display = 'block';
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toast.style.display = 'none'; }, 1800);
  }

  window.copyText = copyText;
})();
