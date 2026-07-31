# Catálogo · App para Alma Delia

Webapp en PHP + SQLite para controlar pedidos, pagos y ganancias de Natura, Avon, Fuller y Arabela.

## 1. Requisitos

- PHP 8+ con extensiones: `pdo_sqlite`, `mbstring`, `curl`
- Eso es todo — **no usa Composer ni frameworks**, la librería de PDF (FPDF) ya viene incluida en `vendor/`.

### En Termux (Android)

```bash
pkg install php
# mbstring y curl normalmente ya vienen incluidos en el paquete php de Termux
```

## 2. Configurar Telegram

Edita `config.php` y reemplaza:

```php
define('TELEGRAM_BOT_TOKEN', 'TU_BOT_TOKEN_AQUI');
define('TELEGRAM_CHAT_ID', 'TU_CHAT_ID_AQUI');
```

con el token de tu bot y el `chat_id` al que quieres que lleguen los reportes.

## 3. Correr en local / Termux

Desde la carpeta del proyecto:

```bash
php -S 0.0.0.0:8080
```

Ábrelo en Chrome desde el mismo celular en `http://localhost:8080`, o desde otro dispositivo en la misma red Wi-Fi usando la IP local del teléfono (`http://192.168.x.x:8080`).

## 4. Desplegar en AWS

Cualquiera de estas opciones funciona, ya que la app es PHP + SQLite sin dependencias raras:

**Opción simple (EC2 con PHP embebido):**
```bash
sudo yum install -y php php-cli php-pdo php-mbstring   # Amazon Linux
# o: sudo apt install -y php php-sqlite3 php-mbstring   # Ubuntu
cd /ruta/al/proyecto
php -S 0.0.0.0:8080
```
(usa `nohup php -S 0.0.0.0:8080 &` o un servicio systemd para que quede corriendo en segundo plano)

**Opción con Apache/Nginx:** apunta el document root a la carpeta del proyecto; no necesita configuración especial de reescritura de URLs.

Importante: abre el puerto que uses (ej. 8080) en el Security Group de tu instancia EC2.

## 5. Permisos

La carpeta `data/` debe tener permiso de escritura (ahí vive `catalogo.sqlite`, se crea sola la primera vez que abres la app):

```bash
chmod 775 data
```

## 6. Estructura del proyecto

```
config.php          → credenciales de Telegram, nombre de la dueña
db.php               → conexión SQLite + creación de tablas + marcas base
helpers.php          → funciones compartidas (cálculo de totales, formato de dinero)
index.php            → pantalla de inicio con los 5 widgets
marca.php            → campañas de una marca
campana.php          → tabla de clientas/encargos/pagos de una campaña
global.php           → estadísticas y gráficas de todas las marcas
export_pdf.php       → genera el PDF de una campaña (FPDF, sin Composer)
export_telegram.php  → envía el resumen de una campaña al bot de Telegram
api/                 → endpoints JSON para crear/editar/eliminar clientas, productos, campañas, encargos y pagos
assets/              → CSS (tema oscuro) y JS de la interfaz
vendor/fpdf.php      → librería de PDF (un solo archivo, sin dependencias)
data/catalogo.sqlite → base de datos (se crea sola en el primer arranque)
```

## 7. Cómo se usa

1. **Inicio:** saludo + 5 widgets (Natura, Avon, Fuller, Arabela y Global).
2. Toca una marca → ves sus campañas (numeradas dinámicamente, tú decides qué números crear).
3. Toca "+ Nueva campaña" para agregar la campaña del mes con su rango de fechas.
4. Toca una campaña → tabla de clientas con sus encargos.
   - Cada clienta puede tener **varios encargos**; cada uno aparece como su propia tarjeta.
   - Todo es editable: descripción, precio, fecha, estado (toca la píldora "Por entregar / Entregado"), y los pagos (puedes agregar tantos como necesites, cada uno editable y eliminable).
   - El saldo y el "liquidado" se calculan solos — nunca hay que hacer cuentas a mano.
5. Al crear un encargo puedes elegir el producto de tu inventario (autocompleta el precio) o escribirlo libre.
6. Botones inferiores en cada campaña: **↓ PDF** (descarga el reporte) y **↗ Telegram** (envía el resumen al chat configurado).
7. Widget **Global**: total de pedidos, por cobrar y ganado de las 4 marcas juntas, más gráficas por marca y por campaña.

## 8. Inventario

Ya tiene su propia pantalla, accesible desde el widget "Inventario" al final del inicio (o `inventario.php`):

- Agregar producto: nombre, marca (selección) y campaña (se llena sola según la marca elegida), precio y stock opcionales.
- Todo editable/eliminable igual que los encargos.
- Filtro por marca en la parte superior.
- Exporta a PDF y envía resumen a Telegram, igual que en las campañas.
- Al crear un encargo, el `<select>` de producto sigue llenándose desde este inventario.

## 10. Clientas

Widget "👤 Clientas" al final del inicio (o `clientas.php`):

- Lista de todas las clientas ordenada por **quién debe más primero** (las que están al corriente se van al final), con buscador por nombre o alias.
- Al tocar una clienta: su estado de cuenta completo — todos sus encargos de **todas las marcas y campañas**, cada uno editable/eliminable igual que en la vista de campaña, con sus pagos.
- Totales arriba: comprado, pagado y saldo.
- Exporta a PDF y envía el estado de cuenta a Telegram — útil para mandárselo directo a la clienta o para tu control.

## 9. Nota sobre recursos externos

La app usa dos cosas que originalmente venían de internet: la librería de gráficas (Chart.js) y las tipografías (Google Fonts). Chart.js ya viene incluida localmente en `assets/js/vendor/` — no depende de conexión a internet ni de que tu red permita acceder a CDNs externos (por eso antes no se veían las gráficas en algunas redes). Las tipografías sí se cargan desde Google Fonts vía CSS; si tu red las bloquea, la app simplemente usa la tipografía por defecto del sistema — no rompe nada, solo cambia la letra. Si quieres que también funcionen sin internet, se pueden descargar localmente igual que se hizo con Chart.js.

