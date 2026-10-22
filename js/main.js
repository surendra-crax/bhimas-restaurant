/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * main.js — Core utilities (no frameworks, plain ES6+)
 */

'use strict';

/* ============================================================
   1. NAVBAR — Scroll behaviour + hamburger toggle
   ============================================================ */
(function initNavbar() {
  const navbar   = document.querySelector('.navbar');
  const hamburger = document.querySelector('.navbar__hamburger');
  const mobileMenu = document.querySelector('.navbar__mobile-menu');
  const overlay  = document.querySelector('.overlay');

  if (!navbar) return;

  // Scroll: add .scrolled class after 60px
  function onScroll() {
    navbar.classList.toggle('scrolled', window.scrollY > 60);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll(); // run once on load

  // Hamburger toggle
  if (hamburger && mobileMenu) {
    hamburger.addEventListener('click', function () {
      const isOpen = mobileMenu.classList.toggle('open');
      hamburger.classList.toggle('open', isOpen);
      hamburger.setAttribute('aria-expanded', isOpen);
      if (overlay) overlay.classList.toggle('active', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
    });
  }

  // Close mobile menu on overlay click
  if (overlay) {
    overlay.addEventListener('click', closeMobileMenu);
  }

  // Close mobile menu when a link is clicked
  if (mobileMenu) {
    mobileMenu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeMobileMenu);
    });
  }

  function closeMobileMenu() {
    if (mobileMenu) mobileMenu.classList.remove('open');
    if (hamburger) {
      hamburger.classList.remove('open');
      hamburger.setAttribute('aria-expanded', 'false');
    }
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  // Highlight active nav link based on current page
  var currentPath = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.navbar__link, .navbar__mobile-link').forEach(function (link) {
    var href = link.getAttribute('href');
    if (href && href.split('/').pop() === currentPath) {
      link.classList.add('active');
    }
  });
})();


/* ============================================================
   2. CART SYSTEM — localStorage-backed
   ============================================================ */
var BhimasCart = (function () {

  var STORAGE_KEY = 'bhimas_cart';

  /** Read cart from localStorage */
  function _load() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
    } catch (e) {
      return [];
    }
  }

  /** Persist cart to localStorage */
  function _save(items) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) {
      console.warn('BhimasCart: could not save to localStorage', e);
    }
  }

  /**
   * Add item to cart or increment qty if already present.
   * @param {Object} item — { id, name, price, qty?, image?, category? }
   */
  function addItem(item) {
    if (!item || !item.id || !item.name || item.price == null) {
      console.warn('BhimasCart.addItem: invalid item', item);
      return;
    }
    var items = _load();
    var existing = items.find(function (i) { return i.id === item.id; });
    if (existing) {
      existing.qty = (existing.qty || 1) + (item.qty || 1);
    } else {
      items.push({
        id:       item.id,
        name:     item.name,
        price:    parseFloat(item.price),
        qty:      item.qty || 1,
        image:    item.image || '',
        category: item.category || ''
      });
    }
    _save(items);
    _emitChange();
  }

  /**
   * Remove item completely by id.
   */
  function removeItem(id) {
    var items = _load().filter(function (i) { return i.id !== id; });
    _save(items);
    _emitChange();
  }

  /**
   * Update quantity for an item. qty <= 0 removes the item.
   */
  function updateQty(id, qty) {
    qty = parseInt(qty, 10);
    if (isNaN(qty) || qty < 0) return;
    var items = _load();
    if (qty === 0) {
      items = items.filter(function (i) { return i.id !== id; });
    } else {
      var item = items.find(function (i) { return i.id === id; });
      if (item) item.qty = qty;
    }
    _save(items);
    _emitChange();
  }

  /** Get total item count (sum of all qtys) */
  function getCount() {
    return _load().reduce(function (sum, i) { return sum + (i.qty || 1); }, 0);
  }

  /** Get subtotal */
  function getTotal() {
    return _load().reduce(function (sum, i) {
      return sum + (i.price * (i.qty || 1));
    }, 0);
  }

  /** Get formatted total string e.g. "£24.50" */
  function getTotalFormatted(currency) {
    currency = currency || '£';
    return currency + getTotal().toFixed(2);
  }

  /** Get all items */
  function getItems() {
    return _load();
  }

  /** Clear cart entirely */
  function clear() {
    _save([]);
    _emitChange();
  }

  /** Dispatch a custom event so UI components can react */
  function _emitChange() {
    document.dispatchEvent(new CustomEvent('bhimas:cart:change', {
      detail: { count: getCount(), total: getTotal() }
    }));
  }

  // Public API
  return {
    addItem:             addItem,
    removeItem:          removeItem,
    updateQty:           updateQty,
    getCount:            getCount,
    getTotal:            getTotal,
    getTotalFormatted:   getTotalFormatted,
    getItems:            getItems,
    clear:               clear
  };

})();


