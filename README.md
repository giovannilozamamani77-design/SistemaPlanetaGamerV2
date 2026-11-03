# Planeta Gamer V2

Sistema administrativo Laravel/Filament para Cajas, Tarjetas, Préstamos, Accesorios, Notificaciones y Reportes.

## Requisitos

- PHP 8.5 recomendado (las dependencias del lock requieren como mínimo PHP 8.4.1).
- Composer 2, extensiones requeridas por Composer y GD para gráficos de reportes.
- Node/npm para compilar los recursos con `npm ci` y `npm run build`.
- Base de datos, almacenamiento privado, cron y ejecución de colas configurados en el servidor.

## Configuración

Instalar dependencias con `composer install` para desarrollo o `composer install --no-dev --optimize-autoloader` para producción. Conservar composer.lock y package-lock.json.

Crear un `.env` privado a partir de `.env.example` y configurar conexión, URL HTTPS y entorno. En una instalación nueva y vacía, generar una APP_KEY propia. No regenerarla en actualizaciones de una instalación con datos.

El directorio público del servidor debe apuntar únicamente a `public/`. Compilar recursos con `npm ci` y `npm run build`; `public/build` y las dependencias no están versionados. Dar permisos adecuados a `storage` y `bootstrap/cache`.

Las migraciones se incluyen; las bases de datos reales, respaldos, archivos privados, tokens y contraseñas no. No importar SQLite directamente en MySQL/MariaDB. Validar primero las migraciones y flujos en una base aislada del motor de destino.

**No ejecutar el DatabaseSeeder general en producción:** todavía crea un usuario de prueba. El administrador inicial y los permisos deben prepararse con un procedimiento seguro de instalación. Nunca usar credenciales predeterminadas públicas.

## Pruebas

`php artisan test --compact`

La configuración de pruebas usa SQLite en memoria. No cambiarla para apuntar a una base operativa. Las pruebas no deben enviar mensajes reales a Telegram ni operar cajas reales.

## Estado del despliegue

Esta publicación de código no instala ni activa el sistema en Hostinger. La instalación prevista es Hostinger Business, PHP 8.5/SSH y una base nueva vacía. Configurar el programador y las colas de acuerdo con los recursos del hosting; no asumir un worker permanente de VPS.

Antes de desplegar, resolver los avisos de seguridad pendientes del lock (Livewire GHSA-mhf7-xp6r-6qrc y Symfony Mime CVE-2026-45070), repetir auditoría/pruebas y validar MySQL/MariaDB. No ejecutar `composer update`, `composer setup`, seeders generales o migraciones destructivas a ciegas sobre producción.

## Seguridad de archivos

No publicar `.env`, credenciales de Giovanni, archivos SQLite/SQL, copias de seguridad ni `storage/app/private`. Los respaldos limpios se entregan por separado y siguen siendo privados. Mantener una copia de seguridad privada del servidor antes de actualizar.
