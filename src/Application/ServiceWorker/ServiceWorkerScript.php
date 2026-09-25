<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\ServiceWorker;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

/**
 * The prebuilt service worker (assets/dist/web-push-sw.js) with the configuration
 * injected — the most privileged script of the origin, so the injection is a JSON
 * literal escaped for any context (</script>, U+2028, quotes).
 */
final readonly class ServiceWorkerScript
{
    public const CONTENT_TYPE = 'text/javascript; charset=utf-8';

    public function __construct(
        private string $prebuiltScriptPath,
    ) {
    }

    public static function createFromPackageDist(): self
    {
        return new self(\dirname(__DIR__, 3).'/assets/dist/web-push-sw.js');
    }

    public function render(ServiceWorkerConfig $config): string
    {
        $script = is_file($this->prebuiltScriptPath) ? file_get_contents($this->prebuiltScriptPath) : false;

        if (false === $script) {
            throw InvalidValue::because('There is no prebuilt service worker: run "yarn build" in assets/.');
        }

        return 'self.__WEB_PUSH_CONFIG__ = '.self::encodeForScript($config->toArray()).";\n".$script;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function encodeForScript(array $value): string
    {
        return json_encode(
            $value,
            \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES,
        );
    }
}
