<?php

namespace Futurello\MoodBoard\Services\Ai;

use Futurello\MoodBoard\Services\Ai\Contracts\Model3dProvider;
use Futurello\MoodBoard\Services\Ai\Exceptions\AiHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Клиент Tencent Hunyuan To 3D (TencentCloud API 3.0, сервис ai3d/hunyuan).
 *
 * Генерация асинхронная:
 *   SubmitHunyuanTo3DProJob  -> JobId
 *   QueryHunyuanTo3DProJob   -> Status (WAIT/RUN/FAIL/DONE) + ResultFile3Ds[]
 *
 * Подпись запросов — TC3-HMAC-SHA256 (нужна пара SecretId + SecretKey).
 * Эндпоинт/сервис/версия/регион/имена экшенов вынесены в конфиг,
 * чтобы переключаться между intl (hunyuan.intl.tencentcloudapi.com) и
 * домашним (ai3d.tencentcloudapi.com) без правки кода.
 *
 * ВНИМАНИЕ: только для локального теста зовём Tencent напрямую. В проде —
 * через сервисы «Труба» (прокси) и «Бухгалтер» (лимиты/тарифы).
 */
class Hunyuan3dProvider implements Model3dProvider
{
    private const ALGORITHM = 'TC3-HMAC-SHA256';

    /**
     * @param  array{
     *     secret_id: string,
     *     secret_key: string,
     *     host: string,
     *     service: string,
     *     region: string,
     *     version: string,
     *     submit_action: string,
     *     query_action: string
     * }  $config
     * @param  array{connect_timeout: int, timeout: int}  $httpConfig
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
        private readonly array $httpConfig,
    ) {
    }

    public function isEnabled(): bool
    {
        return ! empty($this->config['secret_id']) && ! empty($this->config['secret_key']);
    }

    /**
     * Значение опции Guzzle verify: путь к CA-бандлу, либо bool.
     *
     * @return string|bool
     */
    private function verifyOption(): string|bool
    {
        $caBundle = (string) ($this->config['ca_bundle'] ?? '');
        if ($caBundle !== '' && is_file($caBundle)) {
            return $caBundle;
        }

        return (bool) ($this->config['verify_ssl'] ?? true);
    }

