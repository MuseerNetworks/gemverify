/**
 * GemVerify Theme Manager
 * Dark mode toggle & persistence across sessions
 */

(function () {
  function applyTheme(isDark) {
    if (isDark) {
      document.body.classList.add('dark-mode');
      document.documentElement.classList.add('dark');
    } else {
      document.body.classList.remove('dark-mode');
      document.documentElement.classList.remove('dark');
    }
    var toggleBtn = document.getElementById('darkToggle');
    if (toggleBtn) {
      toggleBtn.textContent = isDark ? '☀' : '☾';
    }
  }

  window.toggleDarkMode = function () {
    var isDark = !document.body.classList.contains('dark-mode');
    applyTheme(isDark);
    try {
      localStorage.setItem('gv-theme', isDark ? 'dark' : 'light');
    } catch (e) {}
  };

  document.addEventListener('DOMContentLoaded', function () {
    try {
      var saved = localStorage.getItem('gv-theme');
      if (saved === 'dark') {
        applyTheme(true);
      }
    } catch (e) {}
  });
})();