/* ============================================================
   3. CART BAR + FLOATING FAB — show/hide based on cart count
   ============================================================ */
(function initCartBar() {
  var cartBar         = document.querySelector('.cart-bar');
  var countEls        = document.querySelectorAll('.cart-bar__count, .navbar__cart-count');
  var totalEl         = document.querySelector('.cart-bar__total');
  var cartFab         = document.getElementById('cart-fab');
  var cartFabCount    = document.getElementById('cart-fab-count');
  var mobileCartCount = document.getElementById('mobile-cart-count');

  function updateCartUI(count, total) {
    // Update navbar count badges
    countEls.forEach(function (el) {
      el.textContent = count;
    });

    // Update total
    if (totalEl) {
      totalEl.textContent = '€' + total.toFixed(2);
    }

    // Floating FAB — show when cart has items
    if (cartFab) {
      if (count > 0) {
        cartFab.classList.add('visible');
      } else {
        cartFab.classList.remove('visible');
      }
    }
    if (cartFabCount)    cartFabCount.textContent    = count;
    if (mobileCartCount) mobileCartCount.textContent = count;

    // Show/hide cart bar (takeaway page sticky bar)
    if (cartBar) {
      if (count > 0) {
        cartBar.classList.add('visible');
        document.body.classList.add('cart-bar-visible');
      } else {
        cartBar.classList.remove('visible');
        document.body.classList.remove('cart-bar-visible');
      }
    }
  }

  // Listen for cart changes
  document.addEventListener('bhimas:cart:change', function (e) {
    updateCartUI(e.detail.count, e.detail.total);
  });

  // Initialise on page load
  updateCartUI(BhimasCart.getCount(), BhimasCart.getTotal());
})();


/* ============================================================
   4. TOAST NOTIFICATION
   ============================================================ */
