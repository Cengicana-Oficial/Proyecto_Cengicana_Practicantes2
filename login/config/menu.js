(function () {
    var header = document.getElementById('appHeader');
    var menuButton = document.getElementById('menuBtn');
    var mainNav = document.getElementById('mainNav');
    var details = document.querySelectorAll('.nav-dropdown, .user-menu');
    var search = document.getElementById('systemSearch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.system-card'));
    function normalize(value) {
        return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }
    function filterSystems() {
        var query = normalize(search.value);
        var visible = 0;
        cards.forEach(function (card) {
            card.hidden = !normalize(card.querySelector('strong').textContent).includes(query);
            if (!card.hidden) visible++;
        });
        document.getElementById('systemResults').textContent = visible + ' de ' + cards.length + ' sistemas';
        document.getElementById('systemsEmpty').hidden = visible !== 0;
    }
    if (search && cards.length) {
        document.getElementById('systemsToolbar').hidden = false;
        search.addEventListener('input', filterSystems);
        document.getElementById('clearSystemSearch').addEventListener('click', function () {
            search.value = '';
            filterSystems();
            search.focus();
        });
        filterSystems();
    }

    function closeNavigation() {
        if (!header || !menuButton) return;
        header.classList.remove('is-menu-open');
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.setAttribute('aria-label', 'Abrir menú');
    }

    if (header && menuButton) {
        menuButton.addEventListener('click', function () {
            var willOpen = !header.classList.contains('is-menu-open');
            header.classList.toggle('is-menu-open', willOpen);
            menuButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menuButton.setAttribute('aria-label', willOpen ? 'Cerrar menú' : 'Abrir menú');
        });
    }

    Array.prototype.forEach.call(details, function (item) {
        item.addEventListener('toggle', function () {
            if (!item.open) return;

            Array.prototype.forEach.call(details, function (other) {
                if (other !== item) other.removeAttribute('open');
            });
        });
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('a[aria-disabled="true"]')) event.preventDefault();
        Array.prototype.forEach.call(details, function (item) {
            if (!item.contains(event.target)) item.removeAttribute('open');
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (header.classList.contains('is-menu-open') && mainNav.contains(document.activeElement)) menuButton.focus();
        closeNavigation();
        Array.prototype.forEach.call(details, function (item) {
            if (item.open && item.contains(document.activeElement)) item.querySelector('summary').focus();
            item.removeAttribute('open');
        });
    });

    if (mainNav) {
        mainNav.addEventListener('click', function (event) {
            var link = event.target.closest('a');
            if (!link) return;

            if (link.getAttribute('href') === '#') {
                event.preventDefault();
                return;
            }

            closeNavigation();
        });
    }
})();
