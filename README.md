# Planeta Gamer V2

Sistema Laravel 13 / Filament 5 para Cajas, Tarjetas, Préstamos, Accesorios, Notificaciones y Reportes.

## Instalación nueva en Hostinger Business

Preparación C374: PHP 8.5 y SSH confirmados por el propietario. El código está preparado; estos pasos deben ejecutarse en el servidor. No se ha conectado ni desplegado automáticamente en Hostinger.

### 1. Sitio y base privados

- Clonar la rama `master` del repositorio autorizado. Configurar la raíz del dominio exclusivamente en `public/`, nunca en la raíz Laravel. Si hPanel no permite escogerla, configurar una estructura equivalente que mantenga código, `.env`, vendor y storage privado fuera del directorio servido; no copiar todo a public_html.
- Crear una base MySQL/MariaDB nueva y un usuario exclusivo desde hPanel, con permisos para las migraciones e índices, claves foráneas y triggers de integridad. No importar la SQLite local ni respaldos con operaciones.
- Confirmar PHP 8.5 tanto web como SSH (`php -v` puede apuntar a otra versión). Verificar las extensiones con Composer, incluyendo PDO MySQL, intl, mbstring, GD, XML/DOM, fileinfo, curl y ZIP según requisitos del lock.
- Migraciones y bootstrap verificados en MariaDB 10.4.32 aislado con `explicit_defaults_for_timestamp=ON`. Verificar `SELECT VERSION(), @@explicit_defaults_for_timestamp;` en el servidor. Las versiones antiguas con ese ajuste apagado pueden rechazar columnas timestamp. No desactivar el modo estricto ni modificar migraciones aplicadas para ocultarlo: confirmar soporte/configuración con el proveedor antes de continuar.
- Crear `.env` privado desde `.env.example`, con credenciales propias, y permisos de escritura para `storage` y `bootstrap/cache` (no usar permisos universales 777).

Valores de producción que deben configurarse **privadamente** (los marcadores no son credenciales):

```dotenv
APP_NAME="Planeta Gamer"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://TU_DOMINIO
DB_CONNECTION=mysql
DB_HOST=HOST_DE_HPANEL
DB_PORT=3306
DB_DATABASE=BASE_NUEVA_DE_HPANEL
DB_USERNAME=USUARIO_DE_HPANEL
DB_PASSWORD=CONTRASENA_PRIVADA
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
TELEGRAM_TRANSPORT_ENABLED=false
TELEGRAM_SCHEDULED_WORKER_ENABLED=false
CASH_BOX_SCHEDULE_CONTROLS_ENABLED=false
```

No copiar APP_KEY, tokens, contraseñas ni datos del entorno local. Mantener Telegram y control horario pausados durante la puesta en marcha. El ajuste de horario de pantalla puede prevalecer más adelante; en esta base nueva no hay ajustes ni turnos asignados.

### 2. Dependencias, recursos y migraciones

En SSH, desde la carpeta del proyecto, usar la ruta real del PHP 8.5 comprobado. Estos comandos usan `$PHP` como variable de shell y `composer.phar` como ruta de ejemplo; sustituir por las rutas disponibles en el hosting.

```bash
PHP=/RUTA/AL/PHP85
"$PHP" -v
"$PHP" /RUTA/composer.phar install --no-dev --optimize-autoloader --no-interaction
"$PHP" /RUTA/composer.phar check-platform-reqs --no-dev
"$PHP" artisan key:generate --force
"$PHP" artisan migrate --force
"$PHP" artisan planeta:instalar
"$PHP" artisan storage:link
"$PHP" artisan filament:assets
```

`planeta:instalar` es interactivo: solicita el correo de Giovanni y la contraseña dos veces, oculta y de mínimo 12 caracteres. No acepta contraseñas en argumentos. Si encuentra datos, cancela sin borrarlos. Instala en una transacción:

- Un administrador **Giovanni**, roles y permisos de los módulos.
- Los **16 cortes y tres precios aprobados** de Entel, Tigo y Viva, incluidas Cabina Viva de Bs 40 y Bs 100; flags de mayoreo conservados.
- Stock y efectivo **cero**, tienda pendiente de conteo inicial.
- Ningún cliente, préstamo, venta, caja operativa, deuda, saldo digital, notificación ni movimiento de prueba.

