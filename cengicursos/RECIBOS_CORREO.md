# Recibos contables: acciones por pagador

La bandeja ofrece cuatro botones para cada pago (individual, orden grupal o proforma):

1. Ver solicitud: vista del correo a Centro Financiero con destinatarios, buzón de respuesta, asunto, cursos, monto y adjunto disponible.
2. Enviar solicitud: confirma el envío desde Cengicursos. Las solicitudes confirmadas no se repiten; ante fallos se reintentan los destinatarios pendientes.
3. Cargar recibo: Administración recibe el documento en Outlook y lo guarda con número y fecha. Este paso no envía el recibo al pagador.
4. Correo al pagador: muestra el asunto y adjunto, permite revisar el destinatario y editar el cuerpo, y envía el correo desde Cengicursos. Un error SMTP conserva el documento para reintentar.

Los botones posteriores se habilitan conforme avanza el expediente. La configuración de Centro Financiero y del buzón de Administración está plegada y ordenada en columnas. La opción de solicitudes automáticas es adicional; si se activa, recibos-worker procesa cada minuto los pagos a partir de la fecha configurada. La selección de esa fecha evita solicitar recibos de pagos antiguos.

No se necesita leer Outlook ni compartir la contraseña del buzón. Administración descarga y carga el recibo; Cengicursos realiza los dos envíos cuando corresponde. Los resultados y destinatarios quedan registrados en el historial. Las pruebas usan transporte simulado y tablas temporales, sin correos reales ni cambios en pagos.