var BhimasToast = (function () {

  var container;
  var AUTO_DISMISS_MS = 4000;

  function _ensureContainer() {
    if (!container) {
      container = document.querySelector('.toast-container');
    }
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
    return container;
  }

  /**
   * Show a toast.
   * @param {string} message
   * @param {Object} opts — { title, type: 'success'|'error'|'info'|'gold', duration }
   */
  function show(message, opts) {
    opts = opts || {};
    var type     = opts.type     || 'info';
    var title    = opts.title    || _defaultTitle(type);
    var duration = opts.duration != null ? opts.duration : AUTO_DISMISS_MS;

    var c = _ensureContainer();

    var toast = document.createElement('div');
    toast.className = 'toast toast--' + type;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');

    toast.innerHTML =
      '<div class="toast__body">' +
        '<div class="toast__title">' + _escape(title) + '</div>' +
        '<div class="toast__msg">'   + _escape(message) + '</div>' +
      '</div>' +
      '<button class="toast__close" aria-label="Close">&times;</button>';

    c.appendChild(toast);

    // Close button
    toast.querySelector('.toast__close').addEventListener('click', function () {
      _dismiss(toast);
    });

    // Auto dismiss
    var timer;
    if (duration > 0) {
      timer = setTimeout(function () { _dismiss(toast); }, duration);
    }

    // Pause timer on hover
    toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
    toast.addEventListener('mouseleave', function () {
      if (duration > 0) timer = setTimeout(function () { _dismiss(toast); }, duration);
    });

    return toast;
  }

  function _dismiss(toast) {
    toast.classList.add('leaving');
    toast.addEventListener('animationend', function () {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    });
  }

  function _defaultTitle(type) {
    var map = { success: 'Done', error: 'Error', info: 'Notice', gold: 'Bhimas' };
    return map[type] || 'Notice';
  }

  function _escape(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  // Convenience shortcuts
  function success(msg, opts) { return show(msg, Object.assign({ type: 'success' }, opts)); }
  function error(msg, opts)   { return show(msg, Object.assign({ type: 'error'   }, opts)); }
  function info(msg, opts)    { return show(msg, Object.assign({ type: 'info'    }, opts)); }
  function gold(msg, opts)    { return show(msg, Object.assign({ type: 'gold'    }, opts)); }

  return { show: show, success: success, error: error, info: info, gold: gold };

})();


/* ============================================================
   5. FORM HELPERS
   ============================================================ */
var BhimasForms = (function () {

  /**
   * Display a success message in a .form-message element.
   * @param {HTMLElement} formEl
   * @param {string} message
   */
  function showSuccess(formEl, message) {
    _showMessage(formEl, message, 'success');
  }

  /**
   * Display an error message in a .form-message element.
   */
  function showError(formEl, message) {
    _showMessage(formEl, message, 'error');
  }

  function _showMessage(formEl, message, type) {
    var msgEl = formEl.querySelector('.form-message');
    if (!msgEl) return;
    msgEl.textContent = message;
    msgEl.className = 'form-message form-message--' + type + ' show';
    msgEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /**
   * Set a button into loading state.
   * @param {HTMLButtonElement} btn
   * @param {string} loadingText — replaces btn text (stored in data-original-text)
   */
  function setLoading(btn, loadingText) {
    if (!btn) return;
    btn.dataset.originalText = btn.innerHTML;
    var span = btn.querySelector('.btn__text');
    if (span) {
      span.textContent = loadingText || 'Please wait…';
    } else {
      btn.innerHTML = '<span class="btn__text">' + (loadingText || 'Please wait…') + '</span>';
    }
    btn.classList.add('loading');
    btn.disabled = true;
  }

  /**
   * Restore button from loading state.
   */
  function resetLoading(btn) {
    if (!btn) return;
    if (btn.dataset.originalText) {
      btn.innerHTML = btn.dataset.originalText;
      delete btn.dataset.originalText;
    }
    btn.classList.remove('loading');
    btn.disabled = false;
  }

  /**
   * Validate a single input and show/clear inline error.
   * @param {HTMLInputElement} input
   * @param {Function} validator — returns error string or null
   */
  function validateField(input, validator) {
    var err = validator(input.value);
    var errEl = input.parentElement.querySelector('.form-error');
    if (err) {
      input.style.borderColor = 'var(--color-error)';
      if (errEl) { errEl.textContent = err; errEl.style.display = 'block'; }
      return false;
    } else {
      input.style.borderColor = '';
      if (errEl) { errEl.textContent = ''; errEl.style.display = 'none'; }
      return true;
    }
  }

  /**
   * Serialize all named form inputs to a plain object.
   */
  function serialize(formEl) {
    var data = {};
    new FormData(formEl).forEach(function (val, key) { data[key] = val; });
    return data;
  }

  return {
    showSuccess:   showSuccess,
    showError:     showError,
    setLoading:    setLoading,
    resetLoading:  resetLoading,
    validateField: validateField,
    serialize:     serialize
  };

})();


/* ============================================================
   6. SETTINGS — fetch from PHP backend
   ============================================================ */
var BhimasSettings = (function () {

  var _cache = null;

  /**
   * Fetch site settings from /php/get-settings.php
   * Returns a Promise that resolves to the settings object.
   * Results are cached for the page session.
   */
  function fetchSettings() {
    if (_cache) return Promise.resolve(_cache);

    return fetch('php/get-settings.php', {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
    .then(function (res) {
      if (!res.ok) throw new Error('Settings fetch failed: ' + res.status);
      return res.json();
    })
    .then(function (data) {
      if (data && data.success) {
        _cache = data.settings || {};
      } else {
        _cache = {};
      }
      return _cache;
    })
    .catch(function (err) {
      console.warn('BhimasSettings.fetchSettings error:', err);
      return {};
    });
  }

  /** Clear cached settings (e.g. after admin save) */
  function clearCache() { _cache = null; }

  return { fetchSettings: fetchSettings, clearCache: clearCache };

})();

// Convenience global alias
function fetchSettings() { return BhimasSettings.fetchSettings(); }


/* ============================================================
   7. TIME SLOT GENERATORS
   ============================================================ */

/**
 * Generate reservation time slots.
 * @param {Object} opts
 *   openTime    {string} "HH:MM" (default "12:00")
 *   closeTime   {string} "HH:MM" (default "22:00")
 *   intervalMin {number} minutes between slots (default 30)
 *   lastSlotMin {number} minutes before close to stop (default 60)
 * @returns {Array<{value:string, label:string}>}
 */
function generateTimeSlots(opts) {
  opts = opts || {};
  var open     = _parseTime(opts.openTime   || '12:00');
  var close    = _parseTime(opts.closeTime  || '22:00');
  var interval = opts.intervalMin || 30;
  var lastStop = (close.total - (opts.lastSlotMin || 60));
  var slots    = [];

  for (var t = open.total; t <= lastStop; t += interval) {
    slots.push({
      value: _formatTime(t),
      label: _formatLabel(t)
    });
  }
  return slots;
}

/**
 * Generate pickup time slots (typically tighter window).
 * @param {Object} opts — same as generateTimeSlots
 * @returns {Array<{value:string, label:string}>}
 */
function generatePickupSlots(opts) {
  opts = opts || {};
  opts.openTime    = opts.openTime    || '12:00';
  opts.closeTime   = opts.closeTime   || '22:00';
  opts.intervalMin = opts.intervalMin || 15;
  opts.lastSlotMin = opts.lastSlotMin || 30;
  return generateTimeSlots(opts);
}

/**
 * Populate a <select> element with time slots.
 * @param {HTMLSelectElement} selectEl
 * @param {Array} slots — from generateTimeSlots/generatePickupSlots
 * @param {string} placeholder — first empty option label
 */
function populateTimeSelect(selectEl, slots, placeholder) {
  if (!selectEl) return;
  selectEl.innerHTML = '';
  var defaultOpt = document.createElement('option');
  defaultOpt.value = '';
  defaultOpt.textContent = placeholder || 'Select a time';
  selectEl.appendChild(defaultOpt);

  slots.forEach(function (slot) {
    var opt = document.createElement('option');
    opt.value = slot.value;
    opt.textContent = slot.label;
    selectEl.appendChild(opt);
  });
}

// --- Internal time helpers ---
function _parseTime(str) {
  var parts = str.split(':');
  var h = parseInt(parts[0], 10);
  var m = parseInt(parts[1], 10) || 0;
  return { h: h, m: m, total: h * 60 + m };
}

function _formatTime(totalMin) {
  var h = Math.floor(totalMin / 60);
  var m = totalMin % 60;
  return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
}

function _formatLabel(totalMin) {
  var h = Math.floor(totalMin / 60);
  var m = totalMin % 60;
  var period = h >= 12 ? 'pm' : 'am';
  var h12 = h > 12 ? h - 12 : (h === 0 ? 12 : h);
  var mStr = m === 0 ? '' : ':' + (m < 10 ? '0' : '') + m;
  return h12 + mStr + ' ' + period;
}


/* ============================================================
   8. INTERSECTION OBSERVER — fade-in animation
   ============================================================ */
(function initFadeIn() {
  var els = document.querySelectorAll('.fade-in');
  if (!els.length) return;

  if (!('IntersectionObserver' in window)) {
    // Fallback: show all immediately
    els.forEach(function (el) { el.classList.add('visible'); });
    return;
  }

  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
  );

  els.forEach(function (el) { observer.observe(el); });
})();


/* ============================================================
   9. ADD-TO-CART BUTTONS — wires data-* attributes to cart
   ============================================================ */
(function initAddToCartButtons() {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-add-to-cart]');
    if (!btn) return;

    var id       = btn.dataset.itemId;
    var name     = btn.dataset.itemName;
    var price    = parseFloat(btn.dataset.itemPrice);
    var image    = btn.dataset.itemImage  || '';
    var category = btn.dataset.itemCategory || '';

    if (!id || !name || isNaN(price)) {
      console.warn('Add-to-cart: missing data attributes on', btn);
      return;
    }

    BhimasCart.addItem({ id: id, name: name, price: price, image: image, category: category, qty: 1 });
    BhimasToast.gold(name + ' added to your order.', { title: 'Added to Cart' });
  });
})();


