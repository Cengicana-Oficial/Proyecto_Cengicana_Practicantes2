<?php
require_once __DIR__.'/planificacion_helpers.php';

/** Una fila por instructor; las encuestas se agregan antes de unir asignaciones. */
function cengi_informe_instructores_resumen(PDO $db, int $anio = 0, string $busqueda = ''): array
{
    $params=$anio?[$anio]:[];
    $filtroAnio=$anio?' AND YEAR(c.inicio)=?':'';
    $asignaciones=$db->prepare("SELECT c.instructor_id,c.id AS curso_id,NULL AS modulo_id,c.nombre_cursos AS nombre FROM cursos c
        WHERE c.instructor_id IS NOT NULL $filtroAnio
        UNION ALL
        SELECT m.instructor_id,c.id,cm.id,CONCAT(c.nombre_cursos,' · ',cm.nombre)
        FROM curso_modulos cm JOIN cursos c ON c.id=cm.curso_id JOIN curso_modulo_instructores m ON m.curso_modulo_id=cm.id WHERE 1=1 $filtroAnio
        UNION ALL
        SELECT c.instructor_id,c.id,cm.id,CONCAT(c.nombre_cursos,' · ',cm.nombre)
        FROM curso_modulos cm JOIN cursos c ON c.id=cm.curso_id
        WHERE c.instructor_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM curso_modulo_instructores m WHERE m.curso_modulo_id=cm.id) $filtroAnio");
    $asignaciones->execute(array_merge($params,$params,$params));
    $porInstructor=[];
    foreach($asignaciones->fetchAll(PDO::FETCH_ASSOC) as $a){
        $key=$a['curso_id'].'-'.($a['modulo_id']??0);
        $porInstructor[$a['instructor_id']][$key]=($a['modulo_id']?'Módulo: ':'Curso: ').$a['nombre'];
    }
    $stats=$db->prepare("SELECT e.instructor_id,COUNT(*) AS respuestas,
        AVG((ei.instructor_lenguaje_claro+ei.instructor_material_adecuado+ei.instructor_conocimiento_tema+ei.instructor_respeto_participantes+ei.instructor_puntualidad_objetivos)/5.0) AS nota,
        AVG(ei.recomendaria_instructor) AS recomienda_instructor,
        AVG(ei.recomendaria_contexto) AS recomienda_lugar,
        AVG((ei.tema_relevancia_utilidad+ei.logistica_evento)/2.0)/4.0*100 AS tema_logistica
        FROM evaluaciones_instructor ei JOIN enlaces_evaluacion_instructor e ON e.id=ei.enlace_id
        JOIN cursos c ON c.id=e.curso_id WHERE 1=1 $filtroAnio GROUP BY e.instructor_id");
    $stats->execute($params);$notas=[];
    foreach($stats->fetchAll(PDO::FETCH_ASSOC) as $s)$notas[$s['instructor_id']]=$s;
    $rows=[];
    foreach($db->query('SELECT id,nombre,codigo_catedratico FROM instructores ORDER BY nombre,id')->fetchAll(PDO::FETCH_ASSOC) as $i){
        $items=array_values($porInstructor[$i['id']]??[]);sort($items,SORT_NATURAL|SORT_FLAG_CASE);
        $s=$notas[$i['id']]??[];
        if($anio&&!$items&&!$s)continue;
        // asignaciones: lista (la página la muestra plegada; Excel y PDF la unen en líneas).
        $row=['id'=>(int)$i['id'],'codigo'=>cengi_pp_codigo_instructor($i['id'],$i['codigo_catedratico']), 'nombre'=>$i['nombre'],
            'asignaciones'=>$items, 'respuestas'=>(int)($s['respuestas']??0), 'nota'=>$s['nota']??null,
            'recomienda_instructor'=>$s['recomienda_instructor']??null, 'recomienda_lugar'=>$s['recomienda_lugar']??null,
            'tema_logistica'=>$s['tema_logistica']??null];
        if($busqueda!==''&&mb_stripos($row['codigo'].' '.$row['nombre'].' '.implode(' ',$items),$busqueda)===false)continue;
        $rows[]=$row;
    }
    return $rows;
}
