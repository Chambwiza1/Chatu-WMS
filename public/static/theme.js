/* Chatu Warehouse 0.4.0 (core 1.3, C1; the same file as ChatuPass): light or dark. The person's choice is kept on their account (light, dark, or
   follow this device); a device may override it. Runs before the page paints. */
(function () {
  'use strict';
  var KEY = 'chatu.theme';
  var root = document.documentElement;
  function device() { try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; } }
  function pick() {
    var dev = device();
    var want = dev || root.getAttribute('data-theme-pref') || 'auto';
    var dark = want === 'dark' || (want === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-theme-choice', want);
    root.setAttribute('data-theme-from', dev ? 'device' : 'account');
  }
  pick();
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    if (mq.addEventListener) mq.addEventListener('change', pick);
  }
  window.ChatuTheme = {
    /** '' hands this device back to the account's choice. */
    setDevice: function (want) { try { if (want) localStorage.setItem(KEY, want); else localStorage.removeItem(KEY); } catch (e) { /* storage blocked */ } pick(); },
    setAccount: function (want) { root.setAttribute('data-theme-pref', want); pick(); },
    choice: function () { return root.getAttribute('data-theme-choice') || 'auto'; },
    from: function () { return root.getAttribute('data-theme-from') || 'account'; }
  };
})();
