<?php
/** Portadas propias o ilustraciones locales, sin depender de servicios externos. */
function aula_portadas(PDO $db) {
    static $cache;
    if (!$cache) $cache = new WeakMap();
    if (isset($cache[$db])) return $cache[$db];
    try {
        $q=$db->query('SELECT curso_id,version FROM aula_portadas');
        return $cache[$db]=array_column($q->fetchAll(PDO::FETCH_ASSOC),'version','curso_id');
    } catch (PDOException $e) { return $cache[$db]=[]; }
}
function aula_portada_url(PDO $db, array $curso) {
    $covers=aula_portadas($db);$id=(int)$curso['id'];
    if (isset($covers[$id])) return 'aula_portada.php?curso='.$id.'&v='.rawurlencode($covers[$id]);
    $name=mb_strtolower($curso['nombre_cursos']);
    if(preg_match('/caña|campo|agro|suelo|riego|cultivo|fertil|plaga|agrícola/u',$name)) $theme='campo';
    elseif(preg_match('/seguridad|riesgo|salud|emergencia|prevención/u',$name)) $theme='seguridad';
    elseif(preg_match('/excel|datos|digital|informática|software|tecnolog/u',$name)) $theme='digital';
    else $theme='formacion';
    return 'img/aulas/'.$theme.'.svg';
}
