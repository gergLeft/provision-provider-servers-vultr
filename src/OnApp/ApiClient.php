<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\Servers\OnApp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionProviders\Servers\Data\CreateParams;
use Upmind\ProvisionProviders\Servers\Data\ResizeParams;
use Upmind\ProvisionProviders\Servers\OnApp\Data\Configuration;

class ApiClient
{
    protected Configuration $configuration;
    protected Client $client;

    public function __construct(Configuration $configuration, ?HandlerStack $handler = null)
    {
        $this->configuration = $configuration;

        $credentials = base64_encode("{$this->configuration->username}:{$this->configuration->password}");

        $this->client = new Client([
            'base_uri' => sprintf('https://%s/', $configuration->hostname),
            'headers' => [
                'Accept' => 'application/json',
                'Content-type' => 'application/json',
                'Authorization' => ['Basic ' . $credentials],
            ],
            'connect_timeout' => 10,
            'timeout' => $configuration->timeout ?? 120,
            'handler' => $handler,
        ]);
    }


    public function makeRequest(
        string  $command,
        ?array  $params = null,
        ?array  $body = null,
        ?string $method = 'GET'
    ): ?array
    {
        try {
            $requestParams = [];

            if ($params) {
                $requestParams['query'] = $params;
            }

            if ($body) {
                $requestParams['json'] = $body;
            }

            $response = $this->client->request($method, $command, $requestParams);
            $result = $response->getBody()->getContents();

            $response->getBody()->close();

            if ($result === '') {
                return null;
            }

            return $this->parseResponseData($result);
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    private function parseResponseData(string $response): array
    {
        $parsedResult = json_decode($response, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $response,
                ]);
        }

        return $parsedResult;
    }

    /**
     * @return no-return
     */
    private function handleException(Throwable $e): void
    {
        if ($e instanceof ConnectException) {
            throw ProvisionFunctionError::create('Provider API Connection error')
            ->withData([
                'message' => $e->getMessage(),
            ]);
        }

        if ($e instanceof TransferException) {
            if ($e instanceof BadResponseException && $e->hasResponse()) {
                $response = $e->getResponse();
                $reason = $response->getReasonPhrase();
                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);

                $messages = [];
                $errors = $responseData['errors'] ?? $responseData['error'] ?? [];
                foreach ($errors as $key => $value) {
                    if (is_array($value)) {
                        $messages[] = $key . ': ' . implode(', ', $value);
                    } else {
                        $messages[] = $value;
                    }
                }

                if ($messages) {
                    $errorMessage = implode(', ', $messages);
                }

                if (!isset($errorMessage) && $reason === 'Unauthorized') {
                    $errorMessage = 'Unauthorized - check credentials and whitelisted IPs';
                }
            }

            $errorMessage = sprintf('Provider API error: %s', $errorMessage ?? $reason ?? 'Unknown');
            throw ProvisionFunctionError::create($errorMessage)
                ->withData([
                    'response_data' => $responseData ?? null,
                ]);
        }

