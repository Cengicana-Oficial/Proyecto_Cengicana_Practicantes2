<?php

require_once __DIR__ . '/../includes/analisis_controller_helper.php';

function lab_generic_controller_run(string $slug): void
{
    lab_controller_render_generic_analysis($slug, __DIR__ . '/../view/analisis_generico_view.php');
}
