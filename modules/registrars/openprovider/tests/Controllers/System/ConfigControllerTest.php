<?php

namespace OpenProvider\WhmcsRegistrar\Tests\Controllers\System;

use Carbon\Carbon;
use Mockery;
use Mockery\MockInterface;
use OpenProvider\API\ApiConfiguration;
use OpenProvider\API\ApiInterface;
use OpenProvider\API\Response;
use OpenProvider\WhmcsRegistrar\Controllers\System\ConfigController;
use OpenProvider\WhmcsRegistrar\helpers\Cache;
use OpenProvider\WhmcsRegistrar\src\Configuration;
use OpenProvider\WhmcsRegistrar\Tests\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use WeDevelopCoffee\wPower\Core\Core;
use WeDevelopCoffee\wPower\Models\Registrar;
use WHMCS\Database\Capsule;

/**
 * Each test defines doubles for the classes normally supplied by WHMCS.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ConfigControllerTest extends TestCase
{
    private MockInterface $api;
    private MockInterface $capsule;
    private MockInterface $tokens;
    private ApiConfiguration $configuration;
    private ConfigController $controller;

    protected function setUp(): void
    {
        define('SESSION_EXPIRATION_LIFE_TIME', 300);
        define('AUTH_TOKEN_EXPIRATION_LIFE_TIME', 1);
        Carbon::setTestNow('2026-01-01 00:00:00');
        $_REQUEST = [];
        $_SERVER['SCRIPT_FILENAME'] = '/fixture/admin/configregistrars.php';
        $this->params['test_mode'] = 'off';

        Mockery::mock('overload:' . Registrar::class)->shouldReceive('getRegistrarData')
            ->once()->andReturn(['openprovider' => $this->params]);
        $this->capsule = Mockery::mock('alias:' . Capsule::class);
        $this->tokens = Mockery::mock();

        $this->configuration = new ApiConfiguration();
        $this->configuration->setHost(Configuration::get('api_url'));
        $this->api = Mockery::mock(ApiInterface::class);
        $this->api->shouldReceive('getConfiguration')->andReturn($this->configuration);

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $this->controller = new ConfigController(Mockery::mock(Core::class), $session, $this->api);
    }

    public function test_return_config_with_failed_login(): void
    {
        $this->expectTokenLookup(null);
        $this->api->shouldReceive('call')
            ->with('generateAuthTokenRequest', ['username' => $this->params['Username'], 'password' => $this->params['Password']])
            ->once()->andReturn(new Response());

        $configuration = $this->controller->getConfig($this->params);

        $this->assertStringContainsString(ConfigController::ERROR_INCORRECT_CREDENTIALS, $configuration[0]['Description']);
        $this->assertSame('', $this->configuration->getToken());
    }

    public function test_return_config_with_successful_login(): void
    {
        $this->expectTokenLookup(null);
        $this->expectTokenCreation($this->params['Username'], $this->params['Password']);

        $configuration = $this->controller->getConfig($this->params);

        $this->assertSame($this->controller->getConfigArray(), $configuration);
        $this->assertSame('fixture-token', $this->configuration->getToken());
    }

    public function test_if_cache_is_used(): void
    {
        $this->expectTokenLookup((object) ['expire_at' => '2026-01-02 00:00:00']);
        Cache::set('op_auth_generate', new Response());
        $this->api->shouldReceive('call')->with('searchPromoMessageRequest', [])->once()->andReturn(new Response());

        $configuration = $this->controller->getConfig($this->params);

        $this->assertSame($this->controller->getConfigArray(), $configuration);
        $this->assertFalse(Cache::has('op_auth_generate'));
    }

    public function test_return_config_with_request_data_and_a_successful_login(): void
    {
        $_REQUEST = ['action' => 'save', 'Username' => 'fixture-user', 'Password' => '***masked'];
        $this->expectTokenLookup(null);
        $this->capsule->shouldReceive('table')->with('reseller_tokens')->once()->andReturn($this->tokens);
        $this->tokens->shouldReceive('where')->with('username', $this->params['Username'])->once()->andReturnSelf();
        $this->tokens->shouldReceive('delete')->once()->andReturn(1);
        $this->expectTokenCreation('fixture-user', $this->params['Password']);

        $configuration = $this->controller->getConfig($this->params);

        $this->assertSame($this->controller->getConfigArray(), $configuration);
        $this->assertSame('fixture-token', $this->configuration->getToken());
    }

    private function expectTokenLookup(?object $row): void
    {
        $schema = Mockery::mock();
        $schema->shouldReceive('hasTable')->with('reseller_tokens')->once()->andReturn($row !== null);
        $this->capsule->shouldReceive('schema')->once()->andReturn($schema);

        if ($row !== null) {
            $this->capsule->shouldReceive('table')->with('reseller_tokens')->once()->andReturn($this->tokens);
            $this->tokens->shouldReceive('where')->with('username', $this->params['Username'])->once()->andReturnSelf();
            $this->tokens->shouldReceive('orderBy')->with('created_at', 'desc')->once()->andReturnSelf();
            $this->tokens->shouldReceive('first')->once()->andReturn($row);
        }
    }

    private function expectTokenCreation(string $username, string $password): void
    {
        $response = new Response();
        $response->setData(['token' => 'fixture-token']);
        $this->api->shouldReceive('call')->with('generateAuthTokenRequest', ['username' => $username, 'password' => $password])
            ->once()->andReturn($response);
        $this->capsule->shouldReceive('table')->with('reseller_tokens')->twice()->andReturn($this->tokens);
        $this->tokens->shouldReceive('where')->with('username', $username)->once()->andReturnSelf();
        $this->tokens->shouldReceive('delete')->once()->andReturn(1);
        $this->tokens->shouldReceive('insert')->with([
            'username' => $username,
            'token' => 'fixture-token',
            'expire_at' => '2026-01-02 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
        ])->once()->andReturn(true);
    }
}
