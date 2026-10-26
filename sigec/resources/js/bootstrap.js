window._ = require('lodash');

// Bootstrap 4 / jQuery vienen del skin AdminLTE (public/vendor/adminlte),
// publicados por `php artisan adminlte:install`, no de este bundle.

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

window.axios = require('axios');

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// SIGEC se publica bajo un prefijo (/sigec, ver dockerfiles/modulos-app/sigec-proxy.conf):
// las vistas llaman axios con rutas absolutas ('/investigacion/...'), asi que se
// resuelven contra la URL base que expone el layout (url('/') = APP_URL).
const appBase = document.querySelector('meta[name="app-base"]');
if (appBase && appBase.content) {
    window.axios.defaults.baseURL = appBase.content.replace(/\/+$/, '');
}

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

// import Echo from 'laravel-echo';

// window.Pusher = require('pusher-js');

// window.Echo = new Echo({
//     broadcaster: 'pusher',
//     key: process.env.MIX_PUSHER_APP_KEY,
//     cluster: process.env.MIX_PUSHER_APP_CLUSTER,
//     forceTLS: true
// });
