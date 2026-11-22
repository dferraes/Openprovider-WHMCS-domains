<?php

namespace OpenProvider\WhmcsRegistrar\Tests\Controllers\System;

use Mockery;
use OpenProvider\API\ApiHelper;
use OpenProvider\API\Domain;
use OpenProvider\WhmcsRegistrar\Controllers\System\EppController;
use OpenProvider\WhmcsRegistrar\Tests\TestCase;
use WeDevelopCoffee\wPower\Core\Core;
use WHMCS\Domains\DomainLookup\DomainObj;

class EppControllerTest extends TestCase
{
    private ApiHelper $apiHelper;
    private EppController $controller;
    private array $domainParams;

    protected function setUp(): void
    {
        $this->apiHelper = Mockery::mock(ApiHelper::class);
        $this->controller = new EppController(Mockery::mock(Core::class), new Domain(), $this->apiHelper);

        $domain = new DomainObj();
        $domain->setSecondLevel($this->params['sld']);
        $domain->setTopLevel($this->params['tld']);
        $this->domainParams = $this->params;
        $this->domainParams['original']['domainObj'] = $domain;
    }

    public function test_get_epp_code(): void
    {
        $this->apiHelper->shouldReceive('getDomain')->with($this->requestedDomain())->once()
            ->andReturn(['id' => 17, 'authCode' => 'some-epp-code']);
        $this->apiHelper->shouldNotReceive('getEppCode');

        $this->assertSame(['eppcode' => 'some-epp-code'], $this->controller->get($this->domainParams));
    }

    public function test_failed_get_epp_code(): void
    {
        $this->apiHelper->shouldReceive('getDomain')->with($this->requestedDomain())->once()
            ->andReturn(['id' => 17, 'authCode' => '']);
        $this->apiHelper->shouldReceive('getEppCode')->with(17)->once()
            ->andThrow(\Exception::class, 'EPP code is not set');

        $this->assertSame(['error' => 'EPP code is not set'], $this->controller->get($this->domainParams));
    }

    public function test_failed_request_to_registrar_to_get_epp_code(): void
    {
        $this->apiHelper->shouldReceive('getDomain')->with($this->requestedDomain())->once()
            ->andThrow(\Exception::class, 'Some error message');
        $this->apiHelper->shouldNotReceive('getEppCode');

        $this->assertSame(['error' => 'Some error message'], $this->controller->get($this->domainParams));
    }

    private function requestedDomain()
    {
        return Mockery::on(function (Domain $domain): bool {
            return $domain->name === $this->domainParams['sld']
                && $domain->extension === $this->domainParams['tld'];
        });
    }
}
