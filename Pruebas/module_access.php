<?php
// Read-only integration for the main menu; does not start or modify a session.
function tech_module_has_access(int $userId): bool {
    if ($userId < 1) return false;
    $directory = getenv('TECH_STORAGE_DIR') ?: __DIR__ . '/.docker/private';
    $path = $directory . '/gestion.sqlite';
    if (!is_file($path)) return false;
    try {
        $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        $stmt = $db->prepare("SELECT 1 FROM user_access WHERE user_id=? AND (rol='admin' OR (rol='profesional' AND area<>'' AND area<>'Todas'))");
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Servicios Técnicos: no se pudo consultar el acceso del menú.');
        return false;
    }
}