/* ============================================================
   10. CSRF TOKEN HELPER
   ============================================================ */

/**
 * Internal cached token — populated by initCsrf() below.
 * Static .html pages cannot run PHP so the meta tag starts empty;
 * we fetch a real session token from php/get-csrf.php instead.
 */
var _bhimasCsrfToken = '';

/**
 * On page load, fetch a CSRF token from the PHP backend and cache it.
 * Also updates any hidden <input name="csrf_token"> and the meta tag.
 */
(function initCsrf() {
  fetch('php/get-csrf.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.token) {
        _bhimasCsrfToken = d.token;
        // Patch meta tag
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', d.token);
        // Patch any hidden csrf inputs already in the DOM
        document.querySelectorAll('input[name="csrf_token"]').forEach(function (el) {
          el.value = d.token;
        });
      }
    })
    .catch(function () {
      // PHP not reachable (pure static preview) — CSRF will be skipped server-side in dev mode
    });
})();

/**
 * Get the current CSRF token (meta tag or cached value).
 */
function getCsrfToken() {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var fromMeta = meta ? meta.getAttribute('content') : '';
  return fromMeta || _bhimasCsrfToken || '';
}

/**
 * Attach CSRF token to a fetch request's headers.
 * Usage: fetch(url, withCsrf({ method:'POST', body:... }))
 */
