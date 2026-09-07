<?php

namespace Roundcube\Plugins\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../message_security_info.php';

/**
 * Scaffolding shared by the plugin's test classes: a plugin instance built the
 * way the core builds one, and the configuration left as it was found.
 *
 * Named *TestCase so PHPUnit's default *Test suffix does not collect it as a
 * suite of its own, the same way the core's tests/ActionTestCase.php is named.
 */
abstract class MessageSecurityInfoTestCase extends TestCase
{
    /** Config keys these tests set, restored after each one. */
    private const CONFIG_KEYS = [
        'message_security_info_check_spf',
        'message_security_info_check_dkim',
        'message_security_info_check_dmarc',
        'message_security_info_check_tls',
        'message_security_info_check_submission',
        'message_security_info_trusted_authserv',
        'message_security_info_extra_headers',
    ];

    /** @var array<string, mixed> */
    private $config_backup = [];

    #[\Override]
    protected function setUp(): void
    {
        $config = \rcube::get_instance()->config;

        foreach (self::CONFIG_KEYS as $key) {
            $this->config_backup[$key] = $config->get($key);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $config = \rcube::get_instance()->config;

        // rcube is a singleton, so anything set here would leak into the next test.
        foreach ($this->config_backup as $key => $value) {
            $config->set($key, $value);
        }
    }

    /**
     * A plugin instance with its localization loaded, so the labels and
     * descriptions these tests assert on are the real texts rather than
     * placeholders.
     *
     * @param array<string, mixed> $config
     */
    protected function plugin($config = [])
    {
        $rcube = \rcube::get_instance();

        foreach ($config as $key => $value) {
            $rcube->config->set($key, $value);
        }

        $plugin = new \message_security_info($rcube->plugins);
        $plugin->init();
        $plugin->add_texts('localization/');

        return $plugin;
    }
}
