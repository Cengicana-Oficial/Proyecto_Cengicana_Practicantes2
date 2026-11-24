<?php
/**
 * Permisos y roles del modulo "Inventario de precursores" (SICIR, carpeta inventario_precursores/).
 *
 * Es la unica lista de permisos del modulo. La usan:
 *  - inventario_precursores/ (comprobar permisos y sembrarlos al desplegar);
 *  - login/config/permisos_roles.php (etiquetas y grupo en Roles y permisos).
 *
 * Nombre de permiso: inventario.<seccion>.<accion>. El rol y los permisos del usuario en el
 * modulo salen de la central (usuario_modulo.rol_id, rol_permiso): ver roles_modulo.php.
 */

const INVENTARIO_MODULO = 'Inventario de precursores';

/** [grupo => [permiso => [etiqueta, descripcion]]], en el orden en que se muestran. */
function permisos_inventario_catalogo()
{
    return [
        'Consulta' => [
            'inventario.tablero.ver' => ['Ver tablero de control', 'Resumen de existencias, alertas y tareas pendientes de Inventario de precursores.'],
            'inventario.vales.ver' => ['Ver vales de salida', 'Bandeja de vales de salida y su detalle (solo los de su laboratorio, salvo que tenga "Ver todos los laboratorios").'],
            'inventario.lotes.ver' => ['Ver control de lotes (FEFO)', 'Estado de cada lote: ingresado, consumido, disponible y caducidad.'],
            'inventario.informes.salidas' => ['Informe de control de salidas', 'Registro de descargas por vale, laboratorio y lote.'],
            'inventario.informes.disponibilidad' => ['Informe de disponibilidad', 'Balance por laboratorio: ingresado, consumido, comprometido y existencia libre.'],
            'inventario.informes.cierre' => ['Informe de cierre mensual', 'Informe mensual de control de precursores químicos para declaración regulatoria.'],
            'inventario.informes.exportar' => ['Descargar documentos en Excel', 'Descargar en Excel los informes y vales disponibles en las páginas a las que tiene acceso.'],
        ],
        'Vales de salida' => [
            'inventario.vales.crear' => ['Crear vale y firmar "Solicitado por"', 'Solicitar reactivos de la matriz de su laboratorio y firmar como solicitante.'],
            'inventario.vales.autorizar' => ['Visto bueno / rechazar (jefe inmediato)', 'Firmar el visto bueno o rechazar los vales de su laboratorio.'],
            'inventario.vales.despachar' => ['Despachar y descontar inventario', 'Entregar el vale en bodega por FEFO, firmar "Entregado por" y descontar el inventario.'],
            'inventario.vales.anular' => ['Anular vales autorizados', 'Anular con motivo un vale autorizado que aún no se despachó.'],
            'inventario.vales.ver_todos' => ['Ver todos los laboratorios', 'Ver y operar la información de todos los laboratorios, no solo la del propio.'],
        ],
        'Inventario' => [
            'inventario.entradas.registrar' => ['Ver y registrar entradas a bodega', 'Consultar ingresos y registrar compras. Cada ingreso genera un lote. Corregir y anular requieren sus propios permisos.'],
            'inventario.entradas.corregir' => ['Corregir entradas (con justificación)', 'Corregir una entrada ya registrada con motivo y firma; el historial conserva el valor original.'],
            'inventario.entradas.anular' => ['Anular entradas sin consumo', 'Anular una entrada cuyo lote aún no tiene consumo.'],
        ],
        'Firmas del cierre mensual' => [
            'inventario.cierre.elaborar' => ['Firmar "Elaborado por"', 'Elaborar y firmar el informe mensual (Encargado de Bodega).'],
            'inventario.cierre.revisar' => ['Firmar "Revisado por"', 'Revisar y firmar el informe mensual (Responsable de Bodega).'],
            'inventario.cierre.aprobar' => ['Firmar "Aprobado por"', 'Aprobar y firmar el informe mensual (Director General).'],
        ],
        'Administración' => [
            'inventario.catalogo.gestionar' => ['Catálogo y matriz de reactivos', 'Crear y editar productos y definir qué laboratorios pueden solicitarlos.'],
            'inventario.laboratorios.gestionar' => ['Laboratorios', 'Crear, editar, desactivar o eliminar laboratorios propietarios de inventario.'],
            'inventario.usuarios.gestionar' => ['Gestionar usuarios del módulo', 'Consultar usuarios y asignarles un laboratorio. Solo el superadministrador puede cambiar el rol de Inventario.'],
        ],
    ];
}

/** [permiso => [etiqueta, descripcion]] sin agrupar. */
function permisos_inventario_todos()
{
    $todos = [];
    foreach (permisos_inventario_catalogo() as $permisos) {
        $todos += $permisos;
    }
    return $todos;
}

/** Roles que se crean al desplegar: [nombre del rol => lista de permisos]. */
function roles_inventario_definicion()
{
    $todos = array_keys(permisos_inventario_todos());
    $consulta = ['inventario.tablero.ver', 'inventario.vales.ver', 'inventario.lotes.ver', 'inventario.informes.salidas', 'inventario.informes.disponibilidad'];
    $modulos = array_merge($consulta, ['inventario.informes.cierre']);

    return [
        'Analista de inventario' => array_merge($consulta, ['inventario.vales.crear']),
        'Jefe de laboratorio (inventario)' => array_merge($modulos, ['inventario.vales.crear', 'inventario.vales.autorizar', 'inventario.informes.exportar']),
        'Encargado de bodega' => array_merge($modulos, [
            'inventario.vales.despachar', 'inventario.vales.anular', 'inventario.vales.ver_todos', 'inventario.entradas.registrar',
            'inventario.entradas.corregir', 'inventario.informes.exportar', 'inventario.cierre.elaborar',
        ]),
        'Responsable de bodega' => array_merge($modulos, [
            'inventario.vales.despachar', 'inventario.vales.anular', 'inventario.vales.ver_todos', 'inventario.entradas.registrar',
            'inventario.entradas.corregir', 'inventario.entradas.anular', 'inventario.informes.exportar', 'inventario.cierre.elaborar',
            'inventario.cierre.revisar', 'inventario.catalogo.gestionar', 'inventario.laboratorios.gestionar',
        ]),
        'Director general (inventario)' => array_merge($modulos, ['inventario.vales.ver_todos', 'inventario.informes.exportar', 'inventario.cierre.aprobar']),
        // Roles genericos de la plataforma: acceso total (el superadmin ya pasa todos los controles).
        'Superadmin' => $todos,
        'Administrador' => $todos,
    ];
}

/** Roles de la lista anterior que solo sirven en este modulo (los demas son genericos). */
function roles_inventario_propios()
{
    return array_values(array_diff(array_keys(roles_inventario_definicion()), ['Superadmin', 'Administrador']));
}
