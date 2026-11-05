<?php

namespace Roundcube\Plugins\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

use function Roundcube\Tests\invokeMethod;

require_once __DIR__ . '/PasskeyLoginTestCase.php';

/**
 * The Settings integration, and the request properties the WebAuthn ceremony
 * is pinned to.
 *
 * The hook-registration tests here are deliberate regression cover: the
 * "Passkeys" section once disappeared from Settings because a deployment
 * carried a build of this plugin that registered no preferences hooks, and
 * nothing failed to say so.
 */
class PasskeyLoginSettingsTest extends PasskeyLoginTestCase
{
    /**
     * Test init() registers the preferences hooks for the settings task
     *
     * This is the check that would have caught the regression.
     */
    public function test_init_registers_preferences_hooks()
    {
        \rcmail::get_instance()->task = 'settings';

        $plugin = $this->plugin();

        $this->assertSame(['prefs_sections'], $this->hooked_methods('preferences_sections_list', $plugin));
        $this->assertSame(['prefs_list'], $this->hooked_methods('preferences_list', $plugin));
    }

    /**
     * Test init() still wires the login flow in the settings task
     *
     * The settings section must not come at the cost of the enrollment that
     * runs on every authenticated page.
     */
    public function test_init_registers_login_hooks_in_settings_task()
    {
        \rcmail::get_instance()->task = 'settings';

        $plugin = $this->plugin();

        $this->assertSame(['startup'], $this->hooked_methods('startup', $plugin));
        $this->assertSame(['render_page'], $this->hooked_methods('render_page', $plugin));
    }

    /**
     * Test init() leaves the preferences hooks alone outside the settings task
     *
     * @dataProvider provide_init_skips_preferences_hooks_elsewhere_cases
     */
    #[DataProvider('provide_init_skips_preferences_hooks_elsewhere_cases')]
    public function test_init_skips_preferences_hooks_elsewhere($task)
    {
        \rcmail::get_instance()->task = $task;

        $plugin = $this->plugin();

        $this->assertSame([], $this->hooked_methods('preferences_sections_list', $plugin));
        $this->assertSame([], $this->hooked_methods('preferences_list', $plugin));

        // The login flow is still wired, on every task.
        $this->assertSame(['startup'], $this->hooked_methods('startup', $plugin));
    }

    /**
     * Data sets for the non-settings task test
     */
    public static function provide_init_skips_preferences_hooks_elsewhere_cases(): iterable
    {
        return [['mail'], ['addressbook'], ['login'], ['']];
    }

    /**
     * Test init() registers nothing at all beside a conflicting plugin
     *
     * twostep_login also takes over the login form, so this plugin stands
     * down rather than fighting it. Both plugins are published from this
     * repository, which makes the pair a real deployment possibility.
     *
     * The conflict is also written to logs/errors.log for the administrator,
     * so this test needs that directory writable — it is in the repository.
     */
    public function test_init_stands_down_beside_conflicting_plugin()
    {
        \rcmail::get_instance()->task = 'settings';

        $plugin = $this->plugin(['plugins' => ['twostep_login']]);

        foreach (['startup', 'render_page', 'preferences_sections_list', 'preferences_list'] as $hook) {
            $this->assertSame(
                [],
                $this->hooked_methods($hook, $plugin),
                "{$hook} should not be registered beside a conflicting plugin"
            );
        }
    }

    /**
     * Test prefs_sections() adds the Passkeys section to Settings
     */
    public function test_prefs_sections_adds_section()
    {
        $plugin = $this->plugin();
        $args = $plugin->prefs_sections(['list' => []]);

        $this->assertArrayHasKey('passkeys', $args['list']);
        $this->assertSame('passkeys', $args['list']['passkeys']['id']);
        $this->assertSame('Passkeys', $args['list']['passkeys']['section']);
    }

    /**
     * Test prefs_sections() keeps the sections already in the list
     */
    public function test_prefs_sections_preserves_existing()
    {
        $plugin = $this->plugin();
        $args = $plugin->prefs_sections(['list' => ['general' => ['id' => 'general', 'section' => 'General']]]);

        $this->assertArrayHasKey('general', $args['list']);
        $this->assertArrayHasKey('passkeys', $args['list']);
        $this->assertSame('General', $args['list']['general']['section']);
    }