        throw ProvisionFunctionError::create('Unknown Provider API Error')
            ->withData([
                'message' => $e->getMessage(),
            ]);
    }

    public function getServerInfo(string $serverId): ?array
    {
        $response = $this->makeRequest("/virtual_machines/{$serverId}.json");
        $vm = $response['virtual_machine'];

        $primaryDisk = $this->getPrimaryDisk($serverId);

        $location = $this->getLocation($vm['hypervisor_id']);

        $ipAddress = null;

        if (count($vm['ip_addresses']) == 1) {
            $ipAddress = $vm['ip_addresses'][0]['ip_address']['address'];
        } else {
            foreach ($vm['ip_addresses'] as $ip) {
                $ipAddress = filter_var(
                    $ip['ip_address']['address'],
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                );

                if ($ipAddress) {
                    break;
                }
            }
        }

        return [
            'instance_id' => (string)$vm['id'] ?? 'Unknown',
            'state' => $this->getState($vm),
            'label' => $vm['label'] ?? 'Unknown',
            'hostname' => $vm['hostname'] ?? 'Unknown',
            'ip_address' => $ipAddress,
            'image' => $vm['template_label'] ?? 'Unknown',
            'memory_mb' => (int)$vm['memory'] ?? 0,
            'cpu_cores' => (int)$vm['cpus'] ?? 0,
            'disk_mb' => (int)$primaryDisk['disk_size'] * 1024 ?? 0,
            'location' => $location ?? 'Unknown',
            'virtualization_type' => $vm['hypervisor_type'],
            'created_at' => isset($vm['created_at'])
                ? Utils::formatDate((string)$vm['created_at'])
                : null,
            'updated_at' => isset($vm['updated_at'])
                ? Utils::formatDate((string)$vm['updated_at'])
                : null,
        ];
    }

    public function getState(array $vm): string
    {
        if ($vm['booted']) {
            $state = 'On';
        } else {
            $state = 'Off';
        }

        if ($vm['locked']) {
            $state = 'Locked';
        }

        return $state;
    }

    public function getPassword(string $serverId): ?string
    {
        $response = $this->makeRequest("/virtual_machines/{$serverId}.json");
        $vm = $response['virtual_machine'];

        return (string)$vm['initial_root_password'];
    }

    public function create(CreateParams $params): string
    {
        $body = [
            'virtual_machine' => [
                'cpu_shares' => 1,
                'hostname' => $params->label,
                'label' => $params->label,
                'template_id' => $params->image,
                'memory' => $params->memory_mb,
                'cpus' => $params->cpu_cores,
                'primary_disk_size' => ($params->disk_mb) / 1024,
                'required_virtual_machine_build' => 1,
                'location_id' => $params->location,
            ]
        ];

        $response = $this->makeRequest("/virtual_machines.json", null, $body, 'POST');

        return (string)$response['virtual_machine']['id'];
    }

    public function changePassword(string $serverId, string $password): void
    {
        $body = [
            'virtual_machine' => [
                'initial_root_password' => $password,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}/reset_password.json", null, $body, 'POST');
    }

    public function resize(string $serverId, ResizeParams $params): void
    {
        $primaryDisk = $this->getPrimaryDisk($serverId);

        $body = [
            'virtual_machine' => [
                'memory' => $params->memory_mb,
                'cpus' => $params->cpu_cores,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}.json", null, $body, 'PUT');

        $body = [
            'disk' => [
                'disk_size' => ($params->disk_mb) / 1024,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}/disks/{$primaryDisk['id']}.json", null, $body, 'PUT');

    }

    public function getPrimaryDisk(string $serverId): array
    {
        $response = $this->makeRequest("/virtual_machines/{$serverId}/disks.json");
        return $response[0]['disk'];
    }

    public function reboot(string $serverId): void
    {
        $this->makeRequest("/virtual_machines/{$serverId}/reboot.json", null, null, 'POST');
    }

    public function shutdown(string $serverId): void
    {
        $this->makeRequest("/virtual_machines/{$serverId}/shutdown.json", null, null, 'POST');
    }

    public function start(string $serverId): void
    {

        $this->makeRequest("/virtual_machines/{$serverId}/startup.json", null, null, 'POST');
    }

    public function destroy(string $serverId): void
    {
        $params = [
            'destroy_all_backups' => 1,
        ];

        $this->makeRequest("/virtual_machines/{$serverId}.json", $params, null, 'DELETE');
    }

    public function rebuildServer(string $serverId, string $image)
    {
        $body = [
            'virtual_machine' => [
                'template_id' => $image,
                'required_startup' => 1,
            ]
        ];

        $this->makeRequest("/virtual_machines/{$serverId}/build.json", null, $body, 'POST');
    }

    public function getLocation(int $hypervisor_id): ?string
    {
        $response = $this->makeRequest("/settings/hypervisors/{$hypervisor_id}.json");
        $hypervisorGroupId = $response['hypervisor']['hypervisor_group_id'];

        $response = $this->makeRequest("/settings/hypervisor_zones/{$hypervisorGroupId}.json");
        $locationId = $response['hypervisor_group']['location_group_id'];

        $response = $this->makeRequest("/settings/location_groups/{$locationId}.json");
        $location = $response['location_group'];

        return "{$location['country']} ({$location['city']})";
    }
}


