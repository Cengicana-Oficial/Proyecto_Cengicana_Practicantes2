let mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel application. By default, we are compiling the Sass
 | file for the application as well as bundling up all the JS files.
 |
 */
mix
    .js('resources/js/app.js', 'public/dist/js').vue()
    .sass('resources/sass/app.scss', 'public/dist/css')
    .alias({
        "@": "resources/js",
    })
    // vendor.js = node_modules, salvo las librerias del visor GeoPDF y del
    // lector GeoTIFF de NDVI, que van en chunks cargados bajo demanda
    // (import() en ensayos/croquis-mapa.vue y ensayos/ndvi-imagenes.vue).
    .extract((module) => {
        const ruta = module.nameForCondition && module.nameForCondition();
        return !!ruta && /[\\/]node_modules[\\/]/.test(ruta)
            && !/[\\/]node_modules[\\/](pdfjs-dist|pdf-lib|@pdf-lib|geotiff|threads|txml|pako)[\\/]/.test(ruta);
    })
    // Worker de pdf.js para el visor de croquis GeoPDF (ver ensayos/geopdf.js).
    .copy('node_modules/pdfjs-dist/legacy/build/pdf.worker.min.js', 'public/dist/js/pdf.worker.min.js')
    .options({
        processCssUrls: true,
    })
    .webpackConfig({
        output: {
            chunkFilename: 'dist/js/chunks/[name].js?id=[chunkhash]',
        },
        externals: {
            jquery: 'jQuery'
        },
        resolve: {
            fallback: {
                fs: false,
                // geotiff solo usa http/https para leer por URL en Node.
                http: false,
                https: false
            }
        }
    });

if (mix.inProduction()) {
    mix.version()
}
else {
    mix.sourceMaps(false, 'source-map')
}