    /**
     * Test prefs_list() ignores every section but its own
     */
    public function test_prefs_list_ignores_other_sections()
    {
        $plugin = $this->plugin();
        $args = ['section' => 'general', 'blocks' => ['main' => ['name' => 'Main']]];

        $this->assertSame($args, $plugin->prefs_list($args));
    }

    /**
     * Test prefs_list() only places a marker until the section is opened
     *
     * Settings renders every section's title up front; the rows are built when
     * the user actually opens the section, so the passkey table is not read
     * from the database on each visit to Settings.
     */
    public function test_prefs_list_defers_until_section_is_current()
    {
        $plugin = $this->plugin();
        $args = $plugin->prefs_list(['section' => 'passkeys', 'blocks' => []]);

        $this->assertSame('Passkeys', $args['blocks']['passkeys']['name']);
        $this->assertTrue($args['blocks']['passkeys']['content']);
        $this->assertArrayNotHasKey('options', $args['blocks']['passkeys']);
    }

    /**
     * Test rp_id() — the WebAuthn relying-party ID
     *
     * @dataProvider provide_rp_id_cases
     */
    #[DataProvider('provide_rp_id_cases')]
    public function test_rp_id($expected, $configured, $host)
    {
        $plugin = $this->plugin(['passkey_login_rpid' => $configured]);

        if ($host === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $host;
        }

        $this->assertSame($expected, invokeMethod($plugin, 'rp_id', []));
    }

    /**
     * Data sets for rp_id() test
     */
    public static function provide_rp_id_cases(): iterable
    {
        return [
            // Configured value wins outright.
            ['example.com', 'example.com', 'mail.other.test'],

            // Otherwise the request host, with any port removed: a passkey is
            // scoped to the domain, and a port in the RP ID makes it invalid.
            ['mail.example.com', null, 'mail.example.com'],
            ['mail.example.com', null, 'mail.example.com:8443'],
            ['localhost', null, 'localhost:8000'],
            ['localhost', '', 'localhost'],

            // No Host header at all (CLI, or a malformed request).
            ['localhost', null, null],
        ];
    }

    /**
     * Test default_origin() — reconstructing this deployment's web origin
     *
     * @dataProvider provide_default_origin_cases
     */
    #[DataProvider('provide_default_origin_cases')]
    public function test_default_origin($expected, $server)
    {
        $plugin = $this->plugin();

        foreach (['HTTPS', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO', 'HTTP_HOST'] as $key) {
            unset($_SERVER[$key]);
        }
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }

        $this->assertSame($expected, invokeMethod($plugin, 'default_origin', []));
    }

