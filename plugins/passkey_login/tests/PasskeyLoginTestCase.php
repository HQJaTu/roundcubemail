<?php

namespace Roundcube\Plugins\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../passkey_login.php';

/**
 * Scaffolding shared by the plugin's test classes: a plugin instance built the
 * way the core builds one, and the global state left as it was found.
 *
 * This plugin reads more of its environment than most — the request host and
 * scheme decide the WebAuthn relying-party ID and origin, and the user agent
 * decides a new passkey's default description — so $_SERVER is backed up
 * alongside the configuration. Hook registrations are restored too, because
 * init() adds to the rcube_plugin_api singleton that every test shares.
 *
 * Named *TestCase so PHPUnit's default *Test suffix does not collect it as a
 * suite of its own, the same way the core's tests/ActionTestCase.php is named.
 */
abstract class PasskeyLoginTestCase extends TestCase
{
    /** Config keys these tests set, restored after each one. */
    private const CONFIG_KEYS = [
        'passkey_login_rp_name',
        'passkey_login_rpid',
        'passkey_login_origin',
        'passkey_login_enroll',
        'passkey_login_excluded_browsers',
        'plugins',
    ];

    /** $_SERVER keys these tests set, restored after each one. */
    private const SERVER_KEYS = [
        'HTTP_HOST',
        'HTTPS',
        'SERVER_PORT',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_USER_AGENT',
    ];

    /** @var array<string, mixed> */
    private $config_backup = [];

    /** @var array<string, mixed> */
    private $server_backup = [];

    /** @var array<string, mixed> */
    private $handlers_backup = [];

    /** @var string|null */
    private $task_backup;

    #[\Override]
    protected function setUp(): void
    {
        $rcube = \rcmail::get_instance();

        foreach (self::CONFIG_KEYS as $key) {
            $this->config_backup[$key] = $rcube->config->get($key);
        }

        foreach (self::SERVER_KEYS as $key) {
            $this->server_backup[$key] = $_SERVER[$key] ?? null;
        }

        $this->handlers_backup = $rcube->plugins->handlers;
        $this->task_backup = $rcube->task;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $rcube = \rcmail::get_instance();

        // rcube is a singleton, so anything set here would leak into the next test.
        foreach ($this->config_backup as $key => $value) {
            $rcube->config->set($key, $value);
        }

        foreach ($this->server_backup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        $rcube->plugins->handlers = $this->handlers_backup;
        $rcube->task = $this->task_backup;
    }

    /**
     * A plugin instance with its localization loaded, so the section titles
     * these tests assert on are the real texts rather than placeholders.
     *
     * init() is called because it is what assigns the plugin its rcmail
     * handle; without it every method that reads configuration would fault.
     * The hooks it registers are undone in tearDown(). Set the task (and any
     * conflicting `plugins` entry) through $config *before* calling this, so
     * init() sees the state under test.
     *
     * @param array<string, mixed> $config
     *
     * @return \passkey_login
     */
    protected function plugin($config = [])
    {
        $rcube = \rcmail::get_instance();

        foreach ($config as $key => $value) {
            $rcube->config->set($key, $value);
        }

        $plugin = new \passkey_login($rcube->plugins);
        $plugin->init();
        $plugin->add_texts('localization/');

        return $plugin;
    }

    /**
     * The methods $plugin registered for one hook, as plain names.
     *
     * Asserting on names rather than on [$plugin, 'method'] callables keeps a
     * failure readable: PHPUnit would otherwise dump the whole plugin object,
     * and with it the rcmail singleton, into the diff.
     *
     * @param string         $hook
     * @param \passkey_login $plugin
     *
     * @return list<string>
     */
    protected function hooked_methods($hook, $plugin)
    {
        $methods = [];

        foreach (\rcmail::get_instance()->plugins->handlers[$hook] ?? [] as $callback) {
            if (is_array($callback) && ($callback[0] ?? null) === $plugin) {
                $methods[] = $callback[1];
            }
        }

        return $methods;
    }
}
