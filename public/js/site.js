/* RED PRIME RENTALS website. Plain JS, no build step.
   Prices, availability and requests always come from the CRM API (/api/v1). */
(function () {
  'use strict';
  var RP = window.RP || { t: {}, lang: 'en', api: '/api/v1', aed: 'AED' };
  var T = RP.t || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  var nf = new Intl.NumberFormat(RP.lang === 'ar' ? 'ar-AE' : 'en-AE', { maximumFractionDigits: 2 });
  function money(n) {
    if (n === null || n === undefined || isNaN(n)) return '';
    var s = nf.format(Number(n));
    return RP.lang === 'ar' ? s + ' ' + RP.aed : RP.aed + ' ' + s;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(msg) {
    var el = $('[data-toast]'); if (!el) return;
    el.textContent = msg; el.classList.add('on');
    clearTimeout(el._t); el._t = setTimeout(function () { el.classList.remove('on'); }, 4200);
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'rp-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }
  function api(path, body) {
    return fetch(RP.api + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept-Language': RP.lang },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, ok: r.ok, body: j }; });
    });
  }
  function utm() {
    var q = new URLSearchParams(location.search), o = {};
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) {
      var v = q.get(k) || store.get('rp-' + k);
      if (q.get(k)) store.set('rp-' + k, q.get(k));
      if (v) o[k] = v.slice(0, 100);
    });
    return o;
  }
  utm(); // remember campaign tags from the landing page

  /* ---------- Theme ---------- */
  var root = document.documentElement;
  function setThemeLabel() {
    var dark = root.getAttribute('data-theme') !== 'light';
    $$('[data-theme-toggle]').forEach(function (b) { b.setAttribute('aria-label', dark ? (b.dataset.labelLight || T.theme_light) : (b.dataset.labelDark || T.theme_dark)); });
  }
  $$('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      root.setAttribute('data-theme', next); store.set('rp-theme', next); setThemeLabel();
    });
  });
  setThemeLabel();

  /* ---------- Header, drawer, dropdown, to top ---------- */
  var hdr = $('[data-hdr]'), totop = $('[data-totop]');
  function onScroll() {
    var y = window.scrollY || 0;
    if (hdr && !hdr.classList.contains('always')) hdr.classList.toggle('solid', y > 30);
    if (totop) totop.classList.toggle('on', y > 900);
  }
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  if (totop) totop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  $$('[data-dd] > button').forEach(function (b) {
    b.addEventListener('click', function () {
      var dd = b.parentNode, open = !dd.classList.contains('open');
      dd.classList.toggle('open', open); b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function (e) {
    $$('[data-dd].open').forEach(function (dd) { if (!dd.contains(e.target)) { dd.classList.remove('open'); $('button', dd).setAttribute('aria-expanded', 'false'); } });
  });

  var drawer = $('[data-drawer]'), lastFocus = null;
  function drawerToggle(open) {
    if (!drawer) return;
    drawer.classList.toggle('open', open); drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    $$('[data-drawer-open]').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) { lastFocus = document.activeElement; var f = $('[data-drawer-close].tool', drawer); if (f) f.focus(); } else if (lastFocus) lastFocus.focus();
  }
  $$('[data-drawer-open]').forEach(function (b) { b.addEventListener('click', function () { drawerToggle(true); }); });
  $$('[data-drawer-close]').forEach(function (b) { b.addEventListener('click', function () { drawerToggle(false); }); });

  /* ---------- Reveal on scroll & counters ---------- */
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
    $$('.rv').forEach(function (el) { io.observe(el); });
  } else { $$('.rv').forEach(function (el) { el.classList.add('in'); }); }

  /* ---------- Tabs ---------- */
  $$('[data-tabs]').forEach(function (box) {
    var tabs = $$('[role="tab"]', box);
    function show(tab) {
      tabs.forEach(function (t) {
        var on = t === tab; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
        var p = document.getElementById(t.getAttribute('aria-controls')); if (p) p.classList.toggle('on', on);
      });
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { show(t); });
      t.addEventListener('keydown', function (e) {
        var d = (e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0) * (document.dir === 'rtl' ? -1 : 1);
        if (d) { e.preventDefault(); var n = tabs[(i + d + tabs.length) % tabs.length]; show(n); n.focus(); }
      });
    });
    var sel = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || tabs[0];
    if (sel) show(sel);
  });

  /* ---------- Small form helpers ---------- */
  $$('[data-stepper]').forEach(function (st) {
    var input = $('input', st);
    $$('[data-step]', st).forEach(function (b) {
      b.addEventListener('click', function () {
        var min = input.min !== '' ? +input.min : -Infinity, max = input.max !== '' ? +input.max : Infinity;
        var v = (parseInt(input.value, 10) || (min > 0 ? min - 1 : 0)) + (+b.dataset.step);
        input.value = Math.max(min, Math.min(max, v));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  });

  // Date + time pickers joined into one "YYYY-MM-DD HH:MM" value (cars search and filters).
  $$('[data-dt-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
      [['from', 'from_d', 'from_t'], ['to', 'to_d', 'to_t']].forEach(function (k) {
        var h = form.elements[k[0]], d = form.elements[k[1]], t = form.elements[k[2]];
        if (!h || !d) return;
        h.value = d.value ? d.value + ' ' + (t ? t.value : '10:00') : '';
        d.disabled = true; if (t) t.disabled = true;
      });
      $$('input, select', form).forEach(function (el) {
        if (el.type === 'radio' || el.type === 'checkbox') return;
        if (el.value === '' && el.name) el.disabled = true;
        if (el.type === 'range' && el.dataset.rangeMax && +el.value >= +el.dataset.rangeMax) el.disabled = true;
      });
      var av = form.elements.available; if (av && av.type === 'hidden' && !form.elements.from.value) av.disabled = true;
      var mode = $$('input[name="mode"]:checked', form)[0]; if (mode && !mode.value) mode.disabled = true;
    });
  });

  $$('input[type="range"][data-range-out]').forEach(function (r) {
    var out = $(r.dataset.rangeOut), fmt = out ? out.dataset.fmt : null;
    r.addEventListener('input', function () { if (out && fmt) out.textContent = fmt.replace(':price', money(r.value)); });
  });

  var sortSel = $('[data-sort-select]');
  if (sortSel) sortSel.addEventListener('change', function () {
    var u = new URL(location.href); if (sortSel.value === 'featured') u.searchParams.delete('sort'); else u.searchParams.set('sort', sortSel.value); location.href = u.toString();
  });

  var filters = $('[data-filters]');
  $$('[data-filters-open]').forEach(function (b) { b.addEventListener('click', function () { filters.classList.add('open'); document.body.style.overflow = 'hidden'; }); });
  $$('[data-filters-close]').forEach(function (b) { b.addEventListener('click', function () { filters.classList.remove('open'); document.body.style.overflow = ''; }); });

  // Grid / list view (remembered in this browser).
  var viewTarget = $('[data-view-target]');
  function setView(v) {
    if (!viewTarget) return;
    viewTarget.classList.toggle('as-list', v === 'list');
    $$('[data-view]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.view === v ? 'true' : 'false'); });
  }
  $$('[data-view]').forEach(function (b) { b.addEventListener('click', function () { setView(b.dataset.view); store.set('rp-view', b.dataset.view); }); });
  if (viewTarget && window.innerWidth > 700) setView(store.get('rp-view') || 'grid');

  // Chip filters (client side: home fleet, packages).
  $$('[data-chip-filter]').forEach(function (row) {
    var scope = row.dataset.chipTarget ? $(row.dataset.chipTarget) : (row.parentNode.querySelector('[data-carousel]') || row.parentNode);
    $$('[data-filter]', row).forEach(function (chip) {
      chip.addEventListener('click', function () {
        $$('[data-filter]', row).forEach(function (c) { var on = c === chip; c.classList.toggle('on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        var f = chip.dataset.filter;
        $$('[data-body]', scope).forEach(function (card) { card.style.display = !f || card.dataset.body === f ? '' : 'none'; card.classList.add('in'); });
        var track = $('[data-carousel]', row.parentNode); if (track) track.scrollTo({ left: 0, behavior: 'smooth' });
      });
    });
  });

  // Carousel arrows.
  $$('[data-carousel-wrap]').forEach(function (w) {
    var track = $('[data-carousel]', w); if (!track) return;
    var rtl = document.dir === 'rtl';
    function go(d) { var card = track.firstElementChild; var step = card ? card.getBoundingClientRect().width + 22 : 300; track.scrollBy({ left: d * step * (rtl ? -1 : 1), behavior: 'smooth' }); }
    var p = $('[data-car-prev]', w), n = $('[data-car-next]', w);
    if (p) p.addEventListener('click', function () { go(-1); });
    if (n) n.addEventListener('click', function () { go(1); });
  });

  /* ---------- Gallery & lightbox ---------- */
  var lb = $('[data-lb]'), lbImg = lb ? $('[data-lb-img]', lb) : null, lbList = [], lbIdx = 0;
  function lbShow(i) { if (!lbList.length) return; lbIdx = (i + lbList.length) % lbList.length; lbImg.src = lbList[lbIdx].src; lbImg.alt = lbList[lbIdx].alt || ''; }
  function lbOpen(list, i) { lbList = list; lbShow(i); lb.classList.add('open'); document.body.style.overflow = 'hidden'; $('[data-lb-close]', lb).focus(); }
  function lbClose() { lb.classList.remove('open'); document.body.style.overflow = ''; }
  if (lb) {
    $('[data-lb-close]', lb).addEventListener('click', lbClose);
    $('[data-lb-prev]', lb).addEventListener('click', function () { lbShow(lbIdx - 1); });
    $('[data-lb-next]', lb).addEventListener('click', function () { lbShow(lbIdx + 1); });
    lb.addEventListener('click', function (e) { if (e.target === lb) lbClose(); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { if (lb && lb.classList.contains('open')) lbClose(); drawerToggle(false); if (filters) filters.classList.remove('open'); }
    if (lb && lb.classList.contains('open')) { if (e.key === 'ArrowRight') lbShow(lbIdx + (document.dir === 'rtl' ? -1 : 1)); if (e.key === 'ArrowLeft') lbShow(lbIdx - (document.dir === 'rtl' ? -1 : 1)); }
  });
  $$('[data-gallery]').forEach(function (g) {
    var list = []; try { list = JSON.parse(g.dataset.images || '[]'); } catch (e) {}
    var main = $('[data-g-main]', g), count = $('[data-g-count]', g), idx = 0;
    var thumbs = $$('[data-g-thumb]', g);
    function show(i) {
      if (!list.length || !main) return;
      idx = (i + list.length) % list.length; main.src = list[idx].src; main.alt = list[idx].alt || '';
      thumbs.forEach(function (t, k) { t.setAttribute('aria-current', k === idx ? 'true' : 'false'); });
      if (count) count.textContent = (idx + 1) + ' / ' + list.length;
    }
    thumbs.forEach(function (t) { t.addEventListener('click', function () { show(+t.dataset.gThumb); }); });
    var p = $('[data-g-prev]', g), n = $('[data-g-next]', g);
    if (p) p.addEventListener('click', function () { show(idx - 1); });
    if (n) n.addEventListener('click', function () { show(idx + 1); });
    if (main && lb) main.addEventListener('click', function () { lbOpen(list, idx); });
    var x0 = null;
    if (main) {
      main.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
      main.addEventListener('touchend', function (e) { if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) show(idx + (dx < 0 ? 1 : -1) * (document.dir === 'rtl' ? -1 : 1)); x0 = null; });
    }
  });

  // Section tabs on detail pages: highlight the section in view.
  $$('[data-spy]').forEach(function (nav) {
    var links = $$('a[href^="#"]', nav);
    if (!('IntersectionObserver' in window) || !links.length) return;
    var obs = new IntersectionObserver(function (es) {
      es.forEach(function (en) { if (en.isIntersecting) links.forEach(function (l) { l.classList.toggle('on', l.getAttribute('href') === '#' + en.target.id); }); });
    }, { rootMargin: '-40% 0px -55% 0px' });
    links.forEach(function (l) { var s = document.querySelector(l.getAttribute('href')); if (s) obs.observe(s); });
  });

  /* ---------- Booking panels (estimate + request) ---------- */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function addHours(d, t, h) {
    var p = d.split('-'), q = t.split(':');
    var dt = new Date(+p[0], +p[1] - 1, +p[2], +q[0], +q[1]);
    dt.setTime(dt.getTime() + h * 3600000);
    return dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()) + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
  }
  function val(form, name) { var el = form.elements[name]; if (!el) return ''; if (el instanceof RadioNodeList || (el.length && !el.tagName)) { var c = $$('[name="' + name + '"]:checked', form)[0]; return c ? c.value : ''; } if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? el.value : ''; return (el.value || '').trim(); }
  function checked(form, name) { var el = form.elements[name]; return !!(el && el.checked); }

  function payloadFor(form) {
    var type = form.dataset.quote, b = { product_type: type };
    if (type !== 'transfer') b.product_slug = form.dataset.slug;
    var sd = val(form, 'start_d'), st = val(form, 'start_t') || '10:00';
    if (type === 'car') {
      var ed = val(form, 'end_d'), et = val(form, 'end_t') || '10:00';
      if (!sd || !ed) return null;
      b.start_at = sd + ' ' + st; b.end_at = ed + ' ' + et;
      if (b.end_at <= b.start_at) return null;
      b.drive_mode = val(form, 'drive_mode') || undefined;
      b.delivery = checked(form, 'delivery'); b.collection = checked(form, 'collection');
    } else if (type === 'yacht') {
      if (!sd) return null;
      b.start_at = sd + ' ' + st; b.end_at = addHours(sd, st, +(val(form, 'hours') || 3));
      b.guests = +(val(form, 'guests') || 0) || undefined;
    } else if (type === 'yacht_trip' || type === 'package') {
      var dep = val(form, 'departure_id'), date = val(form, 'date');
      if (dep) b.departure_id = +dep; else if (date) b.date = date; else return null;
      b.guests = +(val(form, 'guests') || 0) || undefined;
    } else if (type === 'transfer') {
      var svc = $$('[name="service"]:checked', form)[0], dist = form.dataset.distance === '1';
      var needs = dist ? ['dropoff'] : (svc ? JSON.parse(svc.dataset.needs || '[]') : []);
      b.service = dist ? undefined : (svc ? svc.value : ''); b.product_slug = val(form, 'product_slug');
      b.pickup = val(form, 'pickup');
      if ((!dist && !b.service) || !b.product_slug || b.pickup.length < 3 || !sd) return null;
      // 11 Nov: an exact pin (map or "my location") goes along with the address.
      if (val(form, 'pickup_ll')) b.pickup_ll = val(form, 'pickup_ll');
      if (val(form, 'dropoff_ll')) b.dropoff_ll = val(form, 'dropoff_ll');
      b.start_at = sd + ' ' + st;
      if (needs.indexOf('dropoff') >= 0) { b.dropoff = val(form, 'dropoff'); if (b.dropoff.length < 3) return null; }
      if (needs.indexOf('end_at') >= 0) { var ld = val(form, 'end_d') || sd; b.end_at = ld + ' ' + (val(form, 'end_t') || '15:00'); if (b.end_at <= b.start_at) return null; }
      if (needs.indexOf('flight_number') >= 0 && val(form, 'flight_number')) b.flight_number = val(form, 'flight_number');
      if (checked(form, 'return') && val(form, 'return_d')) b.return_at = val(form, 'return_d') + ' ' + (val(form, 'return_t') || '18:00');
      b.passengers = +(val(form, 'passengers') || 0) || undefined;
      b.luggage = val(form, 'luggage') !== '' ? +val(form, 'luggage') : undefined;
      b.all_classes = true; // 6 Nov: every class priced at once
    }
    var extras = $$('[name="extras[]"]:checked', form).map(function (x) { return x.value; });
    if (extras.length) b.extras = extras;
    return b;
  }

  function renderEstimate(form, est, d) {
    var rows = [], p = d.price;
    var state = d.available === true ? '<span class="badge badge-ok">' + esc(T.available) + '</span>'
      : d.available === false ? '<span class="badge badge-bad">' + esc(d.reason || T.unavailable) + '</span>'
      : '<span class="badge badge-dark">' + esc(T.on_request) + '</span>';
    rows.push('<div class="est-state">' + state + '</div>');
    // 6 Nov (limousine): price on every class card, and the route on a map.
    if (d.classes) {
      d.classes.forEach(function (c) {
        var el = $('[data-class-price="' + c.slug + '"]', form);
        if (!el) return;
        el.textContent = c.total ? money(c.total) : '';
        var card = el.closest('.vcat'); if (card) card.classList.toggle('vcat-nofit', c.fits === false);
      });
    }
    var mp = $('[data-map]', form);
    if (mp) {
      if (d.map) { if (mp.dataset.src !== d.map) { mp.dataset.src = d.map; mp.innerHTML = '<iframe title="Route" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen src="' + esc(d.map) + '"></iframe>'; } mp.hidden = false; }
      else mp.hidden = true;
    }
    if (d.route) {
      var rb = $('[data-route]', form);
      if (rb) { rb.hidden = false; $('[data-route-km]', rb).textContent = T.km.replace(':n', nf.format(d.route.km)); $('[data-route-min]', rb).textContent = d.route.minutes ? T.minutes.replace(':n', d.route.minutes) : '—'; }
    }
    if (!p) {
      rows.push('<p class="muted small">' + esc(T.no_price) + '</p>');
    } else {
      rows.push('<div class="est-row"><span>' + esc(p.base.label) + '</span><span>' + money(p.base.amount) + '</span></div>');
      (p.lines || []).forEach(function (l) { rows.push('<div class="est-row"><span>' + esc(l.label) + '</span><span>' + (l.amount < 0 ? '−' + money(-l.amount) : money(l.amount)) + '</span></div>'); });
      if (p.vat && !p.vat.included) {
        rows.push('<div class="est-row"><span>' + esc(T.subtotal) + '</span><span>' + money(p.subtotal) + '</span></div>');
        rows.push('<div class="est-row"><span>' + esc(T.vat.replace(':rate', p.vat.rate)) + '</span><span>' + money(p.vat.amount) + '</span></div>');
      }
      rows.push('<div class="est-row est-total"><span>' + esc(T.total) + '</span><span>' + money(p.total) + '</span></div>');
      if (p.vat && p.vat.included) rows.push('<p class="est-note">' + esc(T.vat_included.replace(':rate', p.vat.rate)) + '</p>');
    }
    if (d.security_deposit) rows.push('<div class="est-row"><span>' + esc(T.deposit) + '</span><span>' + money(d.security_deposit) + '</span></div>');
    (d.conditions || []).forEach(function (c) { rows.push('<p class="est-note">• ' + esc(c) + '</p>'); });
    if (p) rows.push('<p class="est-note">' + esc(RP.lang === 'ar' || !d.note ? T.note : d.note) + '</p>');
    est.innerHTML = rows.join('');
    form._estimate = d;

    // Optional extras offered by the price list.
    var wrap = $('[data-extras-wrap]', form), box = $('[data-extras]', form);
    if (wrap && box && d.optional_extras && d.optional_extras.length && !box.childElementCount) {
      box.innerHTML = d.optional_extras.map(function (o) {
        return '<label class="extra"><span class="flex center gap-1"><input type="checkbox" name="extras[]" value="' + esc(o.key) + '" data-label="' + esc(o.label) + '">' + esc(o.label) + '</span><span>' + money(o.price) + '</span></label>';
      }).join('');
      wrap.hidden = false;
    }
  }

  function setupQuote(form) {
    var est = $('[data-est]', form), timer = null, seq = 0;
    function steps(name) { $$('.bk-step', form).forEach(function (s) { s.classList.toggle('on', s.dataset.step === name); }); }
    function refresh() {
      var b = payloadFor(form);
      updateWa(form, b);
      if (!b) { if (est) est.innerHTML = '<p class="muted small">' + esc(T.pick_dates) + '</p>'; form._estimate = null; return; }
      if ((b.product_type === 'yacht_trip' || b.product_type === 'package') && !b.departure_id && form.dataset.quote === 'yacht_trip') {
        est.innerHTML = '<div class="est-state"><span class="badge badge-dark">' + esc(T.on_request) + '</span></div><p class="muted small">' + esc(T.no_price) + '</p>'; return;
      }
      clearTimeout(timer);
      timer = setTimeout(function () {
        var my = ++seq; est.classList.add('est-loading');
        api('/price-estimate', b).then(function (r) {
          if (my !== seq) return; est.classList.remove('est-loading');
          if (r.ok && r.body.data) renderEstimate(form, est, r.body.data);
          else if (r.status === 429) est.innerHTML = '<p class="err-msg">' + esc(T.too_many) + '</p>';
          else {
            var msg = r.body && r.body.errors ? Object.keys(r.body.errors).map(function (k) { return r.body.errors[k][0]; })[0] : (r.body.message || T.no_price);
            est.innerHTML = '<p class="err-msg">' + esc(msg) + '</p>';
          }
        }).catch(function () { if (my === seq) { est.classList.remove('est-loading'); est.innerHTML = '<p class="err-msg">' + esc(T.error) + '</p>'; } });
      }, 350);
    }
    form.addEventListener('change', function (e) {
      if (e.target.name === 'drive_mode' && e.target.dataset.from) { var fl = $('[data-from-label]', form); if (fl) fl.innerHTML = esc(e.target.dataset.from) + ' <span>' + esc(T.per_day) + '</span>'; }
      if (e.target.name === 'service') transferNeeds(form);
      if (e.target.name === 'start_d') { ['end_d', 'return_d'].forEach(function (n) { var el = form.elements[n]; if (el) { el.min = e.target.value; if (!el.value || el.value < e.target.value) el.value = n === 'end_d' && form.dataset.quote === 'car' ? nextDay(e.target.value) : e.target.value; } }); }
      showIf(form);
      if (!e.target.closest('[data-step="details"]')) refresh();
    });
    form.addEventListener('input', function (e) { if (e.target.name === 'pickup' || e.target.name === 'dropoff') refresh(); });
    if (form.dataset.quote === 'transfer') { places(form, refresh); pins(form, refresh); prefill(form); }
    var next = $('[data-next]', form);
    if (next) next.addEventListener('click', function () {
      if (!payloadFor(form)) { if (est) { est.innerHTML = '<p class="err-msg">' + esc(T.pick_dates) + '</p>'; est.scrollIntoView({ behavior: 'smooth', block: 'center' }); } return; }
      steps('details'); var n = form.elements.name; if (n) n.focus();
    });
    var back = $('[data-back]', form); if (back) back.addEventListener('click', function () { steps('quote'); });
    form._key = uuid();
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var b = payloadFor(form); if (!b) { steps('quote'); return; }
      var errEl = $('[data-form-error]', form), btn = $('[data-send]', form);
      var name = val(form, 'name'), phone = val(form, 'phone'), email = val(form, 'email');
      var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
      $$('.input.err', form).forEach(function (i) { i.classList.remove('err'); });
      if (!name || !/^[0-9+()\-\s]{6,30}$/.test(phone) || !emailOk) {
        if (!name) form.elements.name.classList.add('err'); if (!/^[0-9+()\-\s]{6,30}$/.test(phone)) form.elements.phone.classList.add('err');
        if (!emailOk && form.elements.email) form.elements.email.classList.add('err');
        errEl.textContent = T.error; errEl.hidden = false; return;
      }
      var notes = [];
      if (b.delivery) notes.push('Delivery' + (val(form, 'address') ? ': ' + val(form, 'address') : ''));
      if (b.collection) notes.push('Collection from customer');
      $$('[name="extras[]"]:checked', form).forEach(function (x) { notes.push('Extra: ' + x.dataset.label); });
      if (b.product_type === 'yacht' && val(form, 'hours')) notes.push(val(form, 'hours') + ' hours');
      if (RP.lang === 'ar') notes.push('Language: Arabic');
      var msg = [val(form, 'message'), notes.join('\n')].filter(Boolean).join('\n\n');
      var req = Object.assign({}, b, utm(), {
        request_type: 'booking', name: name, phone: phone, email: email,
        message: msg || undefined, website: val(form, 'website'), idempotency_key: form._key
      });
      var payWhen = form.elements.pay_when;
      if (payWhen) req.pay_now = (payWhen.value || '') === 'now';
      if (b.delivery && val(form, 'address')) req.address = val(form, 'address');
      if (req.product_type === 'transfer') { req.guests = req.passengers; if (form._leadRef) req.lead_ref = form._leadRef; }
      btn.disabled = true; btn.textContent = T.sending; errEl.hidden = true;
      api('/booking-requests', req).then(function (r) {
        btn.disabled = false; btn.textContent = T.send;
        if (r.ok && r.body.data) {
          var d = r.body.data, ref = d.reference || '';
          $$('[data-ref]', form).forEach(function (x) { x.textContent = ref; });
          var booked = !!d.booked, bk = $('[data-done-booked]', form), rq = $('[data-done-request]', form);
          var ride = !!(booked && d.awaiting_price && d.ride_url), rd = $('[data-done-ride]', form);
          if (bk) bk.hidden = !booked || ride; if (rq) rq.hidden = booked;
          if (rd) { rd.hidden = !ride; if (ride) { var rt = $('[data-ride-text]', rd); rt.textContent = rt.dataset.tpl.replace(':ref', ref); $('[data-ride-link]', rd).href = d.ride_url; } }
          var dt = $('[data-done-text]', form);
          if (dt) dt.textContent = (d.pay_url && !d.pay_now && dt.dataset.tplLater ? dt.dataset.tplLater : dt.dataset.tpl).replace(':ref', ref);
          if (d.pay_now && d.pay_url) { window.location = d.pay_url; return; }
          var dh = $('[data-done-hold]', form); if (dh) { dh.hidden = !d.hold_hours; dh.textContent = dh.dataset.tpl.replace(':hours', d.hold_hours || ''); }
          var wf = $('[data-wa-follow]', form);
          if (wf && RP.whatsapp) wf.href = RP.whatsapp + '?text=' + encodeURIComponent((RP.lang === 'ar' ? 'مرحباً، رقم طلبي ' : 'Hello, my request reference is ') + ref + ' (' + (form.dataset.item || '') + ')');
          steps('done'); form.scrollIntoView({ behavior: 'smooth', block: 'start' });
          if (window.dataLayer) window.dataLayer.push({ event: booked ? 'website_booking' : 'booking_request', item: form.dataset.item, reference: ref, value: d.total || undefined });
        } else if (r.status === 429) { errEl.textContent = T.too_many; errEl.hidden = false; }
        else {
          var m = r.body && r.body.errors ? Object.keys(r.body.errors).map(function (k) { var el = form.elements[k]; if (el && el.classList) el.classList.add('err'); return r.body.errors[k][0]; })[0] : T.error;
          errEl.textContent = m || T.error; errEl.hidden = false;
        }
      }).catch(function () { btn.disabled = false; btn.textContent = T.send; errEl.textContent = T.error; errEl.hidden = false; });
    });
    if (form.dataset.quote === 'transfer') transferNeeds(form);
    showIf(form); refresh();
  }
  function nextDay(d) { var p = d.split('-'); var dt = new Date(+p[0], +p[1] - 1, +p[2] + 1); return dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()); }
  function showIf(form) { $$('[data-show-if]', form).forEach(function (el) { el.hidden = !checked(form, el.dataset.showIf); }); }
  /* 6 Nov (limousine L4): a booking link from an agent opens the page filled in. */
  function prefill(form) {
    var q = new URLSearchParams(location.search); if (!q.toString()) return;
    function radio(name, v) { var r = $$('[name="' + name + '"]', form).filter(function (x) { return x.value === v; })[0]; if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); } }
    function set(name, v) { var el = form.elements[name]; if (el && v) { el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); } }
    if (q.get('service')) radio('service', q.get('service'));
    if (q.get('class')) radio('product_slug', q.get('class'));
    set('pickup', q.get('pickup')); set('dropoff', q.get('dropoff'));
    set('start_d', q.get('date')); set('start_t', q.get('time')); set('passengers', q.get('pax'));
    if (/^\d+\.[a-f0-9]{16}$/.test(q.get('lr') || '')) form._leadRef = q.get('lr');
  }

  /* 6 Nov (limousine): address suggestions from Google (through the CRM) and quick places. */
  function places(form, refresh) {
    ['pickup', 'dropoff'].forEach(function (name) {
      var input = form.elements[name]; if (!input) return;
      var field = input.closest('.field'), list = field && $('[data-ac-list]', field), timer = null, seq = 0, items = [], pos = -1;
      function close() { if (list) { list.hidden = true; list.innerHTML = ''; } items = []; pos = -1; input.setAttribute('aria-expanded', 'false'); }
      function choose(v) { input.value = v; if (form.elements[name + '_ll']) form.elements[name + '_ll'].value = ''; close(); refresh(); }
      function draw() {
        list.innerHTML = items.map(function (a, i) { return '<button type="button" role="option" class="ac-item' + (i === pos ? ' on' : '') + '" data-v="' + esc(a) + '">' + esc(a) + '</button>'; }).join('');
        list.hidden = !items.length; input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
      }
      input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('autocomplete', 'off');
      input.addEventListener('input', function () {
        if (form.elements[name + '_ll']) form.elements[name + '_ll'].value = '';
        clearTimeout(timer); var q = input.value.trim();
        if (q.length < 3 || !list) { close(); return; }
        timer = setTimeout(function () {
          var my = ++seq;
          fetch(RP.api + '/places?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : { data: [] }; })
            .then(function (j) { if (my !== seq) return; items = (j.data || []).slice(0, 6); pos = -1; draw(); })
            .catch(close);
        }, 280);
      });
      input.addEventListener('keydown', function (e) {
        if (!items.length) return;
        if (e.key === 'ArrowDown') { pos = (pos + 1) % items.length; draw(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { pos = (pos - 1 + items.length) % items.length; draw(); e.preventDefault(); }
        else if (e.key === 'Enter' && pos >= 0) { choose(items[pos]); e.preventDefault(); }
        else if (e.key === 'Escape') close();
      });
      input.addEventListener('blur', function () { setTimeout(close, 180); });
      if (list) list.addEventListener('mousedown', function (e) { var b = e.target.closest('[data-v]'); if (b) { e.preventDefault(); choose(b.dataset.v); } });
      if (field) $$('[data-spot]', field).forEach(function (b) { b.addEventListener('click', function () { choose(b.dataset.spot); }); });
    });
  }

  /* 11 Nov (limousine): pickup and destination like a ride app: "my location" and a pin on the map.
     The pin stays in the middle and the map moves under it; the CRM turns the point into an address. */
  function pins(form, refresh) {
    var box = $('[data-mapick]'), DUBAI = { lat: 25.2048, lng: 55.2708 };
    function ll(p) { return p.lat.toFixed(6) + ',' + p.lng.toFixed(6); }
    function addressOf(p) {
      return fetch(RP.api + '/places/reverse?lat=' + p.lat + '&lng=' + p.lng, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { return (j && j.data && j.data.address) || null; }).catch(function () { return null; });
    }
    function put(name, p, address) {
      var input = form.elements[name]; if (!input) return;
      input.value = address || ('Pin ' + p.lat.toFixed(5) + ', ' + p.lng.toFixed(5));
      form.elements[name + '_ll'].value = ll(p); input.classList.remove('err'); refresh();
    }
    function locate(done, fail) {
      if (!navigator.geolocation) { fail(); return; }
      navigator.geolocation.getCurrentPosition(function (g) { done({ lat: g.coords.latitude, lng: g.coords.longitude }); }, fail, { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
    }
    function denied(btn) { var f = btn.closest('.field'), m = f && $('.loc-msg', f); if (!m && f) { m = document.createElement('p'); m.className = 'loc-msg err-msg'; f.appendChild(m); } if (m) { m.textContent = form.dataset.tDenied; m.hidden = false; } }

    $$('[data-my-location]', form).forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        locate(function (p) { addressOf(p).then(function (a) { btn.disabled = false; put(btn.dataset.myLocation, p, a); }); }, function () { btn.disabled = false; denied(btn); });
      });
    });
    if (!box || !form.dataset.mapsKey) return;

    var canvas = $('[data-mapick-canvas]', box), addr = $('[data-mapick-addr]', box), ok = $('[data-mapick-ok]', box), map = null, target = null, found = null, timer = null, seq = 0, loading = null;
    function load() {
      if (window.google && google.maps && google.maps.Map) return Promise.resolve();
      if (loading) return loading;
      loading = new Promise(function (res, rej) {
        window.rpMapReady = function () { res(); };
        var sc = document.createElement('script'); sc.async = true; sc.onerror = rej;
        sc.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(form.dataset.mapsKey) + '&callback=rpMapReady&loading=async';
        document.head.appendChild(sc);
      });
      return loading;
    }
    function settle() {
      if (!map) return; var c = map.getCenter(), p = { lat: c.lat(), lng: c.lng() }, my = ++seq;
      found = { p: p, address: null }; addr.textContent = form.dataset.tFinding; ok.disabled = false;
      clearTimeout(timer); timer = setTimeout(function () { addressOf(p).then(function (a) { if (my !== seq) return; found.address = a; addr.textContent = a || (p.lat.toFixed(5) + ', ' + p.lng.toFixed(5)); }); }, 350);
    }
    function open(name) {
      target = name; box.hidden = false; document.documentElement.classList.add('mapick-open');
      $('[data-mapick-title]', box).textContent = name === 'pickup' ? form.dataset.tPickup : form.dataset.tDropoff;
      var cur = (form.elements[name + '_ll'].value || '').split(','), start = cur.length === 2 ? { lat: +cur[0], lng: +cur[1] } : null;
      load().then(function () {
        if (!map) { map = new google.maps.Map(canvas, { center: start || DUBAI, zoom: start ? 16 : 12, disableDefaultUI: true, zoomControl: true, gestureHandling: 'greedy', clickableIcons: false }); map.addListener('idle', settle); }
        else { google.maps.event.trigger(map, 'resize'); if (start) { map.setCenter(start); map.setZoom(16); } else settle(); }
        var typed = form.elements[name].value.trim();
        if (!start && typed.length >= 3) go(typed);
      }).catch(function () { addr.textContent = '—'; });
    }
    function close() { box.hidden = true; document.documentElement.classList.remove('mapick-open'); }
    function go(text) {
      fetch(RP.api + '/places/locate?q=' + encodeURIComponent(text), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { if (j && j.data && map) { map.setCenter(j.data); map.setZoom(16); } }).catch(function () {});
    }
    $$('[data-on-map]', form).forEach(function (btn) { btn.addEventListener('click', function () { open(btn.dataset.onMap); }); });
    $('[data-mapick-close]', box).addEventListener('click', close);
    box.addEventListener('click', function (e) { if (e.target === box) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) close(); });
    ok.addEventListener('click', function () { if (found && target) { put(target, found.p, found.address); close(); } });
    $('[data-mapick-me]', box).addEventListener('click', function () { locate(function (p) { if (map) { map.setCenter(p); map.setZoom(17); } }, function () { addr.textContent = form.dataset.tDenied; }); });
    // Search inside the picker: the same suggestions as the address fields.
    var si = $('[data-mapick-search]', box), sl = $('[data-mapick-list]', box), st = null, sq = 0;
    si.addEventListener('input', function () {
      clearTimeout(st); var q = si.value.trim(); if (q.length < 3) { sl.hidden = true; return; }
      st = setTimeout(function () { var my = ++sq;
        fetch(RP.api + '/places?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : { data: [] }; })
          .then(function (j) { if (my !== sq) return; var it = (j.data || []).slice(0, 5); sl.innerHTML = it.map(function (a) { return '<button type="button" class="ac-item" data-v="' + esc(a) + '">' + esc(a) + '</button>'; }).join(''); sl.hidden = !it.length; }).catch(function () { sl.hidden = true; });
      }, 280);
    });
    sl.addEventListener('click', function (e) { var b = e.target.closest('[data-v]'); if (b) { si.value = b.dataset.v; sl.hidden = true; go(b.dataset.v); } });
  }

  function transferNeeds(form) {
    var svc = $$('[name="service"]:checked', form)[0]; if (!svc) return;
    var needs = JSON.parse(svc.dataset.needs || '[]');
    $$('[data-need]', form).forEach(function (el) {
      var k = el.dataset.need; el.hidden = k === 'return' ? svc.dataset.return !== '1' : needs.indexOf(k) < 0;
    });
    $$('[data-svc-desc]', form).forEach(function (p) { p.hidden = p.dataset.svcDesc !== svc.value; });
  }
  function updateWa(form, b) {
    var a = $('[data-wa-dates]', form); if (!a || !RP.whatsapp) return;
    var txt = b && b.start_at && b.end_at
      ? (RP.lang === 'ar' ? 'مرحباً RED PRIME، أود حجز ' + form.dataset.item + ' من ' + b.start_at + ' إلى ' + b.end_at + '.' : 'Hello RED PRIME, I would like to book the ' + form.dataset.item + ' from ' + b.start_at + ' to ' + b.end_at + '.')
      : (RP.lang === 'ar' ? 'مرحباً RED PRIME، أود الاستفسار عن ' + form.dataset.item + '.' : 'Hello RED PRIME, I would like to ask about the ' + form.dataset.item + '.');
    a.href = RP.whatsapp + '?text=' + encodeURIComponent(txt);
  }
  $$('form[data-quote]').forEach(setupQuote);

  /* ---------- Contact / call-back forms ---------- */
  $$('form[data-enquiry]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var errEl = $('[data-form-error]', form), btn = $('[data-send]', form), label = btn.innerHTML;
      var name = val(form, 'name'), phone = val(form, 'phone');
      $$('.err', form).forEach(function (i) { if (i.classList.contains('input')) i.classList.remove('err'); });
      if (!name || !/^[0-9+()\-\s]{6,30}$/.test(phone)) {
        if (!name) form.elements.name.classList.add('err'); if (!/^[0-9+()\-\s]{6,30}$/.test(phone)) form.elements.phone.classList.add('err');
        errEl.textContent = T.error; errEl.hidden = false; return;
      }
      var body = Object.assign({ name: name, phone: phone, page: location.pathname.slice(0, 250) }, utm());
      ['service', 'email', 'date', 'message', 'kind', 'website'].forEach(function (k) { var v = val(form, k); if (v) body[k] = v; });
      var about = val(form, 'about'); if (about) body.message = 'About: ' + about + (body.message ? '\n' + body.message : '');
      if (RP.lang === 'ar') body.message = (body.message ? body.message + '\n\n' : '') + 'Language: Arabic';
      btn.disabled = true; btn.textContent = T.sending; errEl.hidden = true;
      api('/enquiries', body).then(function (r) {
        btn.disabled = false; btn.innerHTML = label;
        if (r.ok) {
          $('[data-enq-form]', form).hidden = true; var done = $('[data-enq-done]', form); done.hidden = false;
          var ref = $('[data-ref]', done); if (ref && r.body.data) ref.textContent = r.body.data.reference || '';
        } else if (r.status === 429) { errEl.textContent = T.too_many; errEl.hidden = false; }
        else { var m = r.body && r.body.errors ? Object.keys(r.body.errors).map(function (k) { var el = form.elements[k]; if (el && el.classList) el.classList.add('err'); return r.body.errors[k][0]; })[0] : T.error; errEl.textContent = m || T.error; errEl.hidden = false; }
      }).catch(function () { btn.disabled = false; btn.innerHTML = label; errEl.textContent = T.error; errEl.hidden = false; });
    });
  });

  /* ---------- Quick quote popup (every page) ---------- */
  var qm = $('[data-quote-modal]'), qmLast = null;
  function quoteOpen(btn) {
    if (!qm) return;
    var f = $('form', qm);
    if (btn && btn.dataset.service && f.elements.service) f.elements.service.value = btn.dataset.service;
    if (f.elements.about) f.elements.about.value = (btn && btn.dataset.item) || '';
    $('[data-enq-form]', f).hidden = false; $('[data-enq-done]', f).hidden = true;
    qmLast = document.activeElement; qm.hidden = false; document.body.style.overflow = 'hidden';
    setTimeout(function () { var n = f.elements.name; if (n) n.focus(); }, 50);
  }
  function quoteClose() { if (!qm || qm.hidden) return; qm.hidden = true; document.body.style.overflow = ''; if (qmLast) qmLast.focus(); }
  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open-quote]'); if (o) { e.preventDefault(); if (o.hasAttribute('data-drawer-close')) drawerToggle(false); quoteOpen(o); return; }
    if (e.target.closest('[data-close-quote]')) quoteClose();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') quoteClose(); });

  /* ---------- Hero photo slideshow ---------- */
  $$('[data-slides]').forEach(function (box) {
    var imgs = $$('img', box), i = 0;
    if (imgs.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    setInterval(function () { imgs[i].classList.remove('on'); i = (i + 1) % imgs.length; imgs[i].classList.add('on'); }, 6500);
  });

  /* ---------- Gallery grid opens the lightbox ---------- */
  $$('[data-g-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      var g = b.closest('[data-gallery]'), list = []; try { list = JSON.parse(g.dataset.images || '[]'); } catch (e) {}
      if (lb && list.length) lbOpen(list, +b.dataset.gOpen);
    });
  });

  /* =====================================================================
     Cinematic layer (30 Oct): intro screen, split headings, staggered reveals,
     parallax, tilt cards, magnetic buttons, counters, scroll progress, hero spotlight,
     slide dots, header hide on scroll down, car finder.
     Everything is skipped for visitors who ask for reduced motion.
     ===================================================================== */
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  // Intro screen: remember it was shown in this visit.
  function introDone() { if (root.classList.contains('is-loading')) root.classList.add('intro-out'); root.classList.remove('is-loading'); try { sessionStorage.setItem('rp-intro', '1'); } catch (e) {} }
  if (root.classList.contains('is-loading')) {
    if (document.readyState === 'complete') setTimeout(introDone, 700);
    else window.addEventListener('load', function () { setTimeout(introDone, 500); });
    setTimeout(introDone, 2200);
  }

  // Split headings into words that rise into place.
  function splitWords(el) {
    if (el.dataset.splitDone) return;
    var i = 0, nodes = Array.prototype.slice.call(el.childNodes);
    el.textContent = '';
    nodes.forEach(function (n) {
      if (n.nodeType === 3) {
        n.textContent.split(/(\s+)/).forEach(function (part) {
          if (!part) return;
          if (/^\s+$/.test(part)) { el.appendChild(document.createTextNode(' ')); return; }
          var w = document.createElement('span'), inner = document.createElement('span');
          w.className = 'w'; inner.textContent = part; inner.style.setProperty('--i', i++); w.appendChild(inner); el.appendChild(w);
        });
      } else if (n.nodeName === 'BR') { el.appendChild(n); }
      else { var w2 = document.createElement('span'), in2 = document.createElement('span'); w2.className = 'w'; in2.style.setProperty('--i', i++); in2.appendChild(n); w2.appendChild(in2); el.appendChild(w2); }
    });
    el.dataset.splitDone = '1'; el.classList.add('split-done');
  }
  var splits = $$('[data-split]');
  if (reduce) splits.forEach(function (el) { el.classList.add('split-done', 'in'); });
  else splits.forEach(splitWords);

  // Stagger the reveal of items in a row.
  ['.stagger', '.grid-cards', '.cats', '.features', '.svc-grid', '.logo-grid', '.post-grid', '.offer-grid', '.deal-grid', '.area-grid', '.steps', '.social-grid', '.occ-grid'].forEach(function (sel) {
    $$(sel).forEach(function (box) {
      $$(':scope > .rv', box).forEach(function (el, k) { el.style.transitionDelay = ((k % 6) * 90) + 'ms'; });
    });
  });
  // Clear the delay once shown, so hover effects stay snappy.
  document.addEventListener('transitionend', function (e) { var t = e.target; if (t.classList && t.classList.contains('rv') && t.classList.contains('in') && e.propertyName === 'opacity') t.style.transitionDelay = ''; });

  function countUp(el) {
    var end = parseFloat(el.dataset.count); if (isNaN(end) || reduce) return;
    var t0 = null, dur = 1400;
    function step(ts) { if (!t0) t0 = ts; var k = Math.min(1, (ts - t0) / dur), e = 1 - Math.pow(1 - k, 3); el.textContent = nf.format(Math.round(end * e)); if (k < 1) requestAnimationFrame(step); }
    requestAnimationFrame(step);
  }
  if ('IntersectionObserver' in window) {
    var io2 = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var el = en.target; el.classList.add('in');
        if (el.dataset.count) countUp(el);
        io2.unobserve(el);
      });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.1 });
    splits.forEach(function (el) { if (el.hasAttribute('data-split-now')) setTimeout(function () { el.classList.add('in'); }, root.classList.contains('is-loading') ? 900 : 150); else io2.observe(el); });
    $$('[data-count]').forEach(function (el) { io2.observe(el); });
  } else { splits.forEach(function (el) { el.classList.add('in'); }); }

  // Scroll-driven bits: progress bar, parallax, header hide on scroll down.
  var bar = $('[data-progress]'), plx = reduce ? [] : $$('[data-parallax]'), lastY = window.scrollY || 0, ticking = false;
  function onFrame() {
    ticking = false;
    var y = window.scrollY || 0, h = document.documentElement.scrollHeight - window.innerHeight, vh = window.innerHeight;
    if (bar) bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, y / h) : 0) + ')';
    plx.forEach(function (el) {
      var r = el.parentNode.getBoundingClientRect();
      if (r.bottom < 0 || r.top > vh) return;
      var off = (r.top + r.height / 2 - vh / 2) * -0.12;
      el.style.transform = 'translate3d(0,' + off.toFixed(1) + 'px,0)';
    });
    if (hdr && !reduce) {
      var open = document.querySelector('[data-dd].open, .drawer.open');
      hdr.classList.toggle('hdr-hide', y > 500 && y > lastY + 4 && !open);
      if (y < lastY - 4 || y < 500) hdr.classList.remove('hdr-hide');
    }
    lastY = y;
  }
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(onFrame); } }, { passive: true });
  window.addEventListener('resize', onFrame); onFrame();

  if (fine && !reduce) {
    // 3D tilt with a soft light that follows the pointer.
    $$('.tilt').forEach(function (el) {
      el.addEventListener('pointermove', function (e) {
        var r = el.getBoundingClientRect(), x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
        el.style.transition = 'transform .12s ease-out, border-color .3s, box-shadow .3s';
        el.style.transform = 'perspective(900px) rotateX(' + ((.5 - y) * 7).toFixed(2) + 'deg) rotateY(' + ((x - .5) * 7).toFixed(2) + 'deg) translateY(-6px)';
        el.style.setProperty('--gx', (x * 100).toFixed(1) + '%'); el.style.setProperty('--gy', (y * 100).toFixed(1) + '%');
        el.classList.add('tilting');
      });
      el.addEventListener('pointerleave', function () { el.style.transition = ''; el.style.transform = ''; el.classList.remove('tilting'); });
    });
    // Magnetic call-to-action buttons.
    $$('.magnetic').forEach(function (b) {
      b.addEventListener('pointermove', function (e) { var r = b.getBoundingClientRect(); b.style.transform = 'translate(' + ((e.clientX - r.left - r.width / 2) * .18).toFixed(1) + 'px,' + ((e.clientY - r.top - r.height / 2) * .28).toFixed(1) + 'px)'; });
      b.addEventListener('pointerleave', function () { b.style.transform = ''; });
    });
    // Spotlight that follows the pointer in the hero and statement sections.
    $$('[data-spot], .statement').forEach(function (sec) {
      sec.addEventListener('pointermove', function (e) { var r = sec.getBoundingClientRect(); sec.style.setProperty('--mx', (e.clientX - r.left) + 'px'); sec.style.setProperty('--my', (e.clientY - r.top) + 'px'); sec.classList.add('spot-on'); });
      sec.addEventListener('pointerleave', function () { sec.classList.remove('spot-on'); });
    });
  }

  // Hero slide dots follow the slideshow.
  $$('[data-slides]').forEach(function (box) {
    var dots = $$('i', $('[data-slide-dots]') || document.createElement('div')), imgs = $$('img', box);
    if (!dots.length) return;
    new MutationObserver(function () { var k = imgs.findIndex(function (im) { return im.classList.contains('on'); }); dots.forEach(function (d, j) { d.classList.toggle('on', j === k); }); })
      .observe(box, { subtree: true, attributes: true, attributeFilter: ['class'] });
  });

  /* ---------- Car finder (home) ---------- */
  $$('[data-cfinder]').forEach(function (box) {
    var cars = [], t = {}; try { cars = JSON.parse(box.dataset.cars || '[]'); t = JSON.parse(box.dataset.t || '{}'); } catch (e) {}
    var f = { max: '', seats: '', type: '', mode: '' };
    var list = $('[data-cf-list]', box), count = $('[data-cf-count]', box), none = $('[data-cf-none]', box), all = $('[data-cf-all]', box);
    function render() {
      var m = cars.filter(function (c) {
        if (f.max && (!c.p || c.p > +f.max)) return false;
        if (f.seats && c.s < +f.seats) return false;
        if (f.type && c.t !== f.type) return false;
        if (f.mode === 'self_drive' && !c.sd) return false;
        if (f.mode === 'with_driver' && !c.wd) return false;
        return true;
      }).sort(function (a, b) { return (a.p || 1e9) - (b.p || 1e9); });
      count.textContent = m.length === 0 ? t.zero : m.length === 1 ? t.one : String(t.many || '').replace(':count', m.length);
      none.hidden = m.length > 0;
      list.innerHTML = m.slice(0, 6).map(function (c, k) {
        return '<a class="cf-car" style="--k:' + k + '" href="' + esc(c.u) + '"><span class="cf-img">' + (c.i ? '<img src="' + esc(c.i) + '" alt="" loading="lazy">' : '<svg viewBox="0 0 120 60"><use href="#ph-car"/></svg>') + '</span>'
          + '<span class="cf-txt"><small>' + esc(c.b) + (c.t ? ' · ' + esc(c.t) : '') + '</small><b>' + esc(c.n) + '</b></span>'
          + (c.p ? '<span class="cf-price"><small>' + esc(t.from) + '</small><b>' + money(c.p) + '</b></span>' : '') + '</a>';
      }).join('');
      var u = new URL(box.dataset.all, location.href);
      if (f.max) u.searchParams.set('max', f.max); if (f.seats) u.searchParams.set('seats', f.seats);
      if (f.type) u.searchParams.append('body[]', f.type); if (f.mode) u.searchParams.set('mode', f.mode);
      all.href = u.toString(); all.hidden = m.length === 0;
    }
    $$('[data-cf]', box).forEach(function (b) {
      b.setAttribute('aria-pressed', b.classList.contains('on') ? 'true' : 'false');
      b.addEventListener('click', function () {
        var k = b.dataset.cf; f[k] = b.dataset.v;
        $$('[data-cf="' + k + '"]', box).forEach(function (o) { var on = o === b; o.classList.toggle('on', on); o.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        render();
      });
    });
    $('[data-cf-reset]', box).addEventListener('click', function () { f = { max: '', seats: '', type: '', mode: '' }; $$('[data-cf]', box).forEach(function (o) { var on = o.dataset.v === ''; o.classList.toggle('on', on); o.setAttribute('aria-pressed', on ? 'true' : 'false'); }); render(); });
    render();
  });
})();

