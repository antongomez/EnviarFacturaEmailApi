# EnviarFacturaEmailApi

<p align="center">
  <img src="https://img.shields.io/badge/FacturaScripts-2025.2+-2670c9?style=for-the-badge&logoColor=white" alt="FacturaScripts 2025.2+">
  <img src="https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.0+">
  <img src="https://img.shields.io/github/license/antongomez/EnviarFacturaEmailApi?style=for-the-badge" alt="License">
  <img src="https://img.shields.io/badge/REST-API-orange?style=for-the-badge&logo=fastapi&logoColor=white" alt="REST API">
  <a href="https://github.com/antongomez/EnviarFacturaEmailApi/releases/latest"><img src="https://img.shields.io/github/v/release/antongomez/EnviarFacturaEmailApi?style=for-the-badge&logo=github&logoColor=white" alt="Latest Release"></a>
</p>

Plugin para [FacturaScripts](https://www.facturascripts.com/) que añade un endpoint a la API REST para **enviar facturas de cliente por email con su PDF adjunto**.

El envío reutiliza la configuración de la propia instancia de FacturaScripts: la identidad SMTP, la plantilla de correo `sendmail-FacturaCliente`, el historial de emails y el sistema de exportación de PDF. No depende de servicios externos.

## Características

- Endpoint REST autenticado por API key: `POST /api/3/enviarFacturaCliente/{idfactura}`.
- Genera el PDF de la factura y lo adjunta automáticamente al correo.
- Resuelve el destinatario siguiendo la misma jerarquía que el envío manual de la interfaz:
  email forzado por parámetro → email de la factura → contacto de facturación → cliente.
- Usa el asunto y cuerpo de la notificación `sendmail-FacturaCliente` si existe; en caso contrario, recurre a las traducciones del core.
- Admite un asunto y un cuerpo propios en la llamada (texto o HTML, con los mismos shortcodes), por ejemplo para un correo de confirmación de pedido en el idioma del cliente, sin tocar la plantilla que usa el envío manual.
- Idempotente: si la factura ya tiene fecha de envío (`femail`) no se reenvía, salvo que se fuerce.
- Marca la factura como enviada tras el envío correcto (igual que el controlador `SendMail` de la interfaz).
- Atribuye el remitente al usuario asociado a la API key cuando es posible.

## Requisitos

- FacturaScripts 2025.2 o superior (`min_version = 2025.2`).
- Email configurado en FacturaScripts (identidad SMTP en *Panel de control → Email*).

## Instalación

1. Descarga el plugin o clona este repositorio dentro de la carpeta `Plugins/` de tu instalación:
   ```
   FacturaScripts/Plugins/EnviarFacturaEmailApi
   ```
2. Activa el plugin desde *Panel de control → Plugins*.
3. Habilita la API REST en *Panel de control → API* y crea una **API key**.
4. En los permisos de la API key, concede acceso al recurso `enviarFacturaCliente`.

## Uso

### Endpoint

```
POST /api/3/enviarFacturaCliente/{idfactura}
```

### Autenticación

Se realiza mediante la API key de FacturaScripts en la cabecera `Token`:

```
Token: TU_API_KEY
```

### Parámetros

| Parámetro | Ubicación | Obligatorio | Descripción |
|-----------|-----------|-------------|-------------|
| `idfactura` | URL | Sí | Identificador (`idfactura`) de la factura de cliente a enviar. |
| `email` | body | No | Fuerza el email del destinatario en lugar de resolverlo desde la factura/contacto/cliente. |
| `force` | body | No | Si vale `1`, reenvía aunque la factura ya tenga fecha de envío (`femail`). |
| `subject` | body | No | Asunto propio en lugar del de la plantilla `sendmail-FacturaCliente`. |
| `body` | body | No | Cuerpo propio (texto plano o HTML) en lugar del de la plantilla. |

`subject` y `body` son independientes: lo que no se envíe sale de la plantilla (o de las traducciones del core). En ambos se sustituyen los shortcodes de la plantilla: `{code}`, `{name}`, `{date}`, `{total}`, `{number2}`, `{contact_name}` y `{contact_surname}`. Cuando se usa texto propio, el email no se etiqueta en el historial con la notificación `sendmail-FacturaCliente`.

### Ejemplo

```bash
curl -X POST "https://tu-dominio.com/api/3/enviarFacturaCliente/42" \
  -H "Token: TU_API_KEY" \
  -d "force=1" \
  -d "email=cliente@ejemplo.com"
```

Con asunto y cuerpo propios:

```bash
curl -X POST "https://tu-dominio.com/api/3/enviarFacturaCliente/42" \
  -H "Token: TU_API_KEY" \
  --data-urlencode "subject=Hemos recibido tu pedido · Factura {code}" \
  --data-urlencode "body=<p>Hola {name}, gracias por tu compra.</p>"
```

### Respuestas

**Envío correcto** (`200 OK`):

```json
{
  "ok": true,
  "email": "cliente@ejemplo.com",
  "codigo": "FAC2026/0042"
}
```

**Factura ya enviada** (`200 OK`, sin `force`):

```json
{
  "ok": true,
  "already_sent": true,
  "femail": "17-06-2026",
  "email": "cliente@ejemplo.com"
}
```

**Error** (código HTTP correspondiente):

```json
{
  "status": "error",
  "message": "no-recipient-email"
}
```

| Código HTTP | `message` | Causa |
|-------------|-----------|-------|
| 405 | `method-not-allowed` | El método no es POST. |
| 400 | `missing-id-parameter` | Falta el identificador de la factura. |
| 404 | `record-not-found` | No existe la factura indicada. |
| 422 | `no-recipient-email` | No se encontró un email de destinatario válido. |
| 500 | `email-not-configured` | El email no está configurado en FacturaScripts. |
| 500 | `pdf-generation-error` | No se pudo generar el PDF de la factura. |
| 500 | `send-mail-error` | Fallo al enviar el correo. |

## Licencia

Este plugin se distribuye bajo la licencia [GNU Lesser General Public License v3.0](https://www.gnu.org/licenses/lgpl-3.0.html) (LGPL-3.0).

Consulta los ficheros [`COPYING.LESSER`](COPYING.LESSER) (LGPL) y [`COPYING`](COPYING) (GPL, que la LGPL complementa) para el texto completo.
