/* TAP CMS — applies texts, images, links and works from the dashboard (api/content.php)
   and adds the DE / EN / AR language switcher. German is the default language. */
(function () {
    var LANGS = ['de', 'en', 'ar'];
    var lang = 'de';
    try {
        var q = new URLSearchParams(location.search).get('lang');
        lang = LANGS.indexOf(q) >= 0 ? q : (localStorage.getItem('tap_lang') || 'de');
        if (LANGS.indexOf(lang) < 0) lang = 'de';
        if (q) localStorage.setItem('tap_lang', lang);
    } catch (e) {}

    var html = document.documentElement;
    html.setAttribute('lang', lang);
    html.classList.add('lang-' + lang);
    // Hide the page briefly while a non-German language loads, to avoid a flash of German text
    if (lang !== 'de') {
        html.classList.add('cms-loading');
        setTimeout(function () { html.classList.remove('cms-loading'); }, 1500);
    }

    var css = document.createElement('style');
    css.textContent =
        'html.cms-loading body{opacity:0}body{transition:opacity .25s}' +
        '.lang-ar [data-i18n]{letter-spacing:0!important;font-family:"Cairo",var(--font-main),sans-serif!important}' +
        '.lang-ar p[data-i18n],.lang-ar .hero-sub,.lang-ar .about-description{direction:rtl}' +
        '.tap-lang{display:inline-flex;gap:4px;margin:0 10px;align-items:center}' +
        '.tap-lang a{font:600 11px/1 var(--font-main,sans-serif)!important;letter-spacing:.05em!important;padding:6px 8px;border-radius:14px;border:1px solid rgba(255,255,255,.18);color:rgba(255,255,255,.7)!important;text-decoration:none}' +
        '.tap-lang a.on{background:var(--purple,#8B5CF6);border-color:var(--purple,#8B5CF6);color:#fff!important}' +
        '.mobile-menu .tap-lang{margin:20px 0 0;justify-content:center}.mobile-menu .tap-lang a{font-size:14px!important;padding:8px 12px}';
    document.head.appendChild(css);
    if (lang === 'ar') {
        var f = document.createElement('link');
        f.rel = 'stylesheet';
        f.href = 'https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap';
        document.head.appendChild(f);
    }

    var data = { texts: { de: {}, en: {}, ar: {} }, images: {}, links: {}, collections: {} };
    var originals = {};

    function has(o, k) { return o && Object.prototype.hasOwnProperty.call(o, k) && o[k] !== '' && o[k] != null; }

    // Text for a key in the current language: lang → German (dashboard) → fallback (HTML original)
    function t(key, fallback) {
        var tx = data.texts || {};
        if (has(tx[lang], key)) return tx[lang][key];
        if (has(tx.de, key)) return tx.de[key];
        return fallback;
    }
    // Translate a {de,en,ar} object (used by works / collections)
    function tr(obj) {
        if (obj == null) return '';
        if (typeof obj === 'string') return obj;
        return has(obj, lang) ? obj[lang] : (obj.de || obj.en || obj.ar || '');
    }

    function apply() {
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            var k = el.getAttribute('data-i18n');
            if (!(k in originals)) originals[k] = el.innerHTML;
            el.innerHTML = t(k, originals[k]);
        });
        document.querySelectorAll('[data-i18n-ph]').forEach(function (el) {
            var k = el.getAttribute('data-i18n-ph');
            if (!(('ph:' + k) in originals)) originals['ph:' + k] = el.getAttribute('placeholder') || '';
            el.setAttribute('placeholder', t(k, originals['ph:' + k]));
        });
        document.querySelectorAll('[data-img]').forEach(function (el) {
            var k = el.getAttribute('data-img');
            if (has(data.images, k)) el.setAttribute('src', data.images[k]);
        });
        document.querySelectorAll('[data-link]').forEach(function (el) {
            var k = el.getAttribute('data-link');
            if (has(data.links, k)) el.setAttribute('href', data.links[k]);
        });
    }

    function switcher() {
        var make = function () {
            var box = document.createElement('div');
            box.className = 'tap-lang';
            LANGS.forEach(function (l) {
                var a = document.createElement('a');
                a.href = '?lang=' + l + location.hash;
                a.textContent = l.toUpperCase();
                if (l === lang) a.className = 'on';
                box.appendChild(a);
            });
            return box;
        };
        var social = document.querySelector('#navbar .nav-social');
        if (social) social.insertBefore(make(), social.firstChild);
        document.querySelectorAll('.mobile-menu').forEach(function (m) {
            (m.querySelector('.mobile-menu-inner') || m).appendChild(make());
        });
    }

    var ready = new Promise(function (resolve) {
        var load = fetch('api/content.php', { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : {}; })
            .catch(function () { return {}; });
        var dom = new Promise(function (res) {
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', res);
            else res();
        });
        Promise.all([load, dom]).then(function (r) {
            var d = r[0] || {};
            data.texts = d.texts || data.texts;
            data.images = d.images || {};
            data.links = d.links || {};
            data.collections = d.collections || {};
            apply();
            switcher();
            html.classList.remove('cms-loading');
            resolve(data);
        });
    });

    window.TAP_CMS = {
        lang: lang,
        ready: ready,
        t: t,
        tr: tr,
        // Works of a collection, or null if the dashboard has none saved yet
        collection: function (name) {
            var c = data.collections && data.collections[name];
            return Array.isArray(c) ? c : null;
        }
    };
})();
