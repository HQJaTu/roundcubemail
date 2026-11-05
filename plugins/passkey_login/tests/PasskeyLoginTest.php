<?php

namespace Roundcube\Plugins\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

use function Roundcube\Tests\invokeMethod;

require_once __DIR__ . '/PasskeyLoginTestCase.php';

/**
 * The input boundary: what the plugin accepts from the browser before any of
 * it reaches the database or OpenSSL, and the encodings it speaks on the way
 * back out.
 */
class PasskeyLoginTest extends PasskeyLoginTestCase
{
    /**
     * Test valid_b64() — the shape every credential field has to have
     *
     * @dataProvider provide_valid_b64_cases
     */
    #[DataProvider('provide_valid_b64_cases')]
    public function test_valid_b64($expected, $value, $max)
    {
        $plugin = $this->plugin();

        $this->assertSame($expected, invokeMethod($plugin, 'valid_b64', [$value, $max]));
    }

    /**
     * Data sets for valid_b64() test
     */
    public static function provide_valid_b64_cases(): iterable
    {
        return [
            // Both base64 alphabets are accepted: WebAuthn uses the URL-safe
            // one, while an SPKI public key arrives as standard base64.
            [true, 'YWJj', 64],
            [true, 'a-b_c', 64],
            [true, 'a+b/c=', 64],
            [true, 'AQ==', 64],

            // Exactly at the limit is fine; one byte over is not.
            [true, 'abcd', 4],
            [false, 'abcde', 4],

            // Nothing is not a credential.
            [false, '', 64],

            // Anything outside the alphabet is rejected, which is what keeps
            // quotes and separators out of the values we go on to store.
            [false, 'abc def', 64],
            [false, 'abc.def', 64],
            [false, "abc\ndef", 64],
            [false, 'abc;DROP', 64],
            [false, "YWJj\x00", 64],

            // A non-string can arrive from a malformed POST body.
            [false, null, 64],
            [false, 42, 64],
            [false, [], 64],
        ];
    }

    /**
     * Test b64url() — URL-safe base64 without padding, per WebAuthn
     *
     * @dataProvider provide_b64url_cases
     */
    #[DataProvider('provide_b64url_cases')]
    public function test_b64url($expected, $bin)
    {
        $plugin = $this->plugin();

        $this->assertSame($expected, invokeMethod($plugin, 'b64url', [$bin]));
    }

    /**
     * Data sets for b64url() test
     */
    public static function provide_b64url_cases(): iterable
    {
        return [
            ['', ''],
            ['YQ', 'a'],
            ['YWI', 'ab'],
            ['YWJj', 'abc'],

            // The two characters that differ from standard base64: this input
            // encodes to "+/8=", so it exercises + -> -, / -> _ and the
            // stripped padding all at once.
            ['-_8', "\xfb\xff"],
            ['-_A', "\xfb\xf0"],
        ];
    }

