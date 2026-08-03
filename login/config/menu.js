(function () {
    var header = document.getElementById('appHeader');
    var menuButton = document.getElementById('menuBtn');
    var mainNav = document.getElementById('mainNav');
    var details = document.querySelectorAll('.nav-dropdown, .user-menu');

    function closeNavigation() {
        if (!header || !menuButton) return;
        header.classList.remove('is-menu-open');
        menuButton.setAttribute('aria-expanded', 'false');
    }

    if (header && menuButton) {
        menuButton.addEventListener('click', function () {
            var willOpen = !header.classList.contains('is-menu-open');
            header.classList.toggle('is-menu-open', willOpen);
            menuButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
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
        Array.prototype.forEach.call(details, function (item) {
            if (!item.contains(event.target)) item.removeAttribute('open');
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        closeNavigation();
        Array.prototype.forEach.call(details, function (item) {
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
