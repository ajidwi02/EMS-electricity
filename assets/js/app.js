/* EMS app shell: drawer mobile + lucide + reveal-on-scroll (dashboard motion) */
(function () {
  'use strict';

  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('overlay');
  var openBtn = document.getElementById('btn-open-sidebar');
  var closeBtn = document.getElementById('btn-close-sidebar');

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('-translate-x-full');
    if (overlay) overlay.classList.remove('hidden', 'is-hidden');
    document.body.style.overflow = 'hidden';
    if (closeBtn) closeBtn.focus({ preventScroll: true });
  }

  function closeSidebar() {
    if (!sidebar) return;
    // Di desktop (lg+) sidebar selalu terlihat — jangan sembunyikan
    if (window.matchMedia('(min-width: 1024px)').matches) return;
    sidebar.classList.add('-translate-x-full');
    if (overlay) overlay.classList.add('hidden', 'is-hidden');
    document.body.style.overflow = '';
    if (openBtn) openBtn.focus({ preventScroll: true });
  }

  if (openBtn) openBtn.addEventListener('click', openSidebar);
  if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Tutup otomatis setelah pilih menu di mobile
  if (sidebar) {
    sidebar.querySelectorAll('a[data-nav]').forEach(function (link) {
      link.addEventListener('click', function () {
        if (window.matchMedia('(max-width: 1023.5px)').matches) closeSidebar();
      });
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
  });

  // Pastikan state benar saat resize (mis. rotate HP -> desktop)
  window.addEventListener('resize', function () {
    if (!sidebar || !overlay) return;
    if (window.matchMedia('(min-width: 1024px)').matches) {
      sidebar.classList.remove('-translate-x-full');
      overlay.classList.add('hidden', 'is-hidden');
      document.body.style.overflow = '';
    } else {
      sidebar.classList.add('-translate-x-full');
      overlay.classList.add('hidden', 'is-hidden');
      document.body.style.overflow = '';
    }
  });

  // Lucide icons
  if (window.lucide && typeof window.lucide.createIcons === 'function') {
    window.lucide.createIcons();
  }

  // Jam realtime zona Jakarta (Asia/Jakarta): "Senin, 14 Okt 2026 • 14:05:33 WIB".
  // Elemen di-render server lebih dulu (PHP) agar tidak kosong tanpa JS.
  var clockText = document.getElementById('ems-clock-text');
  if (clockText) {
    var dateFmt = null;
    var timeFmt = null;
    try {
      dateFmt = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta', weekday: 'long', day: 'numeric', month: 'short', year: 'numeric'
      });
      timeFmt = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
      });
    } catch (err) {
      dateFmt = null;
    }
    // Fallback bila Intl/timezone tidak didukung: jam lokal browser.
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tickClock() {
      var now = new Date();
      var datePart;
      var timePart;
      if (dateFmt && timeFmt) {
        datePart = dateFmt.format(now);
        timePart = timeFmt.format(now).replace(/\./g, ':');
      } else {
        var days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        datePart = days[now.getDay()] + ', ' + now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
        timePart = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
      }
      clockText.textContent = datePart + ' • ' + timePart + ' WIB';
    }
    tickClock();
    window.setInterval(tickClock, 1000);
  }

  // Reveal-on-scroll: elemen .reveal -> .is-visible (stagger via --reveal-delay)
  // Dipakai berat di halaman Dashboard agar ada motion yang hidup tapi tenang.
  var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealEls = document.querySelectorAll('.reveal');

  if (prefersReduced || !('IntersectionObserver' in window)) {
    revealEls.forEach(function (el) { el.classList.add('is-visible'); });
    return;
  }

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

  revealEls.forEach(function (el) { io.observe(el); });
})();
