<?php
/**
 * This file is part of EnviarFacturaEmailApi plugin for FacturaScripts
 * Copyright (C) 2026 Anton Gomez
 */

namespace FacturaScripts\Test\Plugins\EnviarFacturaEmailApi\Controller;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Plugins\EnviarFacturaEmailApi\Controller\ApiEnviarFacturaCliente;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ApiEnviarFacturaClienteTest extends TestCase
{
    use RandomDataTrait;
    use LogErrorsTrait;

    private function controller(): ApiEnviarFacturaCliente
    {
        return new ApiEnviarFacturaCliente('ApiEnviarFacturaCliente', '/api/3/enviarFacturaCliente/1');
    }

    private function call(ApiEnviarFacturaCliente $controller, string $method, array $args)
    {
        $ref = new ReflectionMethod($controller, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($controller, $args);
    }

    public function testResolveRecipientFromCustomer(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $this->assertTrue($invoice->exists(), 'invoice-not-saved');

        // asignamos un email al cliente
        $customer = $invoice->getSubject();
        $customer->email = 'cliente.test@example.com';
        $this->assertTrue($customer->save(), 'customer-not-saved');

        $recipient = $this->call($this->controller(), 'resolveRecipient', [$invoice]);
        $this->assertSame('cliente.test@example.com', $recipient['email']);

        // limpieza
        $this->assertTrue($invoice->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($customer->delete());
    }

    public function testResolveRecipientEmptyWhenNoEmail(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $customer = $invoice->getSubject();

        // ni cliente ni contacto tienen email
        $recipient = $this->call($this->controller(), 'resolveRecipient', [$invoice]);
        $this->assertSame('', $recipient['email']);

        // limpieza
        $this->assertTrue($invoice->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($customer->delete());
    }

    public function testBuildSubjectAndBodyNotEmpty(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $customer = $invoice->getSubject();

        [$subject, $body, $notificationName] = $this->call($this->controller(), 'buildSubjectAndBody', [$invoice]);
        $this->assertNotEmpty($subject, 'empty-subject');
        $this->assertNotEmpty($body, 'empty-body');

        // el código de la factura debe aparecer en el asunto
        $this->assertStringContainsString($invoice->codigo, $subject);

        // el tercer elemento identifica la notificación usada (o null si se usaron traducciones)
        $notification = new EmailNotification();
        if ($notification->load('sendmail-FacturaCliente') && $notification->enabled) {
            $this->assertSame('sendmail-FacturaCliente', $notificationName, 'notification-name-not-returned');
        } else {
            $this->assertNull($notificationName, 'notification-name-should-be-null');
        }

        // limpieza
        $this->assertTrue($invoice->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($customer->delete());
    }

    public function testBuildSubjectAndBodyWithCustomText(): void
    {
        $invoice = $this->getRandomCustomerInvoice();
        $customer = $invoice->getSubject();

        // asunto y cuerpo propios, con shortcodes sustituidos
        [$subject, $body, $notificationName] = $this->call(
            $this->controller(),
            'buildSubjectAndBody',
            [$invoice, 'Pedido web · {code}', '<p>Hola {name}</p>']
        );
        $this->assertSame('Pedido web · ' . $invoice->codigo, $subject);
        $this->assertSame('<p>Hola ' . $invoice->nombrecliente . '</p>', $body);
        $this->assertNull($notificationName, 'custom-text-should-not-be-tagged');

        // solo asunto propio: el cuerpo sale de la plantilla o de las traducciones
        [$subject, $body] = $this->call($this->controller(), 'buildSubjectAndBody', [$invoice, 'Solo asunto', '']);
        $this->assertSame('Solo asunto', $subject);
        $this->assertNotEmpty($body, 'empty-default-body');

        // limpieza
        $this->assertTrue($invoice->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($customer->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