    /**
     * Test b64url() emits nothing that would need escaping in a URL or JSON
     */
    public function test_b64url_is_url_safe()
    {
        $plugin = $this->plugin();
        $encoded = invokeMethod($plugin, 'b64url', [random_bytes(256)]);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    /**
     * Test clean_desc() — the user-supplied passkey label
     *
     * @dataProvider provide_clean_desc_cases
     */
    #[DataProvider('provide_clean_desc_cases')]
    public function test_clean_desc($expected, $value)
    {
        $plugin = $this->plugin();

        $this->assertSame($expected, invokeMethod($plugin, 'clean_desc', [$value]));
    }

    /**
     * Data sets for clean_desc() test
     */
    public static function provide_clean_desc_cases(): iterable
    {
        return [
            ['Work laptop', 'Work laptop'],
            ['Work laptop', '  Work laptop  '],
            ['', ''],
            ['', '   '],

            // A run of control characters collapses to one space, so a pasted
            // multi-line value stays on one line in the settings table.
            ['a b', "a\nb"],
            ['a b', "a\r\n\tb"],
            ['a b', "a\x00\x01b"],
            ['a b', "a\x7fb"],

            // Leading and trailing control characters go altogether.
            ['abc', "\x00abc\x00"],

            // A non-string can arrive from a malformed POST body.
            ['', null],
            ['42', 42],
        ];
    }

    /**
     * Test clean_desc() bounds the label to the column width (255)
     */
    public function test_clean_desc_bounds_length()
    {
        $plugin = $this->plugin();

        $this->assertSame(255, strlen(invokeMethod($plugin, 'clean_desc', [str_repeat('x', 300)])));
        $this->assertSame(255, strlen(invokeMethod($plugin, 'clean_desc', [str_repeat('x', 255)])));
        $this->assertSame(10, strlen(invokeMethod($plugin, 'clean_desc', [str_repeat('x', 10)])));
    }

    /**
     * Test clean_desc() truncates on characters, not bytes
     *
     * mb_substr() is what keeps a multi-byte label from being cut mid-character
     * and stored as invalid UTF-8.
     */
    public function test_clean_desc_truncates_by_character()
    {
        $plugin = $this->plugin();
        $result = invokeMethod($plugin, 'clean_desc', [str_repeat('ä', 300)]);

        $this->assertSame(255, mb_strlen($result));
        $this->assertSame(str_repeat('ä', 255), $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * Test spki_to_pem() — wrapping a stored public key for OpenSSL
     */
    public function test_spki_to_pem()
    {
        $plugin = $this->plugin();
        $der = random_bytes(91);
        $pem = invokeMethod($plugin, 'spki_to_pem', [base64_encode($der)]);

        $this->assertStringStartsWith("-----BEGIN PUBLIC KEY-----\n", $pem);
        $this->assertStringEndsWith("-----END PUBLIC KEY-----\n", $pem);

        // The body has to be wrapped at 64 columns or OpenSSL will not read it.
        $body = trim(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $pem));
        foreach (explode("\n", $body) as $line) {
            $this->assertLessThanOrEqual(64, strlen($line));
        }

        // And it has to round-trip back to the bytes we were given.
        $this->assertSame($der, base64_decode($body, true));
    }

    /**
     * Test spki_to_pem() rejects anything that is not base64
     *
     * @dataProvider provide_spki_to_pem_rejects_cases
     */
    #[DataProvider('provide_spki_to_pem_rejects_cases')]
    public function test_spki_to_pem_rejects($value)
    {
        $plugin = $this->plugin();

        $this->assertNull(invokeMethod($plugin, 'spki_to_pem', [$value]));
    }

    /**
     * Data sets for spki_to_pem() rejection test
     */
    public static function provide_spki_to_pem_rejects_cases(): iterable
    {
        return [
            'empty' => [''],
            'not base64' => ['!!!not base64!!!'],
            'null' => [null],
        ];
    }

    /**
     * Test assertion_malformed() — the structural gate before signature checks
     *
     * @dataProvider provide_assertion_malformed_cases
     */
    #[DataProvider('provide_assertion_malformed_cases')]
    public function test_assertion_malformed($expected, $cred_id, $auth_data, $client_json, $signature)
    {
        $plugin = $this->plugin();

        $this->assertSame(
            $expected,
            invokeMethod($plugin, 'assertion_malformed', [$cred_id, $auth_data, $client_json, $signature])
        );
    }

    /**
     * Data sets for assertion_malformed() test
     *
     * authenticatorData is 37 bytes at minimum: a 32-byte RP ID hash, one
     * flags byte and a 4-byte signature counter.
     */
    public static function provide_assertion_malformed_cases(): iterable
    {
        $auth_data = str_repeat('x', 37);

        return [
            // A well-formed set passes the gate.
            'well formed' => [false, 'Y3JlZA', $auth_data, '{"type":"webauthn.get"}', 'sig'],
            'longer auth data' => [false, 'Y3JlZA', str_repeat('x', 200), '{}', 'sig'],

            // Each field, broken on its own.
            'bad cred id' => [true, 'not valid!', $auth_data, '{}', 'sig'],
            'empty cred id' => [true, '', $auth_data, '{}', 'sig'],
            'auth data undecodable' => [true, 'Y3JlZA', false, '{}', 'sig'],
            'auth data one byte short' => [true, 'Y3JlZA', str_repeat('x', 36), '{}', 'sig'],
            'auth data empty' => [true, 'Y3JlZA', '', '{}', 'sig'],
            'client json undecodable' => [true, 'Y3JlZA', $auth_data, false, 'sig'],
            'client json empty' => [true, 'Y3JlZA', $auth_data, '', 'sig'],
            'signature undecodable' => [true, 'Y3JlZA', $auth_data, '{}', false],
            'signature empty' => [true, 'Y3JlZA', $auth_data, '{}', ''],
        ];
    }
}
