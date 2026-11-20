# Fuentes de tarifas bancarias

## Política real

Las tarifas no se publican como oficiales por construcción de URL o extracción de texto. Deben existir fuente registrada, documento descargado, hash real y aprobación/revisión.

## Conector implementado

- HTTPS obligatorio.
- Dominio autorizado.
- Protección básica SSRF por host público resuelto.
- Timeout, reintentos y límite de tamaño.
- HTML/PDF permitidos.
- SHA-256 sobre bytes descargados.
- Almacenamiento local.
- Logs de acceso y errores.

## Evidencia disponible

La prueba de sincronización verifica estructura, versionado, hash rechazado por dominio/HTTP y manejo de logs. No demuestra una descarga real exitosa desde un banco externo en este cierre.

## Fuentes del catálogo

Las URLs registradas en el seeder pueden mostrarse como referencias, pero no deben interpretarse como verificación bancaria completa sin documento descargado y revisión.
