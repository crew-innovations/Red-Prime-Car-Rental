<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Http;

/**
 * Web Push without extra libraries (4 Nov, 16.3): VAPID (RFC 8292) signed with the server key,
 * and the message encrypted for the device (RFC 8291, aes128gcm) with PHP's openssl.
 * Needs only the openssl extension (no gmp / bcmath). Checked against the RFC 8291 example.
 */
class WebPush
{
    protected const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** A new P-256 key pair: [private PEM, public key (65 bytes, uncompressed)]. */
    public static function newKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key)['ec'];

        return [$pem, "\x04".str_pad($d['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
    }

    /** Private key PEM from the raw 32-byte secret and the 65-byte public key. */
    public static function privatePem(string $d, string $public): string
    {
        $der = hex2bin('30770201010420').str_pad($d, 32, "\0", STR_PAD_LEFT).hex2bin('a00a06082a8648ce3d030107a144034200').$public;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    public static function publicPem(string $public): string
    {
        $der = hex2bin(self::SPKI_P256).$public;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * Encrypts a message for one device (RFC 8291). $localPrivatePem / $salt are only given in tests.
     *
     * @return array{body: string, cipherText: string, localPublic: string, salt: string}
     */
    public static function encrypt(string $plain, string $uaPublic, string $authSecret, ?string $localPrivatePem = null, ?string $salt = null): array
    {
        if ($localPrivatePem) {
            $local = openssl_pkey_get_private($localPrivatePem);
            $ld = openssl_pkey_get_details($local)['ec'];
            $localPublic = "\x04".str_pad($ld['x'], 32, "\0", STR_PAD_LEFT).str_pad($ld['y'], 32, "\0", STR_PAD_LEFT);
        } else {
            [$pem, $localPublic] = self::newKeyPair();
            $local = openssl_pkey_get_private($pem);
        }
        $salt ??= random_bytes(16);

        $shared = openssl_pkey_derive(openssl_pkey_get_public(self::publicPem($uaPublic)), $local, 32);
        if ($shared === false) {
            throw new \RuntimeException('Could not work out the shared key for this device.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$localPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($plain."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        $cipherText = $cipher.$tag;
        $header = $salt.pack('N', 4096).chr(strlen($localPublic)).$localPublic;

        return ['body' => $header.$cipherText, 'cipherText' => $cipherText, 'localPublic' => $localPublic, 'salt' => $salt];
    }

    /** VAPID Authorization header value for an endpoint. */
    public static function vapid(string $endpoint, string $privatePem, string $public, string $subject): string
    {
        $parts = parse_url($endpoint);
        $aud = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $head = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
        openssl_sign($head.'.'.$claims, $der, openssl_pkey_get_private($privatePem), OPENSSL_ALGO_SHA256);

        return 'vapid t='.$head.'.'.$claims.'.'.self::b64(self::derToRaw($der)).', k='.self::b64($public);
    }

    /** ECDSA signature from DER (openssl) to the 64-byte r||s form JWT needs. */
    public static function derToRaw(string $der): string
    {
        $pos = 2; // SEQUENCE header (short form for P-256)
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $out;
    }

    /**
     * Sends one push. Returns the HTTP status (201 = accepted; 404 / 410 = the subscription is gone).
     */
    public static function send(string $endpoint, string $p256dh, string $auth, array $payload, string $privatePem, string $public, string $subject, int $ttl = 86400, string $urgency = 'normal'): int
    {
        $enc = self::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE), self::unb64($p256dh), self::unb64($auth));
        $response = Http::timeout(15)->withHeaders([
            'Authorization' => self::vapid($endpoint, $privatePem, $public, $subject),
            'TTL' => (string) $ttl,
            'Urgency' => $urgency,
            'Content-Encoding' => 'aes128gcm',
        ])->withBody($enc['body'], 'application/octet-stream')->post($endpoint);

        return $response->status();
    }
}
