<?php

declare(strict_types=1);

namespace Claylo\Wp;

class FastCurl
{
    private \CurlMultiHandle $mh;
    private \CurlShareHandle $sh;
    private bool $sharingEnabled = true;
    private array $handlePools = [];
    private array $activeHandles = [];
    private array $completedRequests = [];
    private array $errors = [];
    private array $multiErrors = [];
    private bool $collectResponseHeaders = false;
    private $completedCallback;
    private $errorCallback;
    private $idleCallback;
    private $preRequestCallback;
    private string $baseUrl = '';
    private array $defaultHeaders = [];
    private array $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_ENCODING       => ''
    ];
    private array $multiOptions = [
        CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE => 32768,
        CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE => 32768,
        CURLMOPT_MAX_CONCURRENT_STREAMS => 50,
        CURLMOPT_MAXCONNECTS => 20,
        CURLMOPT_MAX_PIPELINE_LENGTH => 8,
        CURLMOPT_MAX_HOST_CONNECTIONS => 4,
        CURLMOPT_MAX_TOTAL_CONNECTIONS => 16,
        CURLMOPT_PUSHFUNCTION => null,
    ];

    private int $rateLimit = 10;
    private int $requestInterval = 100000;
    private float $lastRequestTime = 0.0;
    private bool $collectStats = false;
    private array $requestStats = [];

    public function __construct(array $handleOptions = [], array $multiOptions = [])
    {
        $this->mh = curl_multi_init();
        $this->setupMultiHandle();
        $this->enableSharing();
        if (!empty($handleOptions)) {
            $this->options = $handleOptions + $this->options;
        }
        if (!empty($multiOptions)) {
            $this->multiOptions = $multiOptions + $this->multiOptions;
        }
    }

    private function setupMultiHandle(): void
    {
        foreach ($this->multiOptions as $option => $value) {
            curl_multi_setopt($this->mh, $option, $value);
        }
    }

    public function enableSharing(): self
    {
        $this->sh = curl_share_init();
        curl_share_setopt($this->sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE | CURL_LOCK_DATA_SSL_SESSION);
        return $this;
    }

    public function disableSharing(): self
    {
        $this->sharingEnabled = false;
        if ($this->sh === null) {
            return $this;
        }

        curl_share_close($this->sh);
        $this->sh = null;
        return $this;
    }

    public function collectResponseHeaders(bool $collect = true): self
    {
        $this->collectResponseHeaders = $collect;
        return $this;
    }

    public function setRateLimit(int $limit): self
    {
        $this->rateLimit = $limit;
        $this->requestInterval = (int)(1000000 / $this->rateLimit);
        return $this;
    }

    public function setBaseUrl(string $url): self
    {
        $this->baseUrl = rtrim($url, '/') . '/';
        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setDefaultHeaders(array $headers): self
    {
        $this->defaultHeaders = $headers;
        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->options = $options + $this->options;
        return $this;
    }

    public function setMultiOptions(array $options): self
    {
        $this->multiOptions = $options + $this->multiOptions;
        return $this;
    }

    public function get(string $path, array $headers = []): self
    {
        $url = $this->baseUrl . ltrim($path, '/');
        return $this->addRequests([
            "$path" => [
                'url' => $url,
                'headers' => $headers
            ]
        ]);
    }

    public function post(string $path, $data = null, array $headers = []): self
    {
        $url = $this->baseUrl . ltrim($path, '/');
        if (empty($headers)) {
            $headers = ['Content-Type: application/json'];
            $data = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
        return $this->addRequests([
            "$path" => [
                'url' => $url,
                'headers' => $headers,
                'options' => [
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $data
                ]
            ]
        ]);
    }

    public function put(string $path, $data = null, array $headers = []): self
    {
        $url = $this->baseUrl . ltrim($path, '/');
        if (empty($headers)) {
            $headers = ['Content-Type: application/json'];
            $data = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
        return $this->addRequests([
            "$path" => [
                'url' => $url,
                'headers' => $headers,
                'options' => [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => $data
                ]
            ]
        ]);
    }

    public function patch(string $path, $data = null, array $headers = []): self
    {
        $url = $this->baseUrl . ltrim($path, '/');
        return $this->addRequests([
            "$path" => [
                'url' => $url,
                'headers' => $headers,
                'options' => [
                    CURLOPT_CUSTOMREQUEST => 'PATCH',
                    CURLOPT_POSTFIELDS => $data
                ]
            ]
        ]);
    }

    public function delete(string $path, $data = null, array $headers = []): self
    {
        $url = $this->baseUrl . ltrim($path, '/');
        return $this->addRequests([
            "$path" => [
                'url' => $url,
                'headers' => $headers,
                'options' => [
                    CURLOPT_CUSTOMREQUEST => 'DELETE',
                    CURLOPT_POSTFIELDS => $data
                ]
            ]
        ]);
    }

    /**
     * $reqs is an associative array of urls or an array of arrays with 'url', 'headers',
     * 'body' and 'options' keys.
     *
     * @param array $reqs
     * @return $this
     */
    public function addRequests(array $reqs): self
    {
        foreach ($reqs as $key => $req) {
            $url = is_array($req) ? $req['url'] : $req;
            $customOptions = $this->options;
            $headers = $this->defaultHeaders;

            if (is_array($req) && isset($req['headers']) && !empty($req['headers'])) {
                $headers = $headers + $req['headers'];
            }
            if (!empty($headers)) {
                $customOptions[CURLOPT_HTTPHEADER] = $headers;
            }
            if (is_array($req) && isset($req['options']) && !empty($req['options'])) {
                $customOptions = $req['options'] + $customOptions;
            }

            if ($this->collectResponseHeaders && !isset($customOptions[CURLOPT_HEADERFUNCTION])) {
                $customOptions[CURLOPT_HEADERFUNCTION] = function ($ch, $header) use ($key) {
                    $this->completedRequests[$key]['headers'][] = trim($header);
                    return strlen($header);
                };
            }

            if (is_callable($this->preRequestCallback)) {
                ($this->preRequestCallback)($req, $customOptions);
            }

            $host = parse_url($url, PHP_URL_HOST);
            $ch = $this->getHandle($host);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt_array($ch, $customOptions);
            if ($this->sharingEnabled && $this->sh !== null) {
                curl_setopt($ch, CURLOPT_SHARE, $this->sh);
            }
            $this->activeHandles[$key] = $ch;
            curl_multi_add_handle($this->mh, $ch);
        }
        return $this;
    }

    private function getHandle(string $host): \CurlHandle
    {
        if (!empty($this->handlePools[$host])) {
            return array_pop($this->handlePools[$host]);
        }
        return curl_init();
    }

    public function execute(): void
    {
        $active = null;
        do {
            while (($mrc = curl_multi_exec($this->mh, $active)) === CURLM_CALL_MULTI_PERFORM);
            if ($mrc !== CURLM_OK) {
                $this->multiErrors[] = $this->interpretCurlmError($mrc);
                break;
            }

            while ($completed = curl_multi_info_read($this->mh)) {
                /** @var \CurlHandle $handle */
                $handle = $completed['handle'];
                $result = $completed['result'];
                $key = array_search($handle, $this->activeHandles, true);
                if ($key === false) continue;

                if ($result !== CURLE_OK) {
                    $this->errors[$key] = [
                        'error_no' => $result,
                        'error_msg' => curl_strerror($result)
                    ];

                    if (is_callable($this->errorCallback)) {
                        ($this->errorCallback)($key, $this->errors[$key]);
                    }
                }

                $info = curl_getinfo($handle);
                $output = curl_multi_getcontent($handle);

                // separate assignments in case headers are being collected.
                $this->completedRequests[$key]['info'] = $info;
                $this->completedRequests[$key]['output'] = $output;
                $this->completedRequests[$key]['error'] = curl_error($handle);

                if ($this->collectStats) {
                    $this->requestStats[$key] = $this->calculateStats($info);
                }

                curl_multi_remove_handle($this->mh, $handle);
                $host = parse_url(curl_getinfo($handle, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST);
                $this->returnHandle($handle, $host);
                unset($this->activeHandles[$key]);

                if (is_callable($this->completedCallback)) {
                    ($this->completedCallback)($key, $this->completedRequests[$key]);
                }
            }

            if ($active && is_callable($this->idleCallback)) {
                ($this->idleCallback)();
            }

            if ($active) {
                curl_multi_select($this->mh);
            }
        } while ($active);
    }

    public function onCompleted(callable $callback): self
    {
        $this->completedCallback = $callback;
        return $this;
    }

    public function onError(callable $callback): self
    {
        $this->errorCallback = $callback;
        return $this;
    }

    public function onIdle(callable $callback): self
    {
        $this->idleCallback = $callback;
        return $this;
    }

    /**
     * This callback is called before each request is queued for execution if it
     * is defined before calling addRequests().
     *
     * If you want a mixture of requests in a batch to have different (or no) callback,
     * you can define the callback before calling addRequests() and set it to null afterwards,
     * before queuing the requests that should not have the callback.
     *
     * The callback should accept two arguments: the request data array and the options array.
     * The options array is passed by reference so that it can be modified.
     *
     * Therefore, the callback function signature is:
     *
     * function (array $request, array &$options): void
     *
     * The callback function can modify the options array to change the behavior of the request.
     *
     * @param callable $callback
     * @return FastCurl
     */
    public function onPreRequest(callable $callback): self
    {
        $this->preRequestCallback = $callback;
        return $this;
    }

    private function interpretCurlmError(int $errorCode): string
    {
        switch ($errorCode) {
            case CURLM_BAD_HANDLE:
                return 'An invalid multi handle was passed. (CURLM_BAD_HANDLE)';
            case CURLM_BAD_EASY_HANDLE:
                return 'An invalid easy handle was passed. (CURLM_BAD_EASY_HANDLE)';
            case CURLM_OUT_OF_MEMORY:
                return 'Out of memory. (CURLM_OUT_OF_MEMORY)';
            case CURLM_INTERNAL_ERROR:
                return 'Internal error. (CURLM_INTERNAL_ERROR)';
            default:
                return 'Unknown multi handle error.';
        }
    }

    private function returnHandle(\CurlHandle $handle, string $host): void
    {
        $this->handlePools[$host][] = $handle;
    }

    public function __get($key)
    {
        return $this->completedRequests[$key] ?? null;
    }

    public function getCompletedRequests(): array
    {
        return $this->completedRequests;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMultiErrors(): array
    {
        return $this->multiErrors;
    }

    private function calculateStats(array $info): array
    {
        $totalTime = $info['total_time'];
        $stats = [
            'namelookup_time'    => $info['namelookup_time'],
            'namelookup_pct'     => $info['namelookup_time'] / $totalTime * 100,
            'connect_time'       => $info['connect_time'],
            'connect_pct'        => $info['connect_time'] / $totalTime * 100,
            'pretransfer_time'   => $info['pretransfer_time'],
            'pretransfer_pct'    => $info['pretransfer_time'] / $totalTime * 100,
            'starttransfer_time' => $info['starttransfer_time'],
            'starttransfer_pct'  => $info['starttransfer_time'] / $totalTime * 100,
            'total_time'         => $totalTime,
            'redirect'           => $info['redirect_time'],
            'redirect_pct'       => ($info['redirect_time'] ?? 0) / $totalTime * 100,
        ];
        return $stats;
    }

    public function getStats(): array
    {
        return $this->requestStats;
    }

    public function enableStats(bool $enable = true): self
    {
        $this->collectStats = $enable;
        return $this;
    }

    public function __destruct()
    {
        foreach ($this->handlePools as $pool) {
            foreach ($pool as $handle) {
                curl_close($handle);
            }
        }
        curl_multi_close($this->mh);
        if ($this->sh !== null) {
            curl_share_close($this->sh);
        }
    }
}