function withCsrf(init) {
  init = init || {};
  init.headers = Object.assign({}, init.headers, {
    'X-CSRF-Token':      getCsrfToken(),
    'X-Requested-With':  'XMLHttpRequest'
  });
  return init;
}


/* ============================================================
   11. SCROLL-TO-TOP BUTTON
   ============================================================ */

(function initScrollToTop() {
  // Create button element
  var btn = document.createElement('button');
  btn.className    = 'scroll-top';
  btn.setAttribute('aria-label', 'Scroll back to top');
  btn.setAttribute('title', 'Back to top');
  btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>';
  document.body.appendChild(btn);

  // Show after 300 px scroll
  var ticking = false;
  function onScroll() {
    if (!ticking) {
      window.requestAnimationFrame(function () {
        if (window.scrollY > 300) {
          btn.classList.add('visible');
        } else {
          btn.classList.remove('visible');
        }
        ticking = false;
      });
      ticking = true;
    }
  }

  window.addEventListener('scroll', onScroll, { passive: true });

  // Smooth scroll to top on click
  btn.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();


/* ============================================================
   12. FOOTER YEAR — auto-update all .footer-year spans
   ============================================================ */
(function initFooterYear() {
  var year = new Date().getFullYear();
  document.querySelectorAll('.footer-year').forEach(function (el) {
    el.textContent = year;
  });
})();


/* ============================================================
   13. PRODUCT DETAIL DRAWER
   Opens a slide-in panel populated from item card data-* attrs.
   ============================================================ */
(function initProductDrawer() {

  // ── Inject drawer HTML once ────────────────────────────────
  var drawerHtml = [
    '<div class="drawer-scrim" id="drawer-scrim"></div>',
    '<aside class="product-drawer" id="product-drawer" role="dialog"',
    '       aria-modal="true" aria-label="Product detail">',
    '  <button class="product-drawer__close" id="drawer-close" aria-label="Close">',
    '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"',
    '         stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/>',
    '      <line x1="6" y1="6" x2="18" y2="18"/></svg>',
    '  </button>',
    '  <div class="product-drawer__img-wrap">',
    '    <img class="product-drawer__img" id="drawer-img" src="" alt="">',
    '    <div class="product-drawer__badge" id="drawer-badge"></div>',
    '  </div>',
    '  <div class="product-drawer__body" id="drawer-body">',
    '    <span class="product-drawer__category" id="drawer-category"></span>',
    '    <h2 class="product-drawer__name" id="drawer-name"></h2>',
    '    <div class="product-drawer__divider"></div>',
    '    <p class="product-drawer__desc" id="drawer-desc"></p>',
    '    <div class="product-drawer__divider"></div>',
    '    <div class="product-drawer__price-row">',
    '      <span class="product-drawer__price" id="drawer-price"></span>',
    '    </div>',
    '  </div>',
    '  <div class="product-drawer__footer">',
    '    <button class="product-drawer__add-btn" id="drawer-add-btn">',
    '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"',
    '           stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/>',
    '        <line x1="5" y1="12" x2="19" y2="12"/></svg>',
    '      Add to Cart',
    '    </button>',
    '    <a href="menu.html" class="product-drawer__menu-link">Browse Full Menu &rarr;</a>',
    '  </div>',
    '</aside>'
  ].join('\n');

  var wrapper = document.createElement('div');
  wrapper.innerHTML = drawerHtml;
  while (wrapper.firstChild) document.body.appendChild(wrapper.firstChild);

  // ── Element refs ───────────────────────────────────────────
  var scrim      = document.getElementById('drawer-scrim');
  var drawer     = document.getElementById('product-drawer');
  var closeBtn   = document.getElementById('drawer-close');
  var drawerImg  = document.getElementById('drawer-img');
  var drawerBadge= document.getElementById('drawer-badge');
  var drawerCat  = document.getElementById('drawer-category');
  var drawerName = document.getElementById('drawer-name');
  var drawerDesc = document.getElementById('drawer-desc');
  var drawerPrice= document.getElementById('drawer-price');
  var addBtn     = document.getElementById('drawer-add-btn');

  // Current item data
  var _current = null;

  // ── Open / close ───────────────────────────────────────────
  function openDrawer(data) {
    _current = data;
    drawerImg.src   = data.image || '';
    drawerImg.alt   = data.name  || '';
    drawerCat.textContent  = data.category  || '';
    drawerName.textContent = data.name      || '';
    drawerDesc.textContent = data.desc      || '';
    drawerPrice.textContent = data.price ? '€' + parseFloat(data.price).toFixed(2) : '';

    // Diet badge
    drawerBadge.innerHTML = '';
    if (data.diet) {
      var isVeg = data.diet === 'veg';
      var dietEl = document.createElement('span');
      dietEl.className = 'product-drawer__diet product-drawer__diet--' + (isVeg ? 'veg' : 'nonveg');
      dietEl.innerHTML = '<span class="product-drawer__diet-dot"></span>' +
                         (isVeg ? 'Vegetarian' : 'Non-Veg');
      drawerBadge.appendChild(dietEl);
    }
    if (data.tags) {
      data.tags.split(',').forEach(function(tag) {
        tag = tag.trim();
        if (!tag) return;
        var el = document.createElement('span');
        el.className = 'product-drawer__tag';
        el.textContent = tag;
        drawerBadge.appendChild(el);
      });
    }

    // Reset add button
    addBtn.classList.remove('added');
    addBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add to Cart';

    drawer.classList.add('open');
    scrim.classList.add('open');
    document.body.style.overflow = 'hidden';
    closeBtn.focus();
  }

  function closeDrawer() {
    drawer.classList.remove('open');
    scrim.classList.remove('open');
    document.body.style.overflow = '';
    _current = null;
  }

  closeBtn.addEventListener('click', closeDrawer);
  scrim.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
  });

  // ── Add to cart from drawer ────────────────────────────────
  addBtn.addEventListener('click', function() {
    if (!_current) return;
    BhimasCart.addItem({
      id:       _current.id       || 'item-' + Date.now(),
      name:     _current.name     || '',
      price:    parseFloat(_current.price) || 0,
      qty:      1,
      category: _current.category || '',
      image:    _current.image    || ''
    });
    addBtn.classList.add('added');
    addBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg> Added to Cart';
    setTimeout(closeDrawer, 900);
  });

  // ── Read item data from card element ──────────────────────
  function readCardData(card) {
    // Try data-* attributes on the card itself first (homepage dish-items)
    var id       = card.dataset.itemId       || '';
    var name     = card.dataset.itemName     || '';
    var price    = card.dataset.itemPrice    || '';
    var category = card.dataset.itemCategory || '';
    var image    = card.dataset.itemImage    || '';
    var desc     = card.dataset.itemDesc     || '';
    var diet     = card.dataset.itemDiet     || '';
    var tags     = card.dataset.itemTags     || '';

    // If not on card, check the Add-to-Cart button inside (menu page item-cards)
    var btn = card.querySelector('[data-add-to-cart]');
    if (btn) {
      if (!id)       id       = btn.dataset.itemId       || '';
      if (!name)     name     = btn.dataset.itemName     || '';
      if (!price)    price    = btn.dataset.itemPrice    || '';
      if (!category) category = btn.dataset.itemCategory || '';
      if (!image)    image    = btn.dataset.itemImage    || '';
      if (!desc)     desc     = btn.dataset.itemDesc     || '';
      if (!diet)     diet     = btn.dataset.itemDiet     || '';
      if (!tags)     tags     = btn.dataset.itemTags     || '';
    }

    // Final DOM fallbacks
    if (!name) {
      var nameEl = card.querySelector('.item-card__name, .dish-item__name, h3, h2');
      if (nameEl) name = nameEl.textContent.trim();
    }
    if (!desc) {
      var descEl = card.querySelector('.item-card__desc, p');
      if (descEl) desc = descEl.textContent.trim();
    }
    if (!price) {
      var priceEl = card.querySelector('.item-card__price, .dish-item__price');
      if (priceEl) price = priceEl.textContent.replace(/[^\d.]/g, '');
    }
    if (!category) {
      var catEl = card.querySelector('.dish-item__category, .item-card__category');
      if (catEl) category = catEl.textContent.trim();
    }
    if (!image) {
      var imgEl = card.querySelector('img');
      if (imgEl) image = imgEl.src;
    }
    if (!diet) {
      if (card.querySelector('.item-card__dot--veg, [aria-label="Vegetarian"]'))     diet = 'veg';
      if (card.querySelector('.item-card__dot--nonveg, [aria-label="Non-vegetarian"]')) diet = 'nonveg';
    }
    if (!tags) {
      var tagEls = card.querySelectorAll('.item-card__tag');
      tags = Array.from(tagEls).map(function(t) { return t.textContent.trim(); }).join(',');
    }

    return { id: id, name: name, price: price, category: category, image: image, desc: desc, diet: diet, tags: tags };
  }

  // ── Event delegation — one handler for all cards ───────────
  // Mark existing cards so hover overlay shows
  function markCards() {
    document.querySelectorAll('.item-card:not([data-drawer-ready]), .dish-item:not([data-drawer-ready])')
      .forEach(function(card) {
        card.setAttribute('data-drawer-ready', '1');
        card.classList.add('item-card--clickable');
        card.style.cursor = 'pointer';
      });
  }
  markCards();

  // Single delegated click on document
  document.addEventListener('click', function(e) {
    // Skip if click was on an add-to-cart button or inside the drawer itself
    if (e.target.closest('[data-add-to-cart], .btn--add-cart, .product-drawer, #drawer-scrim')) return;

    var card = e.target.closest('.item-card, .dish-item');
    if (!card) return;

    openDrawer(readCardData(card));
  });

  // Re-mark if new cards added dynamically
  var _mo = new MutationObserver(function() { markCards(); });
  _mo.observe(document.body, { childList: true, subtree: true });

  // Expose for external use
  window.BhimasDrawer = { open: openDrawer, close: closeDrawer };

})();


