<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_CHAT_ID',
        'GEMINI_API_KEY',
        'GEMINI_MODEL',
        'GEMINI_RATE_LIMIT_PER_MINUTE',
        'APPLYR_POLL_SCHEDULE',
        'APPLYR_REGENERATION_LIMIT',
        'APPLYR_ADAPTER_PAGE_CAP',
        'APPLYR_ADAPTER_REQUEST_DELAY_MS',
        'APPLYR_ADAPTER_REFRESH_CAP',
    ];

    private array $originalEnv = [];

    private bool $ignoreDotEnv = false;

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            $this->setEnv($key, $value);
        }

        parent::tearDown();
    }

    public function createApplication()
    {
        if (! $this->ignoreDotEnv) {
            return parent::createApplication();
        }

        // Boot without the developer's .env so values there can't leak into these assertions.
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.does-not-exist');
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_operational_settings_have_sensible_defaults(): void
    {
        $this->bootWithEnvironment(array_fill_keys(self::ENV_KEYS, null));

        $this->assertSame('0 * * * *', config('applyr.polling.schedule'));
        $this->assertSame(3, config('applyr.tailoring.regeneration_limit'));
        $this->assertSame(5, config('applyr.adapters.page_cap'));
        $this->assertSame(2000, config('applyr.adapters.request_delay_ms'));
        $this->assertSame(50, config('applyr.adapters.refresh_cap'));
        $this->assertSame(8, config('services.gemini.rate_limit_per_minute'));
        $this->assertSame('gemini-2.5-flash', config('services.gemini.model'));
        $this->assertNull(config('services.gemini.api_key'));
        $this->assertNull(config('services.telegram.bot_token'));
        $this->assertNull(config('services.telegram.chat_id'));
    }

    public function test_operational_settings_are_read_from_the_environment(): void
    {
        $this->bootWithEnvironment([
            'TELEGRAM_BOT_TOKEN' => 'bot-token',
            'TELEGRAM_CHAT_ID' => '12345',
            'GEMINI_API_KEY' => 'gemini-key',
            'GEMINI_MODEL' => 'gemini-test-model',
            'GEMINI_RATE_LIMIT_PER_MINUTE' => '4',
            'APPLYR_POLL_SCHEDULE' => '*/30 * * * *',
            'APPLYR_REGENERATION_LIMIT' => '5',
            'APPLYR_ADAPTER_PAGE_CAP' => '2',
            'APPLYR_ADAPTER_REQUEST_DELAY_MS' => '500',
            'APPLYR_ADAPTER_REFRESH_CAP' => '10',
        ]);

        $this->assertSame('bot-token', config('services.telegram.bot_token'));
        $this->assertSame('12345', config('services.telegram.chat_id'));
        $this->assertSame('gemini-key', config('services.gemini.api_key'));
        $this->assertSame('gemini-test-model', config('services.gemini.model'));
        $this->assertSame(4, config('services.gemini.rate_limit_per_minute'));
        $this->assertSame('*/30 * * * *', config('applyr.polling.schedule'));
        $this->assertSame(5, config('applyr.tailoring.regeneration_limit'));
        $this->assertSame(2, config('applyr.adapters.page_cap'));
        $this->assertSame(500, config('applyr.adapters.request_delay_ms'));
        $this->assertSame(10, config('applyr.adapters.refresh_cap'));
    }

    /**
     * @param  array<string, string|null>  $env  null unsets the variable
     */
    private function bootWithEnvironment(array $env): void
    {
        foreach ($env as $key => $value) {
            if (! array_key_exists($key, $this->originalEnv)) {
                $this->originalEnv[$key] = getenv($key) === false ? null : getenv($key);
            }

            $this->setEnv($key, $value);
        }

        $this->ignoreDotEnv = true;
        $this->refreshApplication();
        Http::preventStrayRequests();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
