<?php

namespace EdwinFadilah\Passport\Tests;

use EdwinFadilah\Passport\Scope;
use EdwinFadilah\Passport\Passport;
use PHPUnit\Framework\TestCase;
use EdwinFadilah\Passport\Http\Controllers\ScopeController;

class ScopeControllerTest extends TestCase
{
    public function testShouldGetScopes()
    {
        $controller = new ScopeController;

        Passport::tokensCan($scopes = [
            'place-orders' => 'Place orders',
            'check-status' => 'Check order status',
        ]);

        $result = $controller->all();

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(Scope::class, $result);
        $this->assertSame(['id' => 'place-orders', 'description' => 'Place orders'], $result[0]->toArray());
        $this->assertSame(['id' => 'check-status', 'description' => 'Check order status'], $result[1]->toArray());
    }
}