Una interrupción revierte la transacción; repetir después de un éxito no crea usuarios o catálogos duplicados ni cambia la contraseña. El seeder general ya no crea Test User ni credenciales públicas. No usar `composer setup`, `migrate:fresh`, `db:wipe` ni seeders operativos en producción.

Compilar los recursos con `npm ci && npm run build` en un equipo de construcción o en el hosting si dispone de Node compatible. Si se compilan fuera, transferir `public/build` del mismo commit. No se versionan vendor, node_modules ni build. Después:

```bash
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
```

Generar APP_KEY **solo en la instalación nueva**, nunca al actualizar. Conservarla en un respaldo privado.

### 3. Programador y Telegram en hosting compartido

Configurar una única tarea cron por minuto con la ruta absoluta correcta (hPanel debe ejecutar PHP 8.5):

```cron
* * * * * cd /RUTA/PROYECTO && /RUTA/AL/PHP85 artisan schedule:run >> /RUTA/PRIVADA/scheduler.log 2>&1
```

Inicialmente conservar ambas banderas Telegram en `false`. No se envían mensajes ni se requiere un daemon permanente. El programador conserva las tareas existentes; revisar sus efectos antes de habilitar reglas de horario.

Cuando Administración configure bots, destinos comprobados y eventos, puede habilitar transporte y `TELEGRAM_SCHEDULED_WORKER_ENABLED=true`, regenerar `config:cache` y activar explícitamente cada bot desde la pantalla. El trabajador acotado procesa solo conexión/cola `telegram`, termina al vaciarse o tras 45 s, timeout por trabajo 30 s, un intento de worker y bloqueo de solapamiento de 3 minutos. Los reintentos e idempotencia siguen gestionados por el historial de entregas existente. Un trabajo iniciado puede terminar después del límite global; el mutex evita ejecutar otro simultáneo.

No ejecutar a la vez el worker acotado y otro worker permanente para la misma cola. Confirmar en el hosting que cron, locks, procesos hijos, conectividad HTTPS y duración permitida funcionan. Mantener los tokens fuera del repositorio. Tras activarlo, revisar historial y logs privados; no mandar una prueba real sin autorización.

Las migraciones históricas se conservan. La compatibilidad C374 añade un índice temporal antes de sustituir el índice de cierres y lo retira al terminar, evitando índices duplicados en el esquema final. Los nombres automáticos que superen los 64 caracteres de MySQL se acortan con un hash estable; nombres cortos y SQLite no cambian. No se han aplicado estas migraciones a la base local operativa.

### 4. Verificación y actualizaciones

Entrar por HTTPS como Giovanni, configurar su PIN desde el perfil y comprobar permisos. Registrar inventario y capitales reales únicamente cuando el propietario lo decida. La instalación no inventa efectivo, stock ni saldo Yape.

Antes de actualizar: respaldar privadamente base, archivos subidos y APP_KEY; revisar migraciones y commit; poner mantenimiento cuando corresponda; instalar desde lock, compilar recursos, `migrate --force` y regenerar cachés. No volver a ejecutar el bootstrap sobre una base con datos. Para revertir, conservar código y respaldo compatibles; no hacer rollback destructivo automático de movimientos.

## Seguridad y pruebas

Parches C374: Livewire 4.4.8, Symfony Mime 8.1.10, PHPUnit 13.4.5 y Pest 5.3.5 con sus dependencias compatibles. Auditoría Composer sin avisos de vulnerabilidad; conserva dos advertencias de paquetes de desarrollo abandonados, que no son dependencias productivas ni se reemplazan en esta entrega.

```bash
php artisan test --compact
php artisan test --compact tests/Feature/EmptyInstallationTest.php
composer audit
npm audit --omit=dev
```

Usar PHP 8.5. Las pruebas normales usan SQLite en memoria; nunca cambiar su conexión a una base operativa. Las pruebas de MariaDB deben usar una base aislada y desechable. No probar cajas ni envíos sobre datos reales.

No publicar `.env`, credenciales, SQLite/SQL, respaldos ni `storage/app/private`. GitHub entrega código y catálogo inicial; no contiene una copia de la base de datos ni un acceso con contraseña predeterminada.
