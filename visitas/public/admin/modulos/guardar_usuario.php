<?php
// Las cuentas pertenecen al portal; el módulo solo administra su acceso.
http_response_code(410);
exit('Los usuarios se administran en el portal. Para asignar acceso utiliza Roles y permisos de Visitas.');