(function () {
  var root = document.querySelector('[data-assist]');
  if (!root) return;
  var panel = root.querySelector('[data-assist-panel]');
  var log = root.querySelector('[data-assist-log]');
  var visitor = localStorage.getItem('rp-visitor');
  if (!visitor) { visitor = 'v' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem('rp-visitor', visitor); }
  var greeted = false;
  root.querySelector('[data-assist-open]').addEventListener('click', function () {
    panel.hidden = false;
    if (!greeted) { say(root.getAttribute('data-greet') || ''); greeted = true; }
  });
  root.querySelector('[data-assist-close]').addEventListener('click', function () { panel.hidden = true; });
  function say(text) { var p = document.createElement('p'); p.textContent = text; log.appendChild(p); log.scrollTop = log.scrollHeight; }
  function send(extra) {
    var msg = root.querySelector('[data-assist-message]');
    var body = Object.assign({
      visitor_id: visitor,
      message: (extra && extra.message) || msg.value,
      locale: root.getAttribute('data-locale') || 'en',
      product_slug: root.getAttribute('data-slug') || null,
      website: (root.querySelector('.assist-hp') || {}).value || ''
    }, extra || {});
    return fetch('/api/v1/chat', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        say((res.j.data && res.j.data.reply) || (res.j.message) || '');
        if (res.j.data && res.j.data.pay_url) { window.location.href = res.j.data.pay_url; }
      })
      .catch(function () { say(''); });
  }
  root.querySelector('[data-assist-form]').addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = root.querySelector('[data-assist-message]');
    if (!msg.value.trim()) return;
    say(msg.value.trim());
    send().then(function () { msg.value = ''; });
  });
  root.querySelectorAll('[data-assist-book]').forEach(function (book) {
    book.addEventListener('click', function () {
      send({
        action: 'book',
        message: 'book',
        name: root.querySelector('[data-assist-name]').value,
        phone: root.querySelector('[data-assist-phone]').value,
        email: root.querySelector('[data-assist-email]').value,
        start_at: root.querySelector('[data-assist-start]').value,
        end_at: root.querySelector('[data-assist-end]').value,
        pay_now: book.getAttribute('data-pay') === '1'
      });
    });
  });
})();
