/**
 * GemVerify Login Controller
 * Shared login & logout logic for both User and Admin portals
 */

(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('gv-login-overlay');
    if (!overlay) return;

    /* Tab switching */
    window.gvSwitchTab = function (tab) {
      ['login', 'signup'].forEach(function (t) {
        var tabBtn = document.getElementById('gv-tab-' + t);
        var panel  = document.getElementById('gv-panel-' + t);
        if (tabBtn) tabBtn.classList.toggle('active', t === tab);
        if (panel)  panel.classList.toggle('active', t === tab);
      });
    };

    /* Login submit */
    window.gvDoLogin = function () {
      var emailEl    = document.getElementById('gv-email');
      var passEl     = document.getElementById('gv-password');
      var errEl      = document.getElementById('gv-login-err');

      var email    = emailEl ? emailEl.value.trim() : '';
      var password = passEl ? passEl.value : '';

      if (!email || !password) {
        if (errEl) {
          errEl.textContent = 'Please enter your email and password.';
          errEl.classList.add('show');
        }
        return;
      }
      if (errEl) errEl.classList.remove('show');
      gvProceedToApp();
    };

    /* Signup submit */
    window.gvDoSignup = function () {
      var fnameEl    = document.getElementById('gv-fname');
      var lnameEl    = document.getElementById('gv-lname');
      var emailEl    = document.getElementById('gv-su-email');
      var phoneEl    = document.getElementById('gv-su-phone');
      var passEl     = document.getElementById('gv-su-password');
      var confirmEl  = document.getElementById('gv-su-confirm');
      var errEl      = document.getElementById('gv-signup-err');

      var fname    = fnameEl ? fnameEl.value.trim() : '';
      var lname    = lnameEl ? lnameEl.value.trim() : '';
      var email    = emailEl ? emailEl.value.trim() : '';
      var phone    = phoneEl ? phoneEl.value.trim() : '';
      var password = passEl ? passEl.value : '';
      var confirm  = confirmEl ? confirmEl.value : '';

      if (!fname || !lname || !email || !phone || !password) {
        if (errEl) {
          errEl.textContent = 'Please fill in all required fields.';
          errEl.classList.add('show');
        }
        return;
      }
      if (password !== confirm) {
        if (errEl) {
          errEl.textContent = 'Passwords do not match.';
          errEl.classList.add('show');
        }
        return;
      }
      if (errEl) errEl.classList.remove('show');
      gvProceedToApp();
    };

    /* Dismiss overlay and hand control to main page/app */
    function gvProceedToApp() {
      window.__GV_AUTHED__ = true;
      try { sessionStorage.setItem('gv_authed', 'true'); } catch (e) {}

      overlay.classList.add('gv-exit');
      setTimeout(function () {
        overlay.style.display = 'none';

        /* Show user dashboard logout button if present */
        var userLogoutBtn = document.getElementById('gv-user-header-logout');
        if (userLogoutBtn) userLogoutBtn.style.display = 'flex';

        /* If Admin portal JS login() exists, call it */
        if (typeof window.login === 'function') {
          window.login();
        }

        /* If React setter was exposed in User portal, trigger it */
        if (typeof window.__gvSetLoggedIn === 'function') {
          window.__gvSetLoggedIn(true);
        }
      }, 340);
    }

    /* User Portal Logout function */
    window.gvUserLogout = function () {
      window.__GV_AUTHED__ = false;
      try { sessionStorage.removeItem('gv_authed'); } catch (e) {}

      /* Hide user dashboard logout button */
      var userLogoutBtn = document.getElementById('gv-user-header-logout');
      if (userLogoutBtn) userLogoutBtn.style.display = 'none';

      /* Show overlay again */
      if (overlay) {
        overlay.classList.remove('gv-exit');
        overlay.style.display = 'flex';
      }

      /* Trigger React setLoggedIn if exposed, or reload to reset state */
      if (typeof window.__gvSetLoggedIn === 'function') {
        window.__gvSetLoggedIn(false);
      } else {
        window.location.reload();
      }
    };
  });
})();
