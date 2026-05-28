/**
 * NETXIA 2026 — main.js
 * Scroll reveals · Nav · Multi-step form · Blog loader
 */
'use strict';

// ── Helpers ──────────────────────────────────────────────────────────
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
const on = (el, ev, fn) => el?.addEventListener(ev, fn);

// ── Scroll Reveal ─────────────────────────────────────────────────────
(function initReveal() {
  const io = new IntersectionObserver(
    (entries) => entries.forEach(e => {
      if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); }
    }),
    { threshold: 0.12 }
  );
  $$('.reveal').forEach(el => io.observe(el));
})();

// ── Header scroll state ───────────────────────────────────────────────
(function initHeader() {
  const header = $('.site-header');
  if (!header) return;
  const update = () => header.classList.toggle('scrolled', window.scrollY > 40);
  window.addEventListener('scroll', update, { passive: true });
  update();
})();

// ── Active nav link ────────────────────────────────────────────────────
(function initActiveNav() {
  const sections = $$('section[id]');
  const links    = $$('.nav-links a[href^="#"]');
  if (!sections.length) return;

  const io = new IntersectionObserver(
    entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          links.forEach(l => l.classList.remove('active'));
          const link = $(`a[href="#${e.target.id}"]`);
          link?.classList.add('active');
        }
      });
    },
    { rootMargin: '-40% 0px -55% 0px' }
  );
  sections.forEach(s => io.observe(s));
})();

// ── Mobile nav ─────────────────────────────────────────────────────────
(function initMobileNav() {
  const btn   = $('.hamburger');
  const nav   = $('.mobile-nav');
  if (!btn || !nav) return;

  const toggle = () => {
    const open = btn.classList.toggle('open');
    nav.classList.toggle('open', open);
    document.body.style.overflow = open ? 'hidden' : '';
  };
  on(btn, 'click', toggle);
  $$('.mobile-nav a').forEach(a => on(a, 'click', () => {
    btn.classList.remove('open');
    nav.classList.remove('open');
    document.body.style.overflow = '';
  }));
})();

