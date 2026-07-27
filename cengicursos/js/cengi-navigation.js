(function () {
  if (window.__cengiFastNavBooted) {
    return;
  }
  window.__cengiFastNavBooted = true;

  var progressId = 'cengi-progress-bar';
  var prefetchCache = Object.create(null);
  var activeController = null;

  function ensureProgressBar() {
    var bar = document.getElementById(progressId);
    var parent = document.body || document.documentElement;

    if (!parent) {
      return null;
    }

    if (!bar) {
      bar = document.createElement('div');
      bar.id = progressId;
      parent.appendChild(bar);
    }
    return bar;
  }

  function setLoading(isLoading) {
    var bar = ensureProgressBar();
    var body = document.body;

    if (!bar || !body) {
      return;
    }

    if (isLoading) {
      body.classList.add('cengi-nav-loading');
      bar.classList.add('is-visible');
      requestAnimationFrame(function () {
        bar.classList.add('is-active');
      });
    } else {
      body.classList.remove('cengi-nav-loading');
      bar.classList.remove('is-active');
      setTimeout(function () {
        bar.classList.remove('is-visible');
      }, 220);
    }
  }

  function shouldHandleLink(anchor) {
    if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('download')) {
      return false;
    }

    var href = anchor.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) {
      return false;
    }

    var url = new URL(anchor.href, window.location.href);
    if (url.origin !== window.location.origin) {
      return false;
    }

    if (/logout\.php$/i.test(url.pathname) || url.searchParams.get('act') === 'logout') {
      return false;
    }

    return /\/cengicursos\//i.test(url.pathname);
  }

  function fetchPage(url, useCache) {
    if (useCache && prefetchCache[url]) {
      return Promise.resolve(prefetchCache[url]);
    }

    if (activeController) {
      activeController.abort();
    }

    activeController = new AbortController();

    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      signal: activeController.signal,
      headers: {
        'X-Requested-With': 'fetch'
      }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('No se pudo cargar la pagina');
      }
      return response.text();
    }).then(function (html) {
      prefetchCache[url] = html;
      return html;
    });
  }

  function renderPage(html, url, replaceHistory) {
    if (!replaceHistory) {
      history.pushState({ url: url }, '', url);
    } else {
      history.replaceState({ url: url }, '', url);
    }

    document.open();
    document.write(html);
    document.close();
  }

  function navigate(url, replaceHistory) {
    if (url === window.location.href) {
      return;
    }

    setLoading(true);

    fetchPage(url, true)
      .then(function (html) {
        renderPage(html, url, replaceHistory);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }
        window.location.href = url;
      })
      .finally(function () {
        setLoading(false);
      });
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    var anchor = event.target.closest('a');
    if (!shouldHandleLink(anchor)) {
      return;
    }

    event.preventDefault();
    navigate(anchor.href, false);
  });

  document.addEventListener('mouseover', function (event) {
    var anchor = event.target.closest('a');
    if (!shouldHandleLink(anchor)) {
      return;
    }

    var url = anchor.href;
    if (!prefetchCache[url]) {
      fetchPage(url, false).catch(function () {});
    }
  });

  window.addEventListener('popstate', function () {
    navigate(window.location.href, true);
  });

  history.replaceState({ url: window.location.href }, '', window.location.href);
})();