    public function submitModel3d(array $payload): array
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'Hunyuan 3D provider is not configured');
        }

        $body = array_filter([
            'ImageBase64' => $payload['imageBase64'] ?? null,
            'FaceCount' => $payload['faceCount'] ?? null,
            'GenerateType' => $payload['generateType'] ?? null,
            'EnablePBR' => $payload['enablePbr'] ?? null,
        ], static fn ($v) => $v !== null);

        $response = $this->call($this->config['submit_action'], $body);

        $jobId = $response['JobId'] ?? null;
        if (! is_string($jobId) || $jobId === '') {
            throw new AiHttpException(502, 'Hunyuan 3D did not return JobId', $response);
        }

        return ['jobId' => $jobId];
    }

    public function queryModel3d(string $jobId): array
    {
        if (! $this->isEnabled()) {
            throw new AiHttpException(503, 'Hunyuan 3D provider is not configured');
        }

        $response = $this->call($this->config['query_action'], ['JobId' => $jobId]);

        $status = strtoupper((string) ($response['Status'] ?? ''));

        if ($status === 'FAIL') {
            $message = $response['ErrorMessage'] ?? ($response['ErrorCode'] ?? 'Hunyuan 3D job failed');

            return [
                'status' => 'error',
                'glbUrl' => null,
                'previewUrl' => null,
                'error' => (string) $message,
            ];
        }

        if ($status === 'DONE') {
            [$glbUrl, $previewUrl] = $this->extractResultUrls($response['ResultFile3Ds'] ?? []);

            if ($glbUrl === null) {
                return [
                    'status' => 'error',
                    'glbUrl' => null,
                    'previewUrl' => null,
                    'error' => 'Hunyuan 3D job done but no GLB file in result',
                ];
            }

            return [
                'status' => 'done',
                'glbUrl' => $glbUrl,
                'previewUrl' => $previewUrl,
                'error' => null,
            ];
        }

        // WAIT / RUN / прочее — джоб ещё в процессе.
        return [
            'status' => $status === 'RUN' ? 'running' : 'pending',
            'glbUrl' => null,
            'previewUrl' => null,
            'error' => null,
        ];
    }

    /**
     * Достаёт из ResultFile3Ds URL модели (.glb) и URL превью-картинки.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array{0: string|null, 1: string|null}
     */
    private function extractResultUrls(array $files): array
    {
        $glbUrl = null;
        $previewUrl = null;

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $type = strtoupper((string) ($file['Type'] ?? ''));
            $url = $file['Url'] ?? null;

            if (is_string($url) && $url !== '') {
                $isGlb = $type === 'GLB' || str_contains(strtolower($url), '.glb');
                if ($isGlb && $glbUrl === null) {
                    $glbUrl = $url;
                }
            }

            $preview = $file['PreviewImageUrl'] ?? null;
            if (is_string($preview) && $preview !== '' && $previewUrl === null) {
                $previewUrl = $preview;
            }
        }

        return [$glbUrl, $previewUrl];
    }

    /**
     * Выполняет подписанный POST-запрос к TencentCloud API и возвращает
     * содержимое поля Response (без обёртки).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $action, array $body): array
    {
        $payloadJson = json_encode((object) $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        $headers = $this->buildSignedHeaders($action, $payloadJson, $timestamp);

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->withOptions(['verify' => $this->verifyOption()])
                ->withBody($payloadJson, 'application/json; charset=utf-8')
                ->connectTimeout($this->httpConfig['connect_timeout'])
                ->timeout($this->httpConfig['timeout'])
                ->post('https://'.$this->config['host']);
        } catch (ConnectionException $e) {
            throw new AiHttpException(502, 'Hunyuan 3D API unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            $details = $response->json() ?? $response->body();
            throw new AiHttpException($response->status(), 'Hunyuan 3D API error ('.$response->status().')', $details);
        }

        $json = $response->json();
        $inner = is_array($json) ? ($json['Response'] ?? null) : null;

        if (! is_array($inner)) {
            throw new AiHttpException(502, 'Hunyuan 3D returned unexpected response', $json);
        }

        if (isset($inner['Error']) && is_array($inner['Error'])) {
            $code = $inner['Error']['Code'] ?? 'UnknownError';
            $message = $inner['Error']['Message'] ?? 'Hunyuan 3D API error';
            throw new AiHttpException(502, $message.' ('.$code.')', $inner['Error']);
        }

        return $inner;
    }

    /**
     * Сборка заголовков с TC3-HMAC-SHA256 подписью.
     *
     * @return array<string, string>
     */
    private function buildSignedHeaders(string $action, string $payloadJson, int $timestamp): array
    {
        $host = $this->config['host'];
        $service = $this->config['service'];
        $contentType = 'application/json; charset=utf-8';
        $date = gmdate('Y-m-d', $timestamp);

        // 1. Canonical request
        $signedHeaders = 'content-type;host;x-tc-action';
        $canonicalHeaders = "content-type:{$contentType}\n"
            ."host:{$host}\n"
            .'x-tc-action:'.strtolower($action)."\n";
        $hashedPayload = hash('sha256', $payloadJson);
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";

        // 2. String to sign
        $credentialScope = "{$date}/{$service}/tc3_request";
        $stringToSign = self::ALGORITHM."\n{$timestamp}\n{$credentialScope}\n".hash('sha256', $canonicalRequest);

        // 3. Signature
        $secretDate = hash_hmac('sha256', $date, 'TC3'.$this->config['secret_key'], true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        // 4. Authorization
        $authorization = self::ALGORITHM
            .' Credential='.$this->config['secret_id'].'/'.$credentialScope
            .', SignedHeaders='.$signedHeaders
            .', Signature='.$signature;

        return [
            'Authorization' => $authorization,
            'Content-Type' => $contentType,
            'Host' => $host,
            'X-TC-Action' => $action,
            'X-TC-Timestamp' => (string) $timestamp,
            'X-TC-Version' => $this->config['version'],
            'X-TC-Region' => $this->config['region'],
        ];
    }
}
