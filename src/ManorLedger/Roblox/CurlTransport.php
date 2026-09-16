<?php
/**
 * Default HTTP transport for AnalyticsClient: runs one wave of requests in
 * parallel with curl_multi and returns plain arrays, so the client itself
 * never touches curl and can be tested with a fake transport.
 *
 * Request:  ['method' => 'GET'|'POST', 'url' => string, 'headers' => array<string,string>,
 *            'body' => ?string, 'timeout' => int, 'connectTimeout' => int]
 * Response: ['status' => int, 'body' => string|false, 'headers' => array<string,string>
 *            (lower-case names), 'error' => string]
 */
declare(strict_types=1);

namespace ManorLedger\Roblox;

use CurlHandle;

final class CurlTransport
{
    /**
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>> responses, same indices as $requests
     */
    public function __invoke(array $requests): array
    {
        $multi     = curl_multi_init();
        $handles   = [];
        $headers   = [];
        $responses = [];

        foreach ($requests as $index => $request) {
            $headers[$index] = [];
            $handle = $this->handleFor($request, $headers[$index]);
            $handles[$index] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        do {
            $state = curl_multi_exec($multi, $running);
        } while ($state === CURLM_CALL_MULTI_PERFORM);
        while ($running) {
            curl_multi_select($multi, 1.0);
            do {
                $state = curl_multi_exec($multi, $running);
            } while ($state === CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($handles as $index => $handle) {
            $body = curl_multi_getcontent($handle);
            $responses[$index] = [
                'status'  => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'body'    => $body ?? false,
                'headers' => $headers[$index],
                'error'   => (string)curl_error($handle),
            ];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return $responses;
    }

    /**
     * @param array<string, mixed>  $request
     * @param array<string, string> $headers filled by the header callback
     */
    private function handleFor(array $request, array &$headers): CurlHandle
    {
        $handle = curl_init((string)$request['url']);
        $headerLines = [];
        foreach ($request['headers'] ?? [] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => (int)($request['timeout'] ?? 60),
            CURLOPT_CONNECTTIMEOUT => (int)($request['connectTimeout'] ?? 10),
            // The API key travels in a header: never follow a redirect that
            // could carry it to another host, and always verify the peer.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if (($request['method'] ?? 'GET') === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = (string)($request['body'] ?? '');
        }
        curl_setopt_array($handle, $options);
        return $handle;
    }
}
