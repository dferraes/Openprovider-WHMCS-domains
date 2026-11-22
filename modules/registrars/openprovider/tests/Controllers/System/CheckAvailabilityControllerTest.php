<?php

namespace OpenProvider\WhmcsRegistrar\Tests\Controllers\System;

use Mockery;
use OpenProvider\API\ApiInterface;
use OpenProvider\API\Domain;
use OpenProvider\API\Response;
use OpenProvider\WhmcsRegistrar\Controllers\System\CheckAvailabilityController;
use OpenProvider\WhmcsRegistrar\Tests\TestCase;
use WeDevelopCoffee\wPower\Core\Core;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;


/**
 * Class TestConfigController
 * @package OpenProvider\WhmcsRegistrar\Tests\Controllers\System
 */
class CheckAvailabilityControllerTest extends TestCase
{
    /**
     * @var Mockery\MockInterface|Core
     */
    private $mockedCore;
    /**
     * @var Mockery\MockInterface|ApiInterface
     */
    private $mockedAPI;
    /**
     * @var
     */
    private $CheckAvailabilityController;
    /**
     * @var Mockery\MockInterface|Domain
     */
    private $mockedDomain;
    public function test_unavailable_domain()
    {
        // Prepare
        [$params, $domain, $sld, $tld] = $this->prepareParamsAndDomain();
        $this->expectAvailability($sld, $tld, ['domain' => $domain, 'status' => 'active']);
        $result = $this->CheckAvailabilityController->check($params);
        $this->assertInstanceOf(ResultsList::class, $result);
        $this->assertSame(SearchResult::STATUS_REGISTERED, $result->result[0]->status);
    }

    public function test_premium_domain_with_support_disabled_in_openprovider()
    {
        // Prepare
        [$params, $domain, $sld, $tld] = $this->prepareParamsAndDomain();
        $this->expectAvailability($sld, $tld, [
            'domain' => $domain,
            'premium' => true,
            'status' => 'free',
        ]);
        $result = $this->CheckAvailabilityController->check($params);
        $this->assertSame(SearchResult::STATUS_RESERVED, $result->result[0]->status);
    }

    public function test_premium_domain_with_support_enabled_in_openprovider()
    {
        // Prepare
        [$params, $domain, $sld, $tld] = $this->prepareParamsAndDomain();
        $params['premiumEnabled'] = true;
        $params['OpenproviderPremium'] = true;

        $this->expectAvailability($sld, $tld, [
            'domain' => $domain,
            'premium' => true,
            'status' => 'free',
        ]);

        $args = ['domainName' => $sld, 'domainExtension' => $tld];
        foreach (['create' => '123', 'transfer' => '200'] as $operation => $price) {
            $response = new Response();
            $response->setData(['price' => ['reseller' => ['price' => $price, 'currency' => 'EUR']]]);
            $this->mockedAPI->shouldReceive('call')
                ->with('retrievePriceDomainRequest', $args + ['operation' => $operation])
                ->once()
                ->andReturn($response);
        }

        $result = $this->CheckAvailabilityController->check($params);
        $this->assertSame(SearchResult::STATUS_NOT_REGISTERED, $result->result[0]->status);
        $this->assertTrue($result->result[0]->premiumDomain);
        $this->assertSame([
            'register' => '123',
            'renew' => '200',
            'transfer' => '200',
            'CurrencyCode' => 'EUR',
        ], $result->result[0]->premiumCostPricing);
    }

    /**
     * setUp
     *
     */
    public function setUp(): void
    {
        $this->mockedCore = Mockery::mock(Core::class);
        $this->mockedAPI = Mockery::mock(ApiInterface::class);
        $this->mockedDomain = Mockery::mock(Domain::class);
        $this->CheckAvailabilityController = new CheckAvailabilityController(
            $this->mockedCore,
            $this->mockedDomain,
            $this->mockedAPI
        );
    }

    private function expectAvailability(string $sld, string $tld, array $result): void
    {
        $response = new Response();
        $response->setData(['results' => [$result]]);
        $this->mockedAPI->shouldReceive('call')
            ->with('checkDomainRequest', Mockery::on(static function (array $args) use ($sld, $tld): bool {
                $domains = $args['domains'] ?? [];
                return count($domains) === 1
                    && $domains[0] instanceof Domain
                    && $domains[0]->name === $sld
                    && $domains[0]->extension === $tld;
            }))
            ->once()
            ->andReturn($response);
    }

    /**
     * @return array
     */
    protected function prepareParamsAndDomain()
    {
        $params = $this->params;
        $params['tldsToInclude'][] = '.com';
        $params['isIdnDomain'] = false;
        $params['punyCodeSearchTerm'] = false;
        $params['premiumEnabled'] = false;
        $params['searchTerm'] = 'some-domain';

        $sld = $params['searchTerm'];
        $tld = 'com';
        $domain = $params['searchTerm'] . '.' . $tld;
        return array ($params, $domain, $sld, $tld);
    }
}
