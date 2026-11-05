<?php

namespace Roundcube\Plugins\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

use function Roundcube\Tests\invokeMethod;

require_once __DIR__ . '/PasskeyLoginTestCase.php';

/**
 * The default description a new passkey gets. A user may hold several keys and
 * has to be able to tell them apart in Settings, so an unnamed one is labelled
 * from the enrolling browser and platform.
 *
 * The token tables these exercise are ordered, and the order is load-bearing:
 * Edge and Opera user agents also contain "Chrome", Android ones contain
 * "Linux", and iOS ones contain "Mac OS X".
 */
class PasskeyLoginDescriptionTest extends PasskeyLoginTestCase
{
    /**
     * Test ua_label() — first token in the map that occurs in the string wins
     *
     * @dataProvider provide_ua_label_cases
     */
    #[DataProvider('provide_ua_label_cases')]
    public function test_ua_label($expected, $ua, $tokens)
    {
        $plugin = $this->plugin();

        $this->assertSame($expected, invokeMethod($plugin, 'ua_label', [$ua, $tokens]));
    }

    /**
     * Data sets for ua_label() test
     */
    public static function provide_ua_label_cases(): iterable
    {
        $tokens = ['Specific' => 'first', 'General' => 'second'];

        return [
            ['first', 'a Specific string', $tokens],
            ['second', 'a General string', $tokens],

            // Both present: the earlier entry wins, which is the whole reason
            // the maps are ordered rather than alphabetical.
            ['first', 'Specific and General', $tokens],
            ['first', 'General and Specific', $tokens],

            // No match, and the degenerate cases.
            ['', 'nothing here', $tokens],
            ['', '', $tokens],
            ['', 'Specific', []],

            // Matching is substring and case-sensitive.
            ['first', 'xxSpecificxx', $tokens],
            ['', 'specific', $tokens],
        ];
    }

    /**
     * Test describe_user_agent() against the real token tables
     *
     * @dataProvider provide_describe_user_agent_cases
     */
    #[DataProvider('provide_describe_user_agent_cases')]
    public function test_describe_user_agent($expected, $ua)
    {
        $plugin = $this->plugin();
        $_SERVER['HTTP_USER_AGENT'] = $ua;

        $this->assertSame($expected, invokeMethod($plugin, 'describe_user_agent', []));
    }

    /**
     * Data sets for describe_user_agent() test
     *
     * Real user-agent strings, because the precedence traps only show up in
     * the real ones.
     */
    public static function provide_describe_user_agent_cases(): iterable
    {
        return [
            'Chrome on Windows' => [
                'Chrome on Windows',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            ],
            // Edge carries both "Chrome" and "Safari"; "Edg" has to win.
            'Edge on Windows' => [
                'Edge on Windows',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
            ],
            // Opera identifies itself as OPR and also carries "Chrome".
            'Opera on Windows' => [
                'Opera on Windows',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/115.0.0.0',
            ],
            'Firefox on Windows' => [
                'Firefox on Windows',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
            ],
            'Safari on macOS' => [
                'Safari on macOS',
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
            ],
            // iOS says "like Mac OS X", so iPhone/iPad have to be checked first.
            'Safari on iPhone' => [
                'Safari on iPhone',
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
            ],
            'Safari on iPad' => [
                'Safari on iPad',
                'Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
            ],
            // Android says "Linux", so Android has to be checked first.
            'Chrome on Android' => [
                'Chrome on Android',
                'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
            ],
            'Firefox on Android' => [
                'Firefox on Android',
                'Mozilla/5.0 (Android 15; Mobile; rv:133.0) Gecko/133.0 Firefox/133.0',
            ],
            'Chrome on ChromeOS' => [
                'Chrome on ChromeOS',
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            ],
            'Chrome on Linux' => [
                'Chrome on Linux',
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            ],
            'Firefox on Linux' => [
                'Firefox on Linux',
                'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
            ],

            // Only one half recognised: the label is just that half, with no
            // stray separator left behind.
            'browser only' => ['Firefox', 'Firefox/133.0'],
            'platform only' => ['Windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'],
        ];
    }

    /**
     * Test describe_user_agent() falls back to a dated label
     *
     * An unrecognisable agent still has to produce something that
     * distinguishes one key from another, so the date is appended.
     *
     * @dataProvider provide_describe_user_agent_fallback_cases
     */
    #[DataProvider('provide_describe_user_agent_fallback_cases')]
    public function test_describe_user_agent_fallback($ua)
    {
        $plugin = $this->plugin();

        if ($ua === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $ua;
        }

        $this->assertSame('Passkey ' . date('Y-m-d'), invokeMethod($plugin, 'describe_user_agent', []));
    }

    /**
     * Data sets for describe_user_agent() fallback test
     */
    public static function provide_describe_user_agent_fallback_cases(): iterable
    {
        return [
            'curl' => ['curl/8.9.1'],
            'empty' => [''],
            'absent' => [null],
            'unknown agent' => ['SomeRobot/1.0'],
        ];
    }

    /**
     * Test the default description survives clean_desc() unchanged
     *
     * The two meet in action_store(): a description the user did not supply is
     * filled in from the user agent, so the generated label has to already be
     * within the column width and free of anything clean_desc() would strip.
     */
    public function test_default_description_is_already_clean()
    {
        $plugin = $this->plugin();
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            . ' (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

        $generated = invokeMethod($plugin, 'describe_user_agent', []);

        $this->assertSame($generated, invokeMethod($plugin, 'clean_desc', [$generated]));
        $this->assertLessThanOrEqual(255, strlen($generated));
    }
}
