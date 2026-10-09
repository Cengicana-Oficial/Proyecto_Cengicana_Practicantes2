(function () {
    'use strict';
    var opened = null;
    function close(restoreFocus) {
        if (!opened) return;
        var item = opened;
        opened = null;
        if (item.panel.hidePopover && item.panel.matches(':popover-open')) item.panel.hidePopover();
        item.panel.hidden = true;
        item.button.setAttribute('aria-expanded', 'false');
        if (restoreFocus) item.button.focus();
    }
    document.querySelectorAll('.cengi-row-actions').forEach(function (panel, index) {
        var deleteAction = panel.querySelector('.is-delete');
        if (deleteAction) panel.appendChild(deleteAction);
        var links = panel.querySelectorAll('a, button');
        if (!links.length) return;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'cengi-course-actions-toggle';
        button.innerHTML = '<span class="course-actions-dots" aria-hidden="true">⋯</span> Acciones <span class="course-actions-chevron" aria-hidden="true"></span>';
        button.setAttribute('aria-expanded', 'false');
        panel.id = 'course-actions-' + index;
        button.setAttribute('aria-controls', panel.id);
        var course = panel.closest('tr').querySelector('.cengi-course-name, .col-name');
        if (course) button.setAttribute('aria-label', 'Acciones de ' + course.textContent.trim());
        panel.before(button);
        panel.classList.add('cengi-course-actions-panel');
        var heading = document.createElement('div');
        heading.className = 'course-actions-heading';
        var headingLabel = document.createElement('strong');
        headingLabel.textContent = 'Gestionar curso';
        heading.appendChild(headingLabel);
        if (course) {
            var subtitle = document.createElement('small');
            subtitle.textContent = course.querySelector('strong') ? course.querySelector('strong').textContent : course.textContent.trim();
            heading.appendChild(subtitle);
        }
        panel.prepend(heading);
        panel.hidden = true;
        if (panel.showPopover) panel.setAttribute('popover', 'manual');
        links.forEach(function (link) {
            var label = link.getAttribute('aria-label') || link.getAttribute('data-tooltip');
            link.removeAttribute('data-tooltip');
            link.querySelectorAll('.sr-only').forEach(function (node) { node.remove(); });
            var text = document.createElement('span');
            text.className = 'cengi-course-action-label';
            text.textContent = label;
            link.appendChild(text);
        });
        button.addEventListener('click', function () {
            var same = opened && opened.panel === panel;
            close(false);
            if (same) return;
            panel.hidden = false;
            if (panel.showPopover) panel.showPopover();
            var rect = button.getBoundingClientRect();
            var width = panel.offsetWidth, height = panel.offsetHeight;
            panel.style.left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8)) + 'px';
            panel.style.top = Math.max(8, rect.bottom + height + 8 > window.innerHeight ? rect.top - height - 5 : rect.bottom + 5) + 'px';
            button.setAttribute('aria-expanded', 'true');
            opened = {panel: panel, button: button};
            links[0].focus();
        });
        panel.addEventListener('click', function (event) {
            if (event.target.closest('a,button')) setTimeout(function () { close(false); }, 0);
        });
        panel.addEventListener('keydown', function (event) {
            var position = Array.prototype.indexOf.call(links, document.activeElement);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                links[(position + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length].focus();
            }
        });
    });
    document.addEventListener('click', function (event) {
        if (opened && !opened.panel.contains(event.target) && !opened.button.contains(event.target)) close(false);
    });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && opened) { event.preventDefault(); close(true); } });
    document.addEventListener('focusin', function (event) { if (opened && !opened.panel.contains(event.target) && event.target !== opened.button) close(false); });
    window.addEventListener('resize', function () { close(false); });
    document.addEventListener('scroll', function (event) { if (opened && !opened.panel.contains(event.target)) close(false); }, true);
})();
