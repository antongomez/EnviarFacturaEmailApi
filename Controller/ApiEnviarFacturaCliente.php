<?php
/**
 * This file is part of EnviarFacturaEmailApi plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Plugins\EnviarFacturaEmailApi\Controller;

use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\User;

/**
 * Endpoint que envía una factura de cliente por email con su PDF adjunto,
 * usando la identidad SMTP, la plantilla sendmail-FacturaCliente y el historial
 * de emails de la propia instancia de FacturaScripts.
 *
 * POST /api/3/enviarFacturaCliente/{idfactura}
 *
 * Parámetros opcionales (body):
 *  - email: fuerza el destinatario en lugar de resolverlo desde la factura/cliente/contacto.
 *  - force: si vale 1, reenvía aunque la factura ya tenga fecha de envío (femail).
 */
class ApiEnviarFacturaCliente extends ApiController
{
    protected function runResource(): void
    {
        // 1. solo aceptamos POST
        if ($this->request->method() !== 'POST') {
            $this->jsonError(Response::HTTP_METHOD_NOT_ALLOWED, 'method-not-allowed');
            return;
        }

        // 2. comprobamos el id de la factura
        $id = $this->getUriParam('3');
        if (empty($id)) {
            $this->jsonError(Response::HTTP_BAD_REQUEST, 'missing-id-parameter');
            return;
        }

        // 3. cargamos la factura
        $factura = new FacturaCliente();
        if (false === $factura->load($id)) {
            $this->jsonError(Response::HTTP_NOT_FOUND, Tools::trans('record-not-found'));
            return;
        }

        // 4. resolvemos el destinatario
        $recipient = $this->resolveRecipient($factura);
        if (empty($recipient['email'])) {
            $this->jsonError(Response::HTTP_UNPROCESSABLE_ENTITY, 'no-recipient-email');
            return;
        }

        // 5. idempotencia: si ya se envió y no se fuerza, no reenviamos
        $force = (bool)$this->request->request->get('force', false);
        if (false === $force && !empty($factura->femail)) {
            $this->response
                ->setHttpCode(Response::HTTP_OK)
                ->json([
                    'ok' => true,
                    'already_sent' => true,
                    'femail' => $factura->femail,
                    'email' => $recipient['email'],
                ]);
            return;
        }

        // 6. comprobamos que el email esté configurado antes de generar nada
        $mail = NewMail::create();
        $user = $this->getApiUser();
        if (null !== $user) {
            $mail->setUser($user);
        }
        if (false === $mail->canSendMail()) {
            $this->jsonError(Response::HTTP_INTERNAL_SERVER_ERROR, 'email-not-configured');
            return;
        }

        // 7. generamos el PDF en memoria y lo guardamos en la carpeta temporal de adjuntos
        $pdfPath = $this->generatePdf($factura);
        if (empty($pdfPath)) {
            $this->jsonError(Response::HTTP_INTERNAL_SERVER_ERROR, 'pdf-generation-error');
            return;
        }

        // 8. componemos el asunto y el cuerpo (plantilla sendmail-FacturaCliente o traducciones)
        [$subject, $body] = $this->buildSubjectAndBody($factura);

        // 9. enviamos
        $mail->to($recipient['email'], $recipient['name'])
            ->subject($subject)
            ->body($body)
            ->addAttachment($pdfPath, 'factura_' . $factura->codigo . '.pdf');

        $ok = $mail->send();

        // 10. limpieza del PDF temporal
        if (file_exists($pdfPath)) {
            unlink($pdfPath);
        }

        if (false === $ok) {
            $this->jsonError(Response::HTTP_INTERNAL_SERVER_ERROR, 'send-mail-error');
            return;
        }

        // 11. marcamos la factura como enviada (igual que el controlador SendMail de la UI)
        if ($factura->hasColumn('femail')) {
            $factura->femail = Tools::date();
            $factura->save();
        }

        $this->response
            ->setHttpCode(Response::HTTP_OK)
            ->json([
                'ok' => true,
                'email' => $recipient['email'],
                'codigo' => $factura->codigo,
            ]);
    }

