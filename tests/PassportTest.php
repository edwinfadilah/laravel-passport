<?php

namespace EdwinFadilah\Passport\Tests;

use EdwinFadilah\Passport\Token;
use EdwinFadilah\Passport\Client;
use EdwinFadilah\Passport\AuthCode;
use EdwinFadilah\Passport\Passport;
use PHPUnit\Framework\TestCase;
use EdwinFadilah\Passport\ClientRepository;
use EdwinFadilah\Passport\PersonalAccessClient;

class PassportTest extends TestCase
{
    public function test_scopes_can_be_managed()
    {
        Passport::tokensCan([
            'user' => 'get user information',
        ]);

        $this->assertTrue(Passport::hasScope('user'));
        $this->assertEquals(['user'], Passport::scopeIds());
        $this->assertEquals('user', Passport::scopes()[0]->id);
    }

    public function test_auth_code_instance_can_be_created()
    {
        $authCode = Passport::authCode();

        $this->assertInstanceOf(AuthCode::class, $authCode);
        $this->assertInstanceOf(Passport::authCodeModel(), $authCode);
    }

    public function test_client_instance_can_be_created()
    {
        $client = Passport::client();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(Passport::clientModel(), $client);
    }

    public function test_personal_access_client_instance_can_be_created()
    {
        $client = Passport::personalAccessClient();

        $this->assertInstanceOf(PersonalAccessClient::class, $client);
        $this->assertInstanceOf(Passport::personalAccessClientModel(), $client);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function test_missing_personal_access_client_is_reported()
    {
        Passport::usePersonalAccessClientModel(PersonalAccessClientStub::class);

        $clientRepository = new ClientRepository;
        $clientRepository->personalAccessClient();
    }

    public function test_token_instance_can_be_created()
    {
        $token = Passport::token();

        $this->assertInstanceOf(Token::class, $token);
        $this->assertInstanceOf(Passport::tokenModel(), $token);
    }
}

class PersonalAccessClientStub
{
    public function exists()
    {
        return false;
    }
}
