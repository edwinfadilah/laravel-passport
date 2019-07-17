<?php

namespace EdwinFadilah\Passport\Tests;

use Mockery as m;
use PHPUnit\Framework\TestCase;
use EdwinFadilah\Passport\Bridge\Client;
use EdwinFadilah\Passport\ClientRepository;
use EdwinFadilah\Passport\Bridge\ClientRepository as BridgeClientRepository;

class BridgeClientRepositoryTest extends TestCase
{
    /**
     * @var \EdwinFadilah\Passport\ClientRepository
     */
    private $clientModelRepository;

    /**
     * @var \EdwinFadilah\Passport\Bridge\ClientRepository
     */
    private $repository;

    public function setUp()
    {
        $clientModelRepository = m::mock(ClientRepository::class);
        $clientModelRepository->shouldReceive('findActive')
            ->with(1)
            ->andReturn(new BridgeClientRepositoryTestClientStub);

        $this->clientModelRepository = $clientModelRepository;
        $this->repository = new BridgeClientRepository($clientModelRepository);
    }

    public function tearDown()
    {
        m::close();

        unset($this->clientModelRepository, $this->repository);
    }

    public function test_can_get_client_for_auth_code_grant()
    {
        $client = $this->repository->getClientEntity(1, 'authorization_code', 'secret', true);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertNull($this->repository->getClientEntity(1, 'authorization_code', 'wrong-secret', true));
        $this->assertNull($this->repository->getClientEntity(1, 'client_credentials', 'wrong-secret', true));
    }

    public function test_can_get_client_for_client_credentials_grant()
    {
        $client = $this->clientModelRepository->findActive(1);
        $client->personal_access_client = true;

        $this->assertInstanceOf(
            Client::class,
            $this->repository->getClientEntity(1, 'client_credentials', 'secret', true)
        );
        $this->assertNull($this->repository->getClientEntity(1, 'authorization_code', 'secret', true));
    }

    public function test_password_grant_is_permitted()
    {
        $client = $this->clientModelRepository->findActive(1);
        $client->password_client = true;

        $this->assertInstanceOf(Client::class, $this->repository->getClientEntity(1, 'password', 'secret'));
    }

    public function test_password_grant_is_prevented()
    {
        $this->assertNull($this->repository->getClientEntity(1, 'password', 'secret'));
    }

    public function test_authorization_code_grant_is_permitted()
    {
        $this->assertInstanceOf(Client::class, $this->repository->getClientEntity(1, 'authorization_code', 'secret'));
    }

    public function test_authorization_code_grant_is_prevented()
    {
        $client = $this->clientModelRepository->findActive(1);
        $client->password_client = true;

        $this->assertNull($this->repository->getClientEntity(1, 'authorization_code', 'secret'));
    }

    public function test_personal_access_grant_is_permitted()
    {
        $client = $this->clientModelRepository->findActive(1);
        $client->personal_access_client = true;

        $this->assertInstanceOf(Client::class, $this->repository->getClientEntity(1, 'personal_access', 'secret'));
    }

    public function test_personal_access_grant_is_prevented()
    {
        $this->assertNull($this->repository->getClientEntity(1, 'personal_access', 'secret'));
    }

    public function test_client_credentials_grant_is_permitted()
    {
        $this->assertInstanceOf(Client::class, $this->repository->getClientEntity(1, 'client_credentials', 'secret'));
    }

    public function test_client_credentials_grant_is_prevented()
    {
        $client = $this->clientModelRepository->findActive(1);
        $client->secret = null;

        $this->assertNull($this->repository->getClientEntity(1, 'client_credentials', 'secret'));
    }

    public function test_grant_types_allows_request()
    {
        $client = $this->clientModelRepository->findActive(1);
        $client->grant_types = ['client_credentials'];

        $this->assertInstanceOf(Client::class, $this->repository->getClientEntity(1, 'client_credentials', 'secret'));
    }

    public function test_grant_types_disallows_request()
    {
        $client = $this->clientModelRepository->findActive(1);
        $client->grant_types = ['client_credentials'];

        $this->assertNull($this->repository->getClientEntity(1, 'authorization_code', 'secret'));
    }
}

class BridgeClientRepositoryTestClientStub
{
    public $name = 'Client';

    public $redirect = 'http://localhost';

    public $secret = 'secret';

    public $personal_access_client = false;

    public $password_client = false;

    public $grant_types;

    public function firstParty()
    {
        return $this->personal_access_client || $this->password_client;
    }
}
