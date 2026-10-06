<?php

namespace Tests\Feature;

use Tests\TestCase;

class MailConfigurationTest extends TestCase
{
    public function test_the_test_command_sends_a_message_and_never_prints_the_password(): void
    {
        config(['mail.mailers.smtp.password' => 'TOP-SECRET-PW', 'mail.from.address' => 'no-reply@digitagateway.com']);

        $this->artisan('mail:test ops@example.com')
            ->expectsOutputToContain('Message envoyé à ops@example.com')
            ->doesntExpectOutputToContain('TOP-SECRET-PW')
            ->assertExitCode(0);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages[0]->getOriginalMessage();
        $this->assertSame('ops@example.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('no-reply@digitagateway.com', $mail->getFrom()[0]->getAddress());
    }

    public function test_an_invalid_address_is_refused_without_sending(): void
    {
        $this->artisan('mail:test pas-une-adresse')->assertExitCode(2);

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_the_lws_settings_build_an_implicit_tls_transport_on_the_certificate_hostname(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail65.lwspanel.com',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.scheme' => 'smtps',
            'mail.mailers.smtp.username' => 'no-reply@digitagateway.com',
            'mail.mailers.smtp.password' => 'x',
        ]);
        app('mail.manager')->purge('smtp');

        $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();

        $this->assertTrue($transport->getStream()->isTLS());
        $this->assertSame('smtps://mail65.lwspanel.com', (string) $transport);
        $this->assertSame(465, $transport->getStream()->getPort());
    }
}
