(() => {
    const sidebar = document.getElementById('visits-sidebar');
    const toggle = document.getElementById('visits-menu-toggle');
    const backdrop = document.getElementById('visits-nav-backdrop');
    if (!sidebar || !toggle || !backdrop) return;
    const mobile = window.matchMedia('(max-width: 1024px)');
    const focusables = () => [...sidebar.querySelectorAll('a[href],button:not([disabled])')].filter(el => !el.hidden);
    const setOpen = (open, restoreFocus = false) => {
        sidebar.classList.toggle('is-open', open);
        sidebar.inert = mobile.matches && !open;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Cerrar navegación' : 'Abrir navegación');
        backdrop.hidden = !open;
        document.body.classList.toggle('visits-nav-open', open);
        if (open) focusables()[0]?.focus();
        else if (restoreFocus) toggle.focus();
    };
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true', true));
    backdrop.addEventListener('click', () => setOpen(false, true));
    document.addEventListener('keydown', event => {
        if (toggle.getAttribute('aria-expanded') !== 'true') return;
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false, true); }
        if (event.key === 'Tab') {
            const controls = focusables(), first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    mobile.addEventListener('change', () => setOpen(false));
    setOpen(false);
    const titles = {solicitudes: 'Solicitudes de visitas', reportes: 'Reportes de visitantes', contactos: 'Contactos', areas: 'Áreas de visita', basedatos: 'Directorio de solicitantes', roles: 'Roles y permisos'};
    const updateSection = key => {
        document.getElementById('visits-page-title').textContent = titles[key] || 'Visitas';
        sidebar.querySelectorAll('[data-section]').forEach(button => {
            if (button.dataset.section === key) button.setAttribute('aria-current', 'page');
            else button.removeAttribute('aria-current');
        });
    };
    sidebar.addEventListener('click', event => {
        const button = event.target.closest('[data-section]');
        if (button) updateSection(button.dataset.section);
        if (event.target.closest('a,button') && mobile.matches) setOpen(false, true);
    });
    const view = new URLSearchParams(location.search).get('vista') || 'solicitudes';
    if (typeof switchTab === 'function' && sidebar.querySelector('[data-section="' + (Object.hasOwn(titles, view) ? view : 'solicitudes') + '"]')) {
        switchTab(Object.hasOwn(titles, view) ? view : 'solicitudes');
        updateSection(Object.hasOwn(titles, view) ? view : 'solicitudes');
    }
})();
