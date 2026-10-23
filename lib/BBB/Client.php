<?php

namespace ExclusiveBBB\BBB;

/**
 * Minimal BigBlueButton 3.0 API client for one node.
 *
 * Checksums are computed here only; the secret never leaves PHP.
 */
class Client
{
    public const ALGORITHMS = ['sha1', 'sha256', 'sha384', 'sha512'];

    private string $base_url;
    private string $secret;
    private string $algorithm;
    private int $timeout;

    /** @var callable|null fn(string $url): string, replaces the HTTP layer in tests */
    private $transport;

    public function __construct(string $url, string $secret, string $algorithm = 'sha256', int $timeout = 10, ?callable $transport = null)
    {
        if (!in_array($algorithm, self::ALGORITHMS, true)) {
            throw new \InvalidArgumentException('Unsupported checksum algorithm: ' . $algorithm);
        }
        $this->base_url = self::normalizeUrl($url);
        $this->secret = $secret;
        $this->algorithm = $algorithm;
        $this->timeout = $timeout;
        $this->transport = $transport;
    }

    /**
     * Accepts "https://host", "https://host/bigbluebutton" or ".../bigbluebutton/api"
     * and returns the API base ending in "/bigbluebutton/api/".
     */
    public static function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!preg_match('#/api$#', $url)) {
            if (!preg_match('#/bigbluebutton$#', $url)) {
                $url .= '/bigbluebutton';
            }
            $url .= '/api';
        }
        return $url . '/';
    }

    public function checksum(string $call, string $query): string
    {
        return hash($this->algorithm, $call . $query . $this->secret);
    }

    public function buildUrl(string $call, array $params = []): string
    {
        $params = array_filter($params, fn($value) => $value !== null);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $checksum = $this->checksum($call, $query);
        return $this->base_url . $call . '?' . ($query !== '' ? $query . '&' : '') . 'checksum=' . $checksum;
    }

    /**
     * @return array create response as array
     */
    public function create(array $params): array
    {
        return $this->call('create', $params);
    }

    /**
     * Returns the signed join URL. The user is redirected there; nothing is sent from PHP.
     */
    public function joinUrl(string $meeting_id, string $full_name, bool $moderator, array $extra = []): string
    {
        return $this->buildUrl('join', array_merge([
            'meetingID' => $meeting_id,
            'fullName'  => $full_name,
            'role'      => $moderator ? 'MODERATOR' : 'VIEWER',
        ], $extra));
    }

    public function end(string $meeting_id): void
    {
        try {
            $this->call('end', ['meetingID' => $meeting_id]);
        } catch (ClientException $e) {
            // ending a meeting that is already gone is fine
            if ($e->messageKey !== 'notFound') {
                throw $e;
            }
        }
    }

    public function isMeetingRunning(string $meeting_id): bool
    {
        $response = $this->call('isMeetingRunning', ['meetingID' => $meeting_id]);
        return ($response['running'] ?? 'false') === 'true';
    }

    /**
     * @return array[] list of meetings with keys meetingID, meetingName, running,
     *                 participantCount, videoCount, moderatorCount, createTime, metadata (array)
     */
    public function getMeetings(): array
    {
        $xml = $this->request($this->buildUrl('getMeetings'));
        $meetings = [];
        foreach ($xml->meetings->meeting ?? [] as $meeting) {
            $metadata = [];
            foreach ($meeting->metadata->children() ?? [] as $key => $value) {
                $metadata[strtolower($key)] = (string) $value;
            }
            $meetings[] = [
                'meetingID'        => (string) $meeting->meetingID,
                'meetingName'      => (string) $meeting->meetingName,
                'running'          => (string) $meeting->running === 'true',
                'participantCount' => (int) $meeting->participantCount,
                'videoCount'       => (int) $meeting->videoCount,
                'moderatorCount'   => (int) $meeting->moderatorCount,
                'createTime'       => (int) $meeting->createTime,
                'metadata'         => $metadata,
            ];
        }
        return $meetings;
    }

    private function call(string $call, array $params): array
    {
        $xml = $this->request($this->buildUrl($call, $params));
        return json_decode(json_encode($xml), true) ?: [];
    }

    private function request(string $url): \SimpleXMLElement
    {
        $body = $this->transport ? ($this->transport)($url) : $this->httpGet($url);

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new ClientException('Invalid response from BigBlueButton');
        }
        if ((string) $xml->returncode !== 'SUCCESS') {
            throw new ClientException(
                (string) ($xml->message ?: 'BigBlueButton call failed'),
                (string) $xml->messageKey ?: null
            );
        }
        return $xml;
    }

    private function httpGet(string $url): string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new ClientException('Connection failed: ' . $error);
        }
        if ($status >= 400) {
            throw new ClientException('HTTP status ' . $status);
        }
        return $body;
    }
}
