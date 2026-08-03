<?php
// 后台公共尾部
?>
    </main>
  </div>
</div>
<div class="menu-mask"></div>
<script src="../assets/js/admin.js"></script>
<script>
// 自动给页面中所有 .alert 加关闭按钮 + 4 秒后自动淡出(全局 UX 修复)
(function () {
  function dismissAlert(el) {
    el.classList.add('fade-out');
    setTimeout(function () { el.style.display = 'none'; }, 260);
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert').forEach(function (el) {
      // 已在的不重复加
      if (el.querySelector('.alert-close')) return;
      var btn = document.createElement('button');
      btn.className = 'alert-close';
      btn.type = 'button';
      btn.setAttribute('aria-label', '关闭提示');
      btn.textContent = '×';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        dismissAlert(el);
      });
      el.appendChild(btn);
      // 4 秒后自动消失(error 类型延长到 6 秒,给用户更多阅读时间)
      var ttl = el.classList.contains('error') ? 6000 : 4000;
      setTimeout(function () { dismissAlert(el); }, ttl);
    });
  });
})();
</script>
</body>
</html>
