/* ========================================
   AUTOROLNIK – script.js
   ======================================== */

(function () {
    'use strict';

    /* ----------------------------------
       CSRF TOKEN (generowany po stronie klienta
       jako dodatkowa warstwa; główna walidacja w PHP)
    ---------------------------------- */
    function generateCSRF() {
        const token = Array.from(crypto.getRandomValues(new Uint8Array(24)))
            .map(b => b.toString(16).padStart(2, '0')).join('');
        const field = document.getElementById('csrfToken');
        if (field) {
            field.value = token;
            sessionStorage.setItem('csrf_token', token);
        }
    }

    /* ----------------------------------
       STICKY HEADER
    ---------------------------------- */
    function initHeader() {
        const header = document.querySelector('.site-header');
        if (!header) return;

        const onScroll = () => {
            header.classList.toggle('scrolled', window.scrollY > 40);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ----------------------------------
       HAMBURGER MENU
    ---------------------------------- */
    function initHamburger() {
        const btn = document.getElementById('hamburger');
        const nav = document.getElementById('mainNav');
        if (!btn || !nav) return;

        btn.addEventListener('click', () => {
            const open = nav.classList.toggle('open');
            btn.classList.toggle('active', open);
            btn.setAttribute('aria-expanded', String(open));
            document.body.style.overflow = open ? 'hidden' : '';
        });

        nav.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                nav.classList.remove('open');
                btn.classList.remove('active');
                btn.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            });
        });

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && nav.classList.contains('open')) {
                nav.classList.remove('open');
                btn.classList.remove('active');
                btn.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            }
        });
    }

    /* ----------------------------------
       SCROLL REVEAL
    ---------------------------------- */
    function initReveal() {
        const items = document.querySelectorAll('.reveal');
        if (!items.length) return;

        if (!('IntersectionObserver' in window)) {
            items.forEach(el => el.classList.add('visible'));
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        items.forEach(el => observer.observe(el));
    }

    /* ----------------------------------
       SMOOTH SCROLL dla starszych przeglądarek
    ---------------------------------- */
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (!target) return;
                e.preventDefault();
                const offset = 76;
                const top = target.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top, behavior: 'smooth' });
            });
        });
    }

    /* ----------------------------------
       WALIDACJA FORMULARZA
    ---------------------------------- */
    function validateField(id, errorId, rules) {
        const field = document.getElementById(id);
        const errorEl = document.getElementById(errorId);
        if (!field || !errorEl) return true;

        const val = field.value.trim();
        let msg = '';

        if (rules.required && !val) {
            msg = 'To pole jest wymagane.';
        } else if (rules.minLength && val.length < rules.minLength) {
            msg = `Minimum ${rules.minLength} znaki.`;
        } else if (rules.pattern && !rules.pattern.test(val)) {
            msg = rules.patternMsg || 'Nieprawidłowy format.';
        } else if (rules.min && Number(val) < rules.min) {
            msg = `Minimalna wartość: ${rules.min}.`;
        }

        errorEl.textContent = msg;
        field.classList.toggle('invalid', !!msg);
        return !msg;
    }

    function validateConsent() {
        const cb = document.getElementById('consent');
        const err = document.getElementById('consentError');
        if (!cb || !err) return true;
        const ok = cb.checked;
        err.textContent = ok ? '' : 'Zgoda jest wymagana do wysłania formularza.';
        return ok;
    }

    function validateAll() {
        const r = [
            validateField('name', 'nameError', {
                required: true, minLength: 3,
                pattern: /^[A-Za-zÀ-ÿĄąĆćĘęŁłŃńÓóŚśŹźŻż\s'-]+$/,
                patternMsg: 'Imię i Nazwisko może zawierać tylko litery.'
            }),
            validateField('phone', 'phoneError', {
                required: true,
                pattern: /^[\d\s\+\-\(\)]{7,15}$/,
                patternMsg: 'Podaj poprawny numer telefonu.'
            }),
            validateField('email', 'emailError', {
                required: true,
                pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                patternMsg: 'Podaj poprawny adres e-mail.'
            }),
            validateField('area', 'areaError', {
                required: true, min: 1
            }),
            validateField('crops', 'cropsError', { required: true }),
            validateConsent()
        ];
        return r.every(Boolean);
    }

    /* ----------------------------------
       OBSŁUGA FORMULARZA (AJAX do contact.php)
    ---------------------------------- */
    function initForm() {
        const form = document.getElementById('contactForm');
        if (!form) return;

        ['name', 'phone', 'email', 'area', 'crops'].forEach(id => {
            const field = document.getElementById(id);
            if (field) {
                field.addEventListener('blur', () => {
                    const errorId = id + 'Error';
                    const rules = getFieldRules(id);
                    validateField(id, errorId, rules);
                });
            }
        });

        const consent = document.getElementById('consent');
        if (consent) consent.addEventListener('change', validateConsent);

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            if (!validateAll()) return;

            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoading = submitBtn.querySelector('.btn-loading');
            const successEl = document.getElementById('formSuccess');
            const errorEl = document.getElementById('formError');

            submitBtn.disabled = true;
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline';
            successEl.style.display = 'none';
            errorEl.style.display = 'none';

            const data = new FormData(form);

            try {
                const res = await fetch('contact.php', {
                    method: 'POST',
                    body: data,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const json = await res.json();

                if (json.success) {
                    form.reset();
                    generateCSRF();
                    successEl.style.display = 'block';
                    successEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    errorEl.textContent = json.message || 'Wystąpił błąd. Spróbuj ponownie.';
                    errorEl.style.display = 'block';
                }
            } catch (err) {
                errorEl.textContent = 'Brak połączenia. Zadzwoń do nas bezpośrednio.';
                errorEl.style.display = 'block';
            } finally {
                submitBtn.disabled = false;
                btnText.style.display = 'inline';
                btnLoading.style.display = 'none';
            }
        });
    }

    function getFieldRules(id) {
        const map = {
            name:  { required: true, minLength: 3, pattern: /^[A-Za-zÀ-ÿĄąĆćĘęŁłŃńÓóŚśŹźŻż\s'-]+$/, patternMsg: 'Imię i Nazwisko może zawierać tylko litery.' },
            phone: { required: true, pattern: /^[\d\s\+\-\(\)]{7,15}$/, patternMsg: 'Podaj poprawny numer telefonu.' },
            email: { required: true, pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, patternMsg: 'Podaj poprawny adres e-mail.' },
            area:  { required: true, min: 1 },
            crops: { required: true }
        };
        return map[id] || {};
    }

    /* ----------------------------------
       INIT
    ---------------------------------- */
    document.addEventListener('DOMContentLoaded', () => {
        generateCSRF();
        initHeader();
        initHamburger();
        initReveal();
        initSmoothScroll();
        initForm();
    });

})();