    /**
     * Data sets for default_origin() test
     */
    public static function provide_default_origin_cases(): iterable
    {
        return [
            'plain http' => ['http://mail.example.com', ['HTTP_HOST' => 'mail.example.com']],
            'https on' => ['https://mail.example.com', ['HTTP_HOST' => 'mail.example.com', 'HTTPS' => 'on']],

            // "off" is what some servers set rather than leaving it unset.
            'https off' => ['http://mail.example.com', ['HTTP_HOST' => 'mail.example.com', 'HTTPS' => 'off']],
            'https OFF uppercase' => ['http://mail.example.com', ['HTTP_HOST' => 'mail.example.com', 'HTTPS' => 'OFF']],

            // Port 443 implies TLS even with no HTTPS variable.
            'port 443' => ['https://mail.example.com', ['HTTP_HOST' => 'mail.example.com', 'SERVER_PORT' => '443']],
            'port 80' => ['http://mail.example.com', ['HTTP_HOST' => 'mail.example.com', 'SERVER_PORT' => '80']],

            // Behind a terminating proxy, which is the common deployment.
            'forwarded https' => [
                'https://mail.example.com',
                ['HTTP_HOST' => 'mail.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ],
            'forwarded HTTPS uppercase' => [
                'https://mail.example.com',
                ['HTTP_HOST' => 'mail.example.com', 'HTTP_X_FORWARDED_PROTO' => 'HTTPS'],
            ],
            'forwarded http' => [
                'http://mail.example.com',
                ['HTTP_HOST' => 'mail.example.com', 'HTTP_X_FORWARDED_PROTO' => 'http'],
            ],

            // The host is kept verbatim, port included, unlike the RP ID.
            'host with port' => ['http://localhost:8000', ['HTTP_HOST' => 'localhost:8000']],
            'no host' => ['http://localhost', []],
        ];
    }

    /**
     * Test origin_allowed() — the assertion's stated origin has to match
     *
     * @dataProvider provide_origin_allowed_cases
     */
    #[DataProvider('provide_origin_allowed_cases')]
    public function test_origin_allowed($expected, $configured, $origin)
    {
        $plugin = $this->plugin(['passkey_login_origin' => $configured]);
        $_SERVER['HTTP_HOST'] = 'mail.example.com';
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        $this->assertSame($expected, invokeMethod($plugin, 'origin_allowed', [$origin]));
    }

    /**
     * Data sets for origin_allowed() test
     */
    public static function provide_origin_allowed_cases(): iterable
    {
        return [
            // A single configured origin.
            [true, 'https://mail.example.com', 'https://mail.example.com'],
            [false, 'https://mail.example.com', 'https://evil.example.com'],

            // Comparison is exact: scheme, host and port all count.
            [false, 'https://mail.example.com', 'http://mail.example.com'],
            [false, 'https://mail.example.com', 'https://mail.example.com:8443'],
            [false, 'https://mail.example.com', 'https://mail.example.com/'],
            [false, 'https://mail.example.com', 'https://Mail.Example.com'],

            // Several origins, for a deployment reachable under more than one
            // name.
            [true, ['https://a.example.com', 'https://b.example.com'], 'https://b.example.com'],
            [false, ['https://a.example.com', 'https://b.example.com'], 'https://c.example.com'],

            // Unconfigured: falls back to the reconstructed origin.
            [true, null, 'http://mail.example.com'],
            [false, null, 'https://mail.example.com'],

            // An empty origin is never allowed, however it is configured.
            [false, 'https://mail.example.com', ''],
            [false, null, ''],
        ];
    }

    /**
     * Test client_data_valid() — the clientDataJSON the browser signed
     *
     * @dataProvider provide_client_data_valid_cases
     */
    #[DataProvider('provide_client_data_valid_cases')]
    public function test_client_data_valid($expected, $client, $challenge)
    {
        $plugin = $this->plugin(['passkey_login_origin' => 'https://mail.example.com']);

        $this->assertSame($expected, invokeMethod($plugin, 'client_data_valid', [$client, $challenge]));
    }

    /**
     * Data sets for client_data_valid() test
     */
    public static function provide_client_data_valid_cases(): iterable
    {
        $valid = [
            'type' => 'webauthn.get',
            'challenge' => 'Y2hhbGxlbmdl',
            'origin' => 'https://mail.example.com',
        ];

        return [
            'valid' => [true, $valid, 'Y2hhbGxlbmdl'],

            // The ceremony type stops a registration response being replayed
            // as a sign-in.
            'create not get' => [false, ['type' => 'webauthn.create'] + $valid, 'Y2hhbGxlbmdl'],
            'missing type' => [false, array_diff_key($valid, ['type' => null]), 'Y2hhbGxlbmdl'],

            // The challenge binds the response to the one we issued.
            'wrong challenge' => [false, $valid, 'ZGlmZmVyZW50'],
            'missing challenge' => [false, array_diff_key($valid, ['challenge' => null]), 'Y2hhbGxlbmdl'],
            'empty expected challenge' => [false, $valid, ''],

            // The origin stops a response obtained on another site counting.
            'wrong origin' => [false, ['origin' => 'https://evil.example.com'] + $valid, 'Y2hhbGxlbmdl'],
            'missing origin' => [false, array_diff_key($valid, ['origin' => null]), 'Y2hhbGxlbmdl'],

            // Not an object at all: json_decode() returned something else.
            'not an array' => [false, null, 'Y2hhbGxlbmdl'],
            'string' => [false, 'webauthn.get', 'Y2hhbGxlbmdl'],
            'false' => [false, false, 'Y2hhbGxlbmdl'],
        ];
    }
}
