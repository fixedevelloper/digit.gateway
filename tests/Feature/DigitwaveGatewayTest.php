<?php

namespace Tests\Feature;

use App\Services\Gateways\DigitwaveGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitwaveGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.digitwave.url' => 'https://digitwave.test/api/', 'services.digitwave.api_key' => 'key']);
        Http::fake(['digitwave.test/*' => Http::response(['success' => true, 'request_id' => 'DW-1'])]);
    }

    public function test_send_money_transmits_the_operator_currency(): void
    {
        (new DigitwaveGateway)->sendMoney('TX-1', 'DR Congo', 'OM_CD', '810000000', 16.66, 'USD');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://digitwave.test/api/send'
            && $request['carrier'] === 'OM_CD'
            && $request['amount'] === 16.66
            && $request['currency'] === 'USD');
    }

    public function test_request_withdrawal_transmits_the_operator_currency(): void
    {
        (new DigitwaveGateway)->requestWithdrawal('WD-1', 'DR Congo', 'OM_CD', '810000000', 17.34, 'USD');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://digitwave.test/api/withdrawal'
            && $request['amount'] === 17.34
            && $request['currency'] === 'USD');
    }
}
