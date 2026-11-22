<?php

namespace OpenProvider\WhmcsRegistrar\Tests\Controllers\System;

use Mockery;
use OpenProvider\API\ApiHelper;
use OpenProvider\API\Domain;
use OpenProvider\WhmcsRegistrar\Controllers\System\ContactController;
use OpenProvider\WhmcsRegistrar\Tests\TestCase;
use OpenProvider\WhmcsRegistrar\src\Handle;
use WeDevelopCoffee\wPower\Core\Core;
use WHMCS\Domains\DomainLookup\DomainObj;

class ContactControllerTest extends TestCase
{
    private ApiHelper $apiHelper;
    private ContactController $controller;
    private array $domainParams;

    protected function setUp(): void
    {
        $this->apiHelper = Mockery::mock(ApiHelper::class);
        $this->controller = new ContactController(
            Mockery::mock(Core::class),
            new Domain(),
            Mockery::mock(Handle::class),
            $this->apiHelper
        );

        $domain = new DomainObj();
        $domain->setSecondLevel($this->params['sld']);
        $domain->setTopLevel($this->params['tld']);
        $this->domainParams = $this->params;
        $this->domainParams['original']['domainObj'] = $domain;
    }

    public function test_get_details(): void
    {
        $owner = ['Country' => 'MX', 'State' => 'Example'];
        $this->apiHelper->shouldReceive('getDomain')->with($this->requestedDomain())->once()
            ->andReturn(['ownerHandle' => 'owner-test', 'billingHandle' => 'billing-test']);
        $this->apiHelper->shouldReceive('getTldMeta')->with('com')->once()
            ->andReturn(['ownerHandleSupported' => true, 'billingHandleSupported' => false]);
        $this->apiHelper->shouldReceive('getCustomersAsync')->with(['Owner' => 'owner-test'])->once()
            ->andReturn(['Owner' => $owner]);

        $this->assertSame(['Owner' => $owner], $this->controller->getDetails($this->domainParams));
    }

    public function test_get_exception_when_getting_contact_details(): void
    {
        $this->apiHelper->shouldReceive('getDomain')->with($this->requestedDomain())->once()
            ->andThrow(\Exception::class, 'Some error message');
        $this->apiHelper->shouldNotReceive('getCustomersAsync');

        $this->assertSame(['error' => 'Some error message'], $this->controller->getDetails($this->domainParams));
    }

    private function requestedDomain()
    {
        return Mockery::on(function (Domain $domain): bool {
            return $domain->name === $this->domainParams['sld']
                && $domain->extension === $this->domainParams['tld'];
        });
    }
}