// ── Hero particle canvas ───────────────────────────────────────────────
(function initParticles() {
  const canvas = $('#heroCanvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let W, H, particles;

  const resize = () => {
    W = canvas.width  = canvas.offsetWidth;
    H = canvas.height = canvas.offsetHeight;
  };

  const mkParticle = () => ({
    x: Math.random() * W,
    y: Math.random() * H,
    r: Math.random() * 1.5 + 0.5,
    vx: (Math.random() - 0.5) * 0.3,
    vy: (Math.random() - 0.5) * 0.3,
    a: Math.random() * 0.5 + 0.1,
  });

  const init = () => {
    resize();
    particles = Array.from({ length: 80 }, mkParticle);
  };

  const draw = () => {
    ctx.clearRect(0, 0, W, H);
    particles.forEach(p => {
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = W; if (p.x > W) p.x = 0;
      if (p.y < 0) p.y = H; if (p.y > H) p.y = 0;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(0,210,255,${p.a})`;
      ctx.fill();
    });
    // draw connecting lines
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx = particles[i].x - particles[j].x;
        const dy = particles[i].y - particles[j].y;
        const dist = Math.sqrt(dx*dx + dy*dy);
        if (dist < 90) {
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = `rgba(0,210,255,${0.06 * (1 - dist/90)})`;
          ctx.lineWidth = 0.5;
          ctx.stroke();
        }
      }
    }
    requestAnimationFrame(draw);
  };

  init();
  draw();
  window.addEventListener('resize', () => { resize(); }, { passive: true });
})();

// ── Animated counters ──────────────────────────────────────────────────
(function initCounters() {
  const els = $$('[data-count]');
  if (!els.length) return;

  const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el     = e.target;
      const target = +el.dataset.count;
      const suffix = el.dataset.suffix || '';
      const dur    = 2000;
      const step   = target / (dur / 16);
      let cur = 0;
      const timer = setInterval(() => {
        cur = Math.min(cur + step, target);
        el.textContent = Math.round(cur) + suffix;
        if (cur >= target) clearInterval(timer);
      }, 16);
      io.unobserve(el);
    });
  }, { threshold: 0.5 });
  els.forEach(el => io.observe(el));
})();

// ── Blog loader ─────────────────────────────────────────────────────────
(function initBlog() {
  const grid = $('#blogGrid');
  if (!grid) return;

  fetch('./data/blog.json')
    .then(r => r.json())
    .then(articles => {
      const icons = { 'Inteligencia Artificial': '🤖', 'Ciberseguridad': '🔐', 'Cloud & DevOps': '☁️' };
      grid.innerHTML = articles.map((a, i) => `
        <article class="blog-card reveal reveal-delay-${i+1}">
          <div class="blog-card-img" role="img" aria-label="${a.imagen_alt}">
            <span style="position:relative;z-index:1">${icons[a.categoria] || '📝'}</span>
          </div>
          <div class="blog-card-body">
            <div class="blog-meta">
              <span class="blog-cat">${a.categoria}</span>
              <span class="blog-date">${formatDate(a.fecha)}</span>
            </div>
            <h3>${a.titulo}</h3>
            <p>${a.resumen}</p>
            <a href="${a.url}" class="blog-card-link" aria-label="Leer: ${a.titulo}">
              Leer artículo <span aria-hidden="true">→</span>
            </a>
          </div>
        </article>
      `).join('');
      // Re-observe new elements
      const io = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
      }, { threshold: 0.12 });
      $$('.blog-card.reveal').forEach(el => io.observe(el));
    })
    .catch(() => {
      grid.innerHTML = '<p style="color:var(--text-2);text-align:center">No se pudo cargar el blog.</p>';
    });

  function formatDate(str) {
    const d = new Date(str);
    return d.toLocaleDateString('es-CL', { day:'numeric', month:'long', year:'numeric' });
  }
})();

// ── Requirements multi-step form ─────────────────────────────────────────
(function initRequirementsForm() {
  const form    = $('#requirementsForm');
  if (!form) return;

  const panels  = $$('.form-panel', form);
  const dots    = $$('.form-step-dot', form);
  const lines   = $$('.form-step-line', form);
  const alertEl = $('#reqAlert');
  const success = $('#reqSuccess');
  let step      = 0;
  let csrf      = '';

  // Fetch CSRF token
  fetch('./php/csrf.php')
    .then(r => r.json())
    .then(d => { csrf = d.token; })
    .catch(() => {});

  const showStep = (n) => {
    panels.forEach((p, i) => p.classList.toggle('active', i === n));
    dots.forEach((d, i) => {
      d.classList.toggle('active', i === n);
      d.classList.toggle('done', i < n);
    });
    lines.forEach((l, i) => l.classList.toggle('done', i < n));
    step = n;
  };
  showStep(0);

  // Validate current step fields
  const validateStep = () => {
    const panel = panels[step];
    let valid = true;
    $$('input[required], textarea[required], select[required]', panel).forEach(el => {
      el.classList.remove('error');
      if (!el.value.trim()) { el.classList.add('error'); valid = false; }
    });
    const emailEl = $('input[type=email]', panel);
    if (emailEl && emailEl.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailEl.value)) {
      emailEl.classList.add('error'); valid = false;
    }
    return valid;
  };

  on($('#reqNext1', form), 'click', () => { if (validateStep()) showStep(1); });
  on($('#reqNext2', form), 'click', () => { if (validateStep()) showStep(2); });
  on($('#reqBack1', form), 'click', () => showStep(0));
  on($('#reqBack2', form), 'click', () => showStep(1));

  on(form, 'submit', async (e) => {
    e.preventDefault();
    if (!validateStep()) return;

    const btn = $('[type=submit]', form);
    btn.disabled = true;
    btn.textContent = 'Enviando…';

    const fd = new FormData(form);
    fd.set('csrf_token', csrf);

    try {
      const res = await fetch('./php/submit_requirements.php', { method:'POST', body: fd });
      const data = await res.json();

      if (data.success) {
        form.style.display = 'none';
        success.classList.add('show');
      } else {
        showAlert(data.message || 'Error al enviar. Intenta de nuevo.');
      }
    } catch {
      showAlert('Error de conexión. Intenta de nuevo.');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Enviar Requerimiento';
    }
  });

  function showAlert(msg) {
    alertEl.textContent = msg;
    alertEl.className = 'alert error show';
    setTimeout(() => alertEl.className = 'alert', 5000);
  }
})();

// ── Job application form ─────────────────────────────────────────────────
(function initJobForm() {
  const form    = $('#jobForm');
  if (!form) return;

  const alertEl = $('#jobAlert');
  const success = $('#jobSuccess');
  let csrf      = '';

  fetch('./php/csrf.php')
    .then(r => r.json())
    .then(d => { csrf = d.token; })
    .catch(() => {});

  // File upload drag & drop
  const dropArea = $('.file-upload-area');
  const fileInput = $('input[type=file]', form);
  const fileDisplay = $('.file-name-display');

  if (dropArea && fileInput) {
    on(dropArea, 'click', () => fileInput.click());
    on(dropArea, 'dragover', e => { e.preventDefault(); dropArea.classList.add('drag-over'); });
    on(dropArea, 'dragleave', () => dropArea.classList.remove('drag-over'));
    on(dropArea, 'drop', e => {
      e.preventDefault();
      dropArea.classList.remove('drag-over');
      if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    });
    on(fileInput, 'change', () => { if (fileInput.files[0]) setFile(fileInput.files[0]); });
  }

  function setFile(f) {
    if (f.size > 2 * 1024 * 1024) {
      showAlert('El CV no puede superar 2 MB.'); return;
    }
    if (fileDisplay) {
      fileDisplay.textContent = `✓ ${f.name}`;
      fileDisplay.style.display = 'block';
    }
    // Transfer to input
    const dt = new DataTransfer();
    dt.items.add(f);
    fileInput.files = dt.files;
  }

  // Scroll to form when clicking on a job item
  $$('.job-item').forEach(item => {
    on(item, 'click', () => {
      const cargo = item.querySelector('.job-title')?.textContent;
      const sel   = $('#cargoSelect', form);
      if (sel && cargo) {
        $$('option', sel).forEach(o => {
          if (o.textContent.includes(cargo.split('–')[0].trim())) sel.value = o.value;
        });
      }
      form.scrollIntoView({ behavior:'smooth', block:'center' });
    });
  });

  on(form, 'submit', async (e) => {
    e.preventDefault();
    const btn = $('[type=submit]', form);

    const required = $$('input[required], textarea[required], select[required]', form);
    let valid = true;
    required.forEach(el => {
      el.classList.remove('error');
      if (!el.value.trim()) { el.classList.add('error'); valid = false; }
    });
    if (!valid) { showAlert('Completa los campos obligatorios.'); return; }

    btn.disabled = true;
    btn.textContent = 'Enviando…';

    const fd = new FormData(form);
    fd.set('csrf_token', csrf);

    try {
      const res = await fetch('./php/submit_job.php', { method:'POST', body: fd });
      const data = await res.json();
      if (data.success) {
        form.style.display = 'none';
        success.classList.add('show');
      } else {
        showAlert(data.message || 'Error al enviar.');
      }
    } catch {
      showAlert('Error de conexión.');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Enviar Postulación';
    }
  });

  function showAlert(msg) {
    alertEl.textContent = msg;
    alertEl.className = 'alert error show';
    setTimeout(() => alertEl.className = 'alert', 5000);
  }
})();

// ── Progress bar animation in hero card ────────────────────────────────
(function initProgressBars() {
  $$('.progress-fill').forEach(bar => {
    const target = bar.dataset.width || '75';
    setTimeout(() => { bar.style.width = target + '%'; }, 600);
  });
})();
