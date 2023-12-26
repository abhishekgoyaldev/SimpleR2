<?php declare(strict_types=1);

use DOMDocument;
use RuntimeException;

class SimpleR2
{
    private string $accessKeyId;
    private string $secretKey;
    private ?string $sessionToken;
    private string $endpoint;
    private int $timeoutInSeconds = 5;

    public static function fromEnvironmentVariables(string $endpoint): self
    {
        return new self(
            $_SERVER['R2_ACCESS_KEY_ID'],
            $_SERVER['R2_SECRET_ACCESS_KEY'],
            $_SERVER['R2_SESSION_TOKEN'] ?? null,
            $endpoint
        );
    }

    public function __construct(string $accessKeyId, string $secretKey, ?string $sessionToken, string $endpoint)
    {
        $this->accessKeyId = $accessKeyId;
        $this->secretKey = $secretKey;
        $this->sessionToken = $sessionToken;
        $this->endpoint = $endpoint;
    }

    public function setTimeout(int $timeoutInSeconds): SimpleR2
    {
        $this->timeoutInSeconds = $timeoutInSeconds;
        return $this;
    }

    public function get(string $bucket, string $key, array $headers = []): array
    {
        return $this->r2Request('GET', $bucket, $key, $headers);
    }

    public function getIfExists(string $bucket, string $key, array $headers = []): array
    {
        return $this->r2Request('GET', $bucket, $key, $headers, '', false);
    }

    public function put(string $bucket, string $key, string $content, array $headers = []): array
    {
        return $this->r2Request('PUT', $bucket, $key, $headers, $content);
    }

    public function delete(string $bucket, string $key, array $headers = []): array
    {
        return $this->r2Request('DELETE', $bucket, $key, $headers);
    }

    private function r2Request(string $httpVerb, string $bucket, string $key, array $headers, string $body = '', bool $throwOn404 = true): array
    {
        $uriPath = '/' . $bucket . '/' . $key;
        $queryString = '';

        $hostname = $this->getHostnameFromEndpoint($this->endpoint);
        $headers['host'] = $hostname;

        $headers = $this->signRequest($httpVerb, $uriPath, $queryString, $headers, $body);

        $url = "$this->endpoint$uriPath?$queryString";

        [$status, $body, $responseHeaders] = $this->curlRequest($httpVerb, $url, $headers, $body);

        if (($throwOn404 && $status === 404) || $status < 200 || ($status >= 400 && $status !== 404)) {
            $errorMessage = '';
            if ($body) {
                $dom = new DOMDocument;
                if (!$dom->loadXML($body)) {
                    throw new RuntimeException('Could not parse the R2 response: ' . $body);
                }
                if ($dom->childNodes->item(0)->nodeName === 'Error') {
                    $errorMessage = $dom->childNodes->item(0)->textContent;
                }
            }
            throw $this->httpError($status, $errorMessage);
        }

        return [$status, $body, $responseHeaders];
    }

    private function curlRequest(string $httpVerb, string $url, array $headers, string $body): array
    {
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "$name: $value";
        }

        $ch = curl_init($url);
        if (!$ch) {
            throw $this->httpError(null, 'could not create a CURL request for an unknown reason');
        }

        $responseHeadersAsString = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $httpVerb,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutInSeconds,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => function ($c, $data) use (&$responseHeadersAsString) {
                $responseHeadersAsString .= $data;
                return strlen($data);
            },
        ]);
        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false or curl_errno($ch) > 0) {
            $errorMessage = ($responseBody === false) ? 'Response body is false.' : curl_error($ch);
            throw $this->httpError($status, $errorMessage);
        }

        $responseHeaders = iconv_mime_decode_headers(
            $responseHeadersAsString,
            ICONV_MIME_DECODE_CONTINUE_ON_ERROR,
            'UTF-8',
        ) ?: [];

        return [$status, (string) $responseBody, $responseHeaders];
    }

    private function signRequest(
        string $httpVerb,
        string $uriPath,
        string $queryString,
        array $headers,
        string $body
    ): array {
        $longDate = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        $region = 'auto';
        $scope = "$shortDate/$region/s3/aws4_request";
        $hashedPayload = hash('sha256', $body);
    
        $headers['x-amz-date'] = $longDate;
        $headers['x-amz-content-sha256'] = $hashedPayload;
    
        $headers = $this->sortHeadersByName($headers);
        $signedHeaders = implode(';', array_map('strtolower', array_keys($headers)));
    
        $canonicalHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
        }

        $canonicalRequest = "$httpVerb\n$uriPath\n$queryString\n$canonicalHeaders\n$signedHeaders\n$hashedPayload";

        $stringToSign = "AWS4-HMAC-SHA256\n$longDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $signature = $this->calculateSignature($this->secretKey, $shortDate, $region, 's3', $stringToSign);
    
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/$scope,SignedHeaders=$signedHeaders,Signature=$signature";
    
        return $headers;
    }

    private function calculateSignature($key, $date, $region, $service, $stringToSign) {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $key, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    private function getHostnameFromEndpoint(string $endpoint): string
    {
        $hostname = str_replace('https://', '', $endpoint);
        $hostname = rtrim($hostname, '/');
        return $hostname;
    }

    private function httpError(?int $status, ?string $message): RuntimeException
    {
        return new RuntimeException("R2 request failed: $status $message");
    }

    private function sortHeadersByName(array $headers): array
    {
        ksort($headers, SORT_STRING | SORT_FLAG_CASE);
        return $headers;
    }
}
