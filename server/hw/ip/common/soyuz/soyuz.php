<?php

namespace hw\ip\common\soyuz;

/**
 * Trait providing common functionality related to Soyuz devices.
 */
trait soyuz
{

    public function configureEventServer(string $url): void
    {
        ['host' => $server, 'port' => $port] = parse_url_ext($url);

        $this->apiCall('/v2/log', 'PUT', ['syslog' => [
            'server' => $server.':'.$port,
            'enable' => true,
            ]
        ]);

    }

    public function configureNtp(string $server, int $port = 123, string $timezone = 'Europe/Moscow'): void
    {
        $this->apiCall('/v2/system/tz', 'PUT', ['tz' => $timezone], 15);
        $this->apiCall('/v2/system/ntp', 'PUT', ['ntp' => [$server]], 60);
    }

    public function getSysinfo(): array
    {
        $sysinfo = [];
        $info = $this->apiCall('/v2/system/info', 'GET', [], 3);
        $versions = $this->apiCall('/v2/system/versions', 'GET', [], 3);

        if ($info && $versions) {
            $sysinfo['DeviceID'] = $info['deviceID'] ?? '';
            $sysinfo['DeviceModel'] = $info['model'] ?? ($versions['hostname'] ?? '');
            $sysinfo['HardwareVersion'] = '1.1.0';
            $sysinfo['SoftwareVersion'] = $versions['sw'].'b'.$versions['sw_sub'];
        }

        return $sysinfo;
    }

    public function reboot(): void
    {
        $this->apiCall('/v2/system/reboot', 'PUT', [], 15);
    }

    public function reset(): void
    {
        $this->apiCall('/v2/system/factory-reset', 'PUT');
    }

    public function setAdminPassword(string $password): void
    {
        $this->apiCall('/v2/auth/change_api_password', 'PUT', ['newPassword' => $password], 15);
    }

    public function syncData(): void
    {
        // Empty implementation
    }

    protected function apiCall(
        string $resource,
        string $method = 'GET',
        array  $payload = [],
        int    $timeout = 0,
    ): bool|array|string
    {
        $req = $this->url . $resource;

        $ch = curl_init($req);

        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_USERPWD, "$this->login:$this->password");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);

        if ($timeout <= 0) {
            $timeout = ($method === 'GET') ? 15 : 45;
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if ($payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $res = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) {
            throw new \Exception('Soyuz API: неверный пароль пользователя api (HTTP 401)');
        }

        if ($httpCode >= 400) {
            throw new \Exception("Soyuz API: HTTP $httpCode для $resource");
        }

        $array_res = json_decode($res, true);

        if ($array_res === null) {
            return $res;
        }

        return $array_res;
    }

    protected function getEventServer(): string
    {
        $syslog = $this->apiCall('/v2/log');
        return 'syslog.udp' . ':' . $syslog['syslog']['server'];

    }

    protected function getNtpConfig(): array
    {
        $general = $this->apiCall('/v2/system/general', 'GET', [], 15);
        $ntp = $this->apiCall('/v2/system/ntp', 'GET', [], 15);
        $servers = is_array($ntp['ntp'] ?? null) ? $ntp['ntp'] : (is_array($ntp) ? $ntp : []);
        return [
            'server' => $servers[0] ?? 'pool.ntp.org',
            'port' => 123,
            'timezone' => $general['tz'] ?? 'Europe/Moscow',
        ];
    }

    protected function initializeProperties(): void
    {
        $this->login = 'api';
        if (!isset($this->defaultPassword) || $this->defaultPassword === '') {
            $this->defaultPassword = '123456';
        }
    }

    protected function requiresRebootAfterPasswordChange(): bool
    {
        return true;
    }
}