    /**
     * Resuelve el email del destinatario siguiendo la misma jerarquía que SendMail:
     * email de la factura -> contacto de facturación -> cliente.
     */
    protected function resolveRecipient(FacturaCliente $factura): array
    {
        // destinatario forzado por parámetro
        $forced = $this->request->request->get('email', '');
        if (!empty($forced) && Validator::email($forced)) {
            return ['email' => $forced, 'name' => ''];
        }

        // email directo en la factura (por si alguna extensión añade la columna email)
        if ($factura->hasColumn('email') && !empty($factura->email) && Validator::email($factura->email)) {
            return ['email' => $factura->email, 'name' => $factura->nombrecliente];
        }

        // contacto de facturación
        if (!empty($factura->idcontactofact)) {
            $contacto = new Contacto();
            if (
                $contacto->load($factura->idcontactofact)
                && !empty($contacto->email)
                && Validator::email($contacto->email)
            ) {
                return ['email' => $contacto->email, 'name' => $contacto->fullName()];
            }
        }

        // cliente
        $cliente = $factura->getSubject();
        if (!empty($cliente->email) && Validator::email($cliente->email)) {
            return ['email' => $cliente->email, 'name' => $cliente->razonsocial];
        }

        return ['email' => '', 'name' => ''];
    }

    /**
     * Genera el PDF de la factura y lo escribe en la carpeta temporal de adjuntos.
     * Devuelve la ruta absoluta del fichero o cadena vacía si falla.
     */
    protected function generatePdf(FacturaCliente $factura): string
    {
        $cliente = $factura->getSubject();
        $lang = $cliente->langcode ?? '';
        $title = Tools::lang($lang)->trans('invoice') . ' ' . $factura->codigo;

        $exportManager = new ExportManager();
        $exportManager->newDoc('PDF', $title, 0, $lang);
        $exportManager->addBusinessDocPage($factura);

        $pdf = $exportManager->getDoc();
        if (empty($pdf)) {
            return '';
        }

        Tools::folderCheckOrCreate(Tools::folder(NewMail::ATTACHMENTS_TMP_PATH));
        $fileName = 'factura_' . $factura->codigo . '_' . time() . '.pdf';
        $path = Tools::folder(NewMail::ATTACHMENTS_TMP_PATH, $fileName);
        if (false === file_put_contents($path, $pdf)) {
            return '';
        }

        return $path;
    }

    /**
     * Construye asunto y cuerpo reutilizando la notificación sendmail-FacturaCliente
     * (misma lógica que SendMail::loadDataDefault) o, en su defecto, las traducciones.
     */
    protected function buildSubjectAndBody(FacturaCliente $factura): array
    {
        $notification = new EmailNotification();
        $where = [
            Where::eq('name', 'sendmail-' . $factura->modelClassName()),
            Where::eq('enabled', true),
        ];
        if ($notification->loadWhere($where)) {
            $shortCodes = ['{code}', '{name}', '{date}', '{total}', '{number2}', '{contact_name}', '{contact_surname}'];
            $shortValues = [
                $factura->codigo,
                $factura->nombrecliente,
                $factura->fecha,
                $factura->total,
                $factura->numero2,
                '',
                '',
            ];

            if (!empty($factura->idcontactofact) && $contacto = Contacto::find($factura->idcontactofact)) {
                $shortValues[5] = $contacto->nombre;
                $shortValues[6] = $contacto->apellidos;
            }

            $subject = str_replace($shortCodes, $shortValues, $notification->subject);
            $body = str_replace($shortCodes, $shortValues, $notification->body);
            return [$subject, $body];
        }

        // sin notificación: usamos las traducciones del core
        $subject = Tools::trans('invoice-email-subject', ['%code%' => $factura->codigo]);
        $body = Tools::trans('invoice-email-text', ['%code%' => $factura->codigo]);
        return [$subject, $body];
    }

    /**
     * Devuelve el usuario asociado a la API key (por su nick) o null si no existe.
     * Solo sirve para atribuir el remitente; NewMail funciona igualmente sin él.
     */
    protected function getApiUser(): ?User
    {
        if (empty($this->apiKey->nick)) {
            return null;
        }

        $user = new User();
        return $user->load($this->apiKey->nick) ? $user : null;
    }

    protected function jsonError(int $httpCode, string $message): void
    {
        $this->response
            ->setHttpCode($httpCode)
            ->json([
                'status' => 'error',
                'message' => $message,
            ]);
    }
}