/* ============================================================
   14. CUSTOMER SESSION NAV SYNC
   ============================================================ */
(function initCustomerNav() {
  var accountLink = document.getElementById('navbar-account-link');
  var mobileAccountLink = document.getElementById('mobile-navbar-account-link');

  fetch('php/customer-session.php', { credentials: 'same-origin' })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.logged_in && data.user) {
        if (accountLink) {
          accountLink.textContent = 'My Account';
          accountLink.setAttribute('href', 'account.html');
        }
        if (mobileAccountLink) {
          mobileAccountLink.textContent = 'My Account';
          mobileAccountLink.setAttribute('href', 'account.html');
        }

        // Auto-fill checkout fields if on Reservation or Takeaway page
        var nameInput  = document.getElementById('order-name')  || document.getElementById('res-name');
        var emailInput = document.getElementById('order-email') || document.getElementById('res-email');
        var phoneInput = document.getElementById('order-phone') || document.getElementById('res-phone');

        if (nameInput  && !nameInput.value)  nameInput.value  = data.user.name;
        if (emailInput && !emailInput.value) emailInput.value = data.user.email;
        if (phoneInput && !phoneInput.value) phoneInput.value = data.user.phone || '';
      }
    })
    .catch(function (err) {
      // Offline / local preview fallback
    });
})();


/* ============================================================
   15. EXPORTS — attach to window for inline scripts
   ============================================================ */
window.BhimasCart     = BhimasCart;
window.BhimasToast    = BhimasToast;
window.BhimasForms    = BhimasForms;
window.BhimasSettings = BhimasSettings;

window.fetchSettings       = fetchSettings;
window.generateTimeSlots   = generateTimeSlots;
window.generatePickupSlots = generatePickupSlots;
window.populateTimeSelect  = populateTimeSelect;
window.getCsrfToken        = getCsrfToken;
window.withCsrf            = withCsrf;
