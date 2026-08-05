<?php
error_reporting(E_ALL);
require_once __DIR__ . '/class.Database.php';

if (0 > version_compare(PHP_VERSION, '5')) {
    die('Este archivo fue generado para PHP 5 o superior');
}

class participantes
{
    public function __construct()
    {
    }

    private function consulta_base()
    {
        return "
            SELECT
                p.id AS idparticipante,
                i.nombre_ingenios,
                p.cui_participantes,
                p.nombre_participantes,
                p.puesto_participantes,
                p.area_participantes,
                p.correo_participantes,
                p.grado_academico_participantes,
                p.telefono_participantes
            FROM participantes p
            INNER JOIN ingenios i ON p.ingenio_id = i.id
        ";
    }

    public function consultar_todos()
    {
        $db = Database::getInstancia();
        $sql = $this->consulta_base();
        $sql .= function_exists('cengi_scope_sql_por_nombre_ingenio') ? cengi_scope_sql_por_nombre_ingenio('i', false) : '';
        $sql .= " ORDER BY p.nombre_participantes";

        return $db->ejecutar_idu($sql);
    }

    public function consultar_visibles()
    {
        $db = Database::getInstancia();
        $sql = $this->consulta_base() . " WHERE p.estado_participantes = 1";
        $sql .= function_exists('cengi_scope_sql_por_nombre_ingenio') ? cengi_scope_sql_por_nombre_ingenio('i', true) : '';
        $sql .= " ORDER BY p.nombre_participantes";

        return $db->ejecutar_idu($sql);
    }

    public function getParticipantesByNombre($nombre)
    {
        $db = Database::getInstancia();
        $nombre = $db->escape($nombre);

        $sql = $this->consulta_base() . "
            WHERE p.estado_participantes = 1
              AND p.nombre_participantes LIKE '%{$nombre}%'
        ";
        $sql .= function_exists('cengi_scope_sql_por_nombre_ingenio') ? cengi_scope_sql_por_nombre_ingenio('i', true) : '';
        $sql .= " ORDER BY p.nombre_participantes";

        return $db->ejecutar_idu($sql);
    }
}
?>
