USE usuarios_menu;

ALTER TABLE cengisalas_module_access
  MODIFY role_code ENUM('user','payments','admin','manager','asistente_direccion','director_general','coordinador_visitas','gestor_pagos') NOT NULL;
