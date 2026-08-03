// 后台交互脚本
(function () {
  'use strict';

  // 弹窗关闭:点击遮罩关闭
  document.addEventListener('click', function (e) {
    var masks = document.querySelectorAll('.modal-mask');
    masks.forEach(function (mask) {
      if (e.target === mask) {
        mask.style.display = 'none';
      }
    });
  });

  // Esc 关闭弹窗 / 关闭移动端菜单
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-mask').forEach(function (m) { m.style.display = 'none'; });
      closeMobileMenu();
    }
  });

  // 移动端侧边栏菜单(汉堡按钮 + 抽屉式滑出)
  var side = document.querySelector('.admin-side');
  var mask = document.querySelector('.menu-mask');
  var toggle = document.querySelector('.menu-toggle');

  function openMobileMenu() {
    if (side) side.classList.add('menu-open');
    if (mask) mask.classList.add('menu-open');
  }
  function closeMobileMenu() {
    if (side) side.classList.remove('menu-open');
    if (mask) mask.classList.remove('menu-open');
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      if (side && side.classList.contains('menu-open')) {
        closeMobileMenu();
      } else {
        openMobileMenu();
      }
    });
  }
  if (mask) {
    mask.addEventListener('click', closeMobileMenu);
  }
  // 侧边栏内的链接被点击后(移动端)自动关闭菜单
  if (side) {
    side.addEventListener('click', function (e) {
      var link = e.target.closest('a');
      if (link && window.innerWidth <= 900) {
        closeMobileMenu();
      }
    });
  }

  // 代码用法说明 —— HTML/CSS/JS 三标签切换
  var tabGroups = document.querySelectorAll('[data-tabs]');
  tabGroups.forEach(function (group) {
    var tabs = group.querySelectorAll('.usage-tab');
    // 找到与该组相邻的 .code-panels
    var panels = group.parentElement.querySelectorAll('.code-panels .code-block');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var key = tab.dataset.tab;
        tabs.forEach(function (b) { b.classList.toggle('active', b === tab); });
        panels.forEach(function (p) { p.classList.toggle('active', p.dataset.tab === key); });
      });
    });
  });
})();