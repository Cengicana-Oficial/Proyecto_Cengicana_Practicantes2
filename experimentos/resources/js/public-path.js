// SIGEC se sirve bajo un prefijo (/sigec): los chunks cargados con import()
// (p. ej. el visor GeoPDF) deben pedirse a esa URL base, no a la raiz del dominio.
const base = document.querySelector('meta[name="app-base"]');
if (base && base.content) {
    // eslint-disable-next-line no-undef, camelcase
    __webpack_public_path__ = base.content.replace(/\/+$/, '') + '/';
}
