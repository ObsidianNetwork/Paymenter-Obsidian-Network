<?php

namespace Paymenter\Extensions\Servers\Pterodactyl;

use App\Classes\Extension\Server;
use App\Exceptions\PermanentProvisioningException;
use App\Models\ConfigOption;
use App\Models\Extension;
use App\Models\Service;
use App\Services\Service\DurableFulfillmentService;
use App\Support\PanelEndpointIdentity;
use App\Support\StrictInteger;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Class Pterodactyl
 */
class Pterodactyl extends Server
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    private const USER_LOOKUP_MAX_PAGES = 20;

    private const USER_LOOKUP_MAX_RECORDS = 1000;

    private const CONFIG_COLLECTION_MAX_PAGES = 20;

    private const CONFIG_COLLECTION_MAX_RECORDS = 1000;

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'host',
                'label' => 'Pterodactyl URL',
                'type' => 'text',
                'description' => 'Pterodactyl URL',
                'required' => true,
                'validation' => 'url',
            ],
            [
                'name' => 'api_key',
                'label' => 'Pterodactyl API Key',
                'type' => 'text',
                'description' => 'Pterodactyl API Key',
                'required' => true,
                'encrypted' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->request('/api/application/servers', 'GET');
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    public function request($url, $method = 'get', $data = []): array
    {
        // Trim any leading slashes from the base url and add the path URL to it
        $req_url = rtrim($this->config('host'), '/') . $url;
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->config('api_key'),
            'Accept' => 'application/json',
        ])
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            // Never retry a mutating request in the HTTP client. Queue-level
            // reconciliation determines whether a timed-out create, update,
            // suspend, or delete actually reached Pterodactyl.
            ->$method($req_url, $data);

        if (!$response->successful()) {
            $detail = $response->json('errors.0.detail')
                ?? "Pterodactyl API request failed with status {$response->status()}";
            $exception = $response->clientError()
                && !in_array($response->status(), [408, 409, 423, 425, 429], true)
                ? new PermanentProvisioningException((string) $detail, $response->status())
                : new Exception((string) $detail, $response->status());

            throw $exception;
        }

        return $response->json() ?? [];
    }

    public function getProductConfig($values = []): array
    {
        $nodeList = $this->pterodactylConfigurationOptions(
            '/api/application/nodes',
            'node',
            'name'
        );
        $locationList = $this->pterodactylConfigurationOptions(
            '/api/application/locations',
            'location',
            'short'
        );
        $nestList = $this->pterodactylConfigurationOptions(
            '/api/application/nests',
            'nest',
            'name'
        );

        $eggList = [];
        if (isset($values['nest_id']) && $values['nest_id'] !== '') {
            $eggList = $this->pterodactylConfigurationOptions(
                '/api/application/nests/' . $values['nest_id'] . '/eggs',
                'egg',
                'name'
            );
        }

        $using_port_array = isset($values['port_array']) && $values['port_array'] !== '';

        return [
            [
                'name' => 'location_ids',
                'label' => 'Location(s)',
                'type' => 'select',
                'description' => 'Location(s) where the server will be installed',
                'options' => $locationList,
                'multiple' => true,
                'database_type' => 'array',
                'required' => false,
            ],
            [
                'name' => 'node',
                'label' => 'Node',
                'type' => 'select',
                'description' => 'Fill in to install the server on a specific node',
                'options' => $nodeList,
            ],
            [
                'name' => 'nest_id',
                'label' => 'Nest ID',
                'type' => 'select',
                'options' => $nestList,
                'description' => 'Nest ID to fetch the eggs from',
                'required' => true,
                // Lets fetch the eggs every time the nest id changes
                'live' => true,
            ],
            [
                'name' => 'egg_id',
                'label' => 'Egg ID',
                'type' => 'select',
                'options' => $eggList,
                'required' => true,
            ],
            [
                'name' => 'memory',
                'label' => 'Memory',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'validation' => 'numeric',
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'swap',
                'label' => 'Swap',
                'type' => 'number',
                'min_value' => -1,
                'suffix' => 'MiB',
                'required' => true,
                'description' => 'Set to -1 for unlimited, or to 0 to disable swap',
            ],
            [
                'name' => 'disk',
                'label' => 'Disk',
                'type' => 'number',
                'suffix' => 'MiB',
                'required' => true,
                'min_value' => 0,
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'io',
                'label' => 'IO Weight',
                'type' => 'number',
                'required' => true,
                'default' => 500,
                'min_value' => 10,
                'max_value' => 1000,
                'description' => 'The IO Weight is the priority given to this server for disk access.',
                'hint' => new HtmlString('<a href="https://docs.docker.com/engine/reference/run/#block-io-bandwidth-blkio-constraint" target="_blank">Documentation</a>'),
            ],
            [
                'name' => 'cpu',
                'label' => 'CPU Limit',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
                'suffix' => '%',
                'description' => 'Set to 0 for unlimited',
            ],
            [
                'name' => 'cpu_pinning',
                'label' => 'CPU Pinning',
                'type' => 'text',
                'description' => 'Leave empty for no pinning. Used to specify what threads should be used. Example: 0,2-4,5,6',
                'validation' => 'regex:/^[0-9]+(?:-[0-9]+)?(?:,[0-9]+(?:-[0-9]+)?)*$/',
            ],
            [
                'name' => 'databases',
                'label' => 'Databases',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'backups',
                'label' => 'Backups',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'additional_allocations',
                'label' => 'Additional Allocations',
                'type' => 'number',
                'required' => true,
                'min_value' => 0,
            ],
            [
                'name' => 'port_array',
                'label' => 'Port Array',
                'type' => 'text',
                'description' => 'Used to assign ports to egg variables.',
                'hint' => new HtmlString('<a href="https://paymenter.org/docs/extensions/pterodactyl#port-array" target="_blank">Documentation</a>'),
                'live' => true,
                'validation' => 'json',
            ],
            [
                'name' => 'port_range',
                'label' => 'Port ranges',
                'type' => 'tags',
                'description' => '',
                'database_type' => 'array',
                'required' => false,
                'disabled' => $using_port_array,
            ],
            [
                'name' => 'skip_scripts',
                'label' => 'Skip Egg Install Script',
                'description' => 'If the selected Egg has an install script attached to it, the script will run during the install. If you would like to skip this step, check this box.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'dedicated_ip',
                'label' => 'Dedicated IP',
                'description' => 'Assigns the server an allocation whose IP is not being used by any other server.',
                'type' => 'checkbox',
                'disabled' => $using_port_array,
            ],
            [
                'name' => 'start_on_completion',
                'label' => 'Start on completion',
                'description' => 'Start server automatically after installation.',
                'type' => 'checkbox',
            ],
            [
                'name' => 'oom_killer',
                'label' => 'Enable OOM Killer',
                'description' => 'Terminates the server if it breaches the memory limits. Enabling OOM killer may cause server processes to exit unexpectedly.',
                'type' => 'checkbox',
            ],
        ];
    }

    /**
     * Load an application collection without trusting server-supplied links.
     * Every option endpoint shares this one bounded, fail-closed traversal.
     *
     * @return array<int, string>
     */
    private function pterodactylConfigurationOptions(
        string $url,
        string $collection,
        string $labelField
    ): array {
        $options = [];
        foreach (
            $this->pterodactylConfigurationRecords($url, $collection) as $attributes
        ) {
            $id = StrictInteger::parse($attributes['id'] ?? null);
            $label = $attributes[$labelField] ?? null;
            if (
                $id === null
                || $id <= 0
                || !is_string($label)
                || trim($label) === ''
            ) {
                throw new PermanentProvisioningException(
                    "Pterodactyl {$collection} configuration lookup returned an invalid option record."
                );
            }

            $options[$id] = $label;
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pterodactylConfigurationRecords(
        string $url,
        string $collection
    ): array {
        $records = [];
        $recordsSeen = 0;
        $recordIdsSeen = [];
        $expectedPagination = null;

        for (
            $page = 1;
            $page <= self::CONFIG_COLLECTION_MAX_PAGES;
            $page++
        ) {
            try {
                $response = $this->request(
                    $url,
                    'get',
                    ['page' => $page]
                );
            } catch (\Throwable $exception) {
                throw new Exception(
                    "Pterodactyl {$collection} configuration lookup failed: {$exception->getMessage()}",
                    (int) $exception->getCode(),
                    $exception
                );
            }

            $pageRecords = $response['data'] ?? null;
            $meta = $response['meta'] ?? null;
            $pagination = is_array($meta)
                ? ($meta['pagination'] ?? null)
                : null;
            if (
                !is_array($pageRecords)
                || !array_is_list($pageRecords)
                || !is_array($pagination)
            ) {
                throw new PermanentProvisioningException(
                    "Pterodactyl {$collection} configuration lookup returned an invalid collection response."
                );
            }

            $validated = $this->validatePterodactylConfigurationPagination(
                $pagination,
                count($pageRecords),
                $page,
                $collection
            );
            $stablePagination = [
                'total' => $validated['total'],
                'per_page' => $validated['per_page'],
                'total_pages' => $validated['total_pages'],
            ];
            if ($expectedPagination === null) {
                $expectedPagination = $stablePagination;
            } elseif ($stablePagination !== $expectedPagination) {
                throw new PermanentProvisioningException(
                    "Pterodactyl {$collection} configuration pagination changed during traversal."
                );
            }

            $recordsSeen += $validated['count'];
            if (
                $recordsSeen > $validated['total']
                || $recordsSeen > self::CONFIG_COLLECTION_MAX_RECORDS
            ) {
                throw new PermanentProvisioningException(
                    "Pterodactyl {$collection} configuration lookup returned invalid pagination metadata."
                );
            }

            foreach ($pageRecords as $record) {
                if (
                    !is_array($record)
                    || !is_array($record['attributes'] ?? null)
                ) {
                    throw new PermanentProvisioningException(
                        "Pterodactyl {$collection} configuration lookup returned an invalid option record."
                    );
                }

                $attributes = $record['attributes'];
                $recordId = StrictInteger::parse(
                    $attributes['id'] ?? null
                );
                if ($recordId === null || $recordId <= 0) {
                    throw new PermanentProvisioningException(
                        "Pterodactyl {$collection} configuration lookup returned an invalid option identity."
                    );
                }
                if (isset($recordIdsSeen[$recordId])) {
                    throw new PermanentProvisioningException(
                        "Pterodactyl {$collection} configuration lookup returned a duplicate option identity."
                    );
                }
                $recordIdsSeen[$recordId] = true;
                $records[] = $attributes;
            }

            if ($page === $validated['total_pages']) {
                if ($recordsSeen !== $validated['total']) {
                    throw new PermanentProvisioningException(
                        "Pterodactyl {$collection} configuration lookup returned truncated results."
                    );
                }

                return $records;
            }
        }

        throw new PermanentProvisioningException(
            "Pterodactyl {$collection} configuration lookup exceeded its pagination limit."
        );
    }

    /**
     * @param  array<string, mixed>  $pagination
     * @return array{
     *     total: int,
     *     count: int,
     *     per_page: int,
     *     current_page: int,
     *     total_pages: int
     * }
     */
    private function validatePterodactylConfigurationPagination(
        array $pagination,
        int $actualCount,
        int $requestedPage,
        string $collection
    ): array {
        $total = $pagination['total'] ?? null;
        $count = $pagination['count'] ?? null;
        $perPage = $pagination['per_page'] ?? null;
        $currentPage = $pagination['current_page'] ?? null;
        $totalPages = $pagination['total_pages'] ?? null;
        $links = $pagination['links'] ?? null;
        if (
            !is_int($total)
            || $total < 0
            || $total > self::CONFIG_COLLECTION_MAX_RECORDS
            || !is_int($count)
            || $count < 0
            || !is_int($perPage)
            || $perPage < 1
            || $perPage > self::CONFIG_COLLECTION_MAX_RECORDS
            || !is_int($currentPage)
            || $currentPage !== $requestedPage
            || !is_int($totalPages)
            || $totalPages < 1
            || $totalPages > self::CONFIG_COLLECTION_MAX_PAGES
            || $currentPage > $totalPages
            || !is_array($links)
        ) {
            throw new PermanentProvisioningException(
                "Pterodactyl {$collection} configuration lookup returned invalid pagination metadata."
            );
        }

        $calculatedPages = $total === 0
            ? 1
            : intdiv($total - 1, $perPage) + 1;
        $offset = ($currentPage - 1) * $perPage;
        $expectedCount = min($perPage, max(0, $total - $offset));
        if (
            $totalPages !== $calculatedPages
            || $count !== $actualCount
            || $count !== $expectedCount
        ) {
            throw new PermanentProvisioningException(
                "Pterodactyl {$collection} configuration lookup returned invalid pagination metadata."
            );
        }

        $this->validatePterodactylConfigurationPaginationLinks(
            $links,
            $currentPage,
            $totalPages,
            $collection
        );

        return [
            'total' => $total,
            'count' => $count,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param  array<string, mixed>  $links
     */
    private function validatePterodactylConfigurationPaginationLinks(
        array $links,
        int $currentPage,
        int $totalPages,
        string $collection
    ): void {
        $previous = $links['previous'] ?? null;
        $next = $links['next'] ?? null;
        if (
            (
                $currentPage === 1
                && $previous !== null
            )
            || (
                $currentPage > 1
                && (
                    !is_string($previous)
                    || $this->pterodactylPaginationLinkPage($previous)
                        !== $currentPage - 1
                )
            )
            || (
                $currentPage === $totalPages
                && $next !== null
            )
            || (
                $currentPage < $totalPages
                && (
                    !is_string($next)
                    || $this->pterodactylPaginationLinkPage($next)
                        !== $currentPage + 1
                )
            )
        ) {
            throw new PermanentProvisioningException(
                "Pterodactyl {$collection} configuration lookup returned non-monotonic pagination metadata."
            );
        }
    }

    public function createServer(Service $service, $settings, $properties)
    {
        $reservationServiceClass = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
        $hasDurableReservation = app(DurableFulfillmentService::class)
            ->isReservationBacked($service);
        $reservationExtensionEnabled = Extension::query()
            ->where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->exists();
        $reservationService = $reservationExtensionEnabled && class_exists($reservationServiceClass)
            ? app($reservationServiceClass)
            : null;
        $requiresReservation = ConfigOption::query()
            ->whereHas('products', fn ($query) => $query->whereKey($service->product_id))
            ->where('type', 'dynamic_slider')
            ->where('hidden', false)
            ->whereNull('parent_id')
            ->get()
            ->contains(fn ($option) => in_array(
                strtolower((string) $option->getMetadata('resource_type', '')),
                ['memory', 'cpu', 'disk'],
                true
            ));
        $requiresReservation = $hasDurableReservation
            || $requiresReservation;
        if ($requiresReservation && $reservationService === null) {
            throw new PermanentProvisioningException(
                'Dynamic capacity reservations are unavailable; provisioning was stopped.'
            );
        }

        $reservation = $requiresReservation
            ? $reservationService->beginProvisioning($service)
            : null;
        if ($hasDurableReservation && $reservation === null) {
            throw new PermanentProvisioningException(
                'The durable capacity reservation could not be acquired; provisioning was stopped.'
            );
        }

        try {
            if (
                $reservation !== null
                && !hash_equals(
                    (string) $reservation['panel_identity'],
                    $this->panelIdentity()
                )
            ) {
                throw new PermanentProvisioningException(
                    'Capacity was reserved on a different Pterodactyl panel.'
                );
            }

            // Smash the properties into the settings, then let the authoritative
            // reservation override every deployable resource, image identity,
            // and placement field.
            $settings = array_merge($settings, $properties);
            if ($reservation !== null) {
                $expectedUserExternalId = "paymenter-user-{$service->user_id}";
                if (
                    !is_numeric($reservation['nest_id'] ?? null)
                    || (int) $reservation['nest_id'] <= 0
                    || !is_numeric($reservation['egg_id'] ?? null)
                    || (int) $reservation['egg_id'] <= 0
                    || !is_string($reservation['user_external_id'] ?? null)
                    || !hash_equals(
                        $expectedUserExternalId,
                        $reservation['user_external_id']
                    )
                ) {
                    throw new PermanentProvisioningException(
                        'The capacity reservation has an invalid Pterodactyl provisioning identity.'
                    );
                }
                $settings['node'] = $reservation['node_id'];
                $settings['location'] = $reservation['location_id'];
                $settings['location_ids'] = [$reservation['location_id']];
                $settings['memory'] = $reservation['memory'];
                $settings['cpu'] = $reservation['cpu'];
                $settings['disk'] = $reservation['disk'];
                $settings['nest_id'] = $reservation['nest_id'];
                $settings['egg_id'] = $reservation['egg_id'];
            }
            if ($reservation === null) {
                $this->assertStaticCreateAvoidsManagedNodes($settings);
            }

            // A Paymenter customer ID is the durable cross-system identity.
            // Resolve it before accepting an existing external server so an
            // unrelated server cannot be reconciled merely because it reused
            // the Paymenter service ID.
            $user = $reservation !== null
                ? $this->resolvePterodactylUser(
                    $service,
                    $expectedUserExternalId
                )
                : null;

            $existingServer = $this->getServer($service->id, failIfNotFound: false, raw: true);
            if ($existingServer) {
                if ($reservation !== null) {
                    $this->assertServerMatchesReservation(
                        $existingServer,
                        $reservation,
                        (int) $service->id,
                        (int) $user,
                        (int) $settings['egg_id'],
                        (int) $settings['nest_id']
                    );
                    $this->assertReservationServerInstallationReady(
                        $existingServer
                    );
                    $activated = $reservationService->completeProvisioning(
                        $service,
                        $reservation['provisioning_lease_id'],
                        $existingServer
                    );
                    if (!$activated) {
                        $this->deleteReconciledServer($existingServer);
                        $reservationService->completeServiceCancellation($service);

                        return [];
                    }
                }

                return [
                    'server' => $existingServer['attributes']['id'],
                    'link' => $this->config('host') . '/server/' . $existingServer['attributes']['identifier'],
                ];
            }

            if (($reservation['already_consumed'] ?? false) === true) {
                throw new PermanentProvisioningException(
                    'Capacity reservation was consumed but the Pterodactyl server is missing.'
                );
            }

            $eggData = $this->request('/api/application/nests/' . $settings['nest_id'] . '/eggs/' . $settings['egg_id'], data: ['include' => 'variables']);
            if (!isset($eggData['attributes'])) {
                throw new Exception('Could not fetch egg data');
            }
            $environment = [];
            foreach ($eggData['attributes']['relationships']['variables']['data'] as $variable) {
                $environment[$variable['attributes']['env_variable']] = $settings[$variable['attributes']['env_variable']] ?? $variable['attributes']['default_value'];
            }

            $user ??= $this->resolvePterodactylUser($service);

            if (isset($settings['location'])) {
                $settings['location_ids'] = [$settings['location']];
            }

            if (
                $reservation !== null
                && !$reservationService->provisioningMayContinue(
                    $service->id,
                    $reservation['provisioning_lease_id']
                )
            ) {
                throw new Exception('Capacity fulfillment was cancelled before server creation.');
            }

            $deploymentData = $reservation !== null
                ? $this->generateReservedDeploymentData($reservation, $environment)
                : $this->generateDeploymentData($settings, $environment);

            $serverCreationData = [
                'external_id' => (string) $service->id,
                'name' => isset($settings['servername']) ? $settings['servername'] : $service->product->name . ' #' . $service->id,
                'user' => (int) $user,
                'egg' => $settings['egg_id'],
                'docker_image' => isset($settings['docker_image']) ? $settings['docker_image'] : $eggData['attributes']['docker_image'],
                'startup' => $eggData['attributes']['startup'],
                'environment' => $deploymentData['environment'],
                'skip_scripts' => $settings['skip_scripts'] ?? false,
                'oom_disabled' => !($settings['oom_killer'] ?? false),
                'limits' => [
                    'memory' => (int) $settings['memory'],
                    'swap' => (int) $settings['swap'],
                    'disk' => (int) $settings['disk'],
                    'io' => (int) $settings['io'],
                    'threads' => $settings['cpu_pinning'] ?? null,
                    'cpu' => (int) $settings['cpu'],
                ],
                'feature_limits' => [
                    'databases' => (int) $settings['databases'],
                    // The Application API assigns the exact IDs below. Keep
                    // the client allocation limit at zero so an owner cannot
                    // delete a reserved secondary allocation and self-assign
                    // an unclaimed replacement.
                    'allocations' => $reservation !== null
                        ? 0
                        : max(0, $deploymentData['allocations_needed'] - 1)
                            + (int) $settings['additional_allocations'],
                    'backups' => (int) $settings['backups'],
                ],
                'start_on_completion' => $settings['start_on_completion'] ?? false,
            ];
            if ($deploymentData['auto_deploy']) {
                $serverCreationData['deploy'] = [
                    'locations' => (array) $settings['location_ids'],
                    'dedicated_ip' => $settings['dedicated_ip'] ?? false,
                    'port_range' => $settings['port_range'] ?? [],
                ];
            } else {
                $serverCreationData['allocation'] = $deploymentData['allocation'];
            }

            $createdServer = $this->request('/api/application/servers', 'post', $serverCreationData);
            $server = $createdServer;
            if ($reservation !== null) {
                $server = $this->getServer($service->id, failIfNotFound: false, raw: true);
                if (!$server) {
                    throw new Exception(
                        'Pterodactyl has not exposed the created server for reconciliation yet.'
                    );
                }
            }

            if ($reservation !== null) {
                $this->assertServerMatchesReservation(
                    $server,
                    $reservation,
                    (int) $service->id,
                    (int) $user,
                    (int) $settings['egg_id'],
                    (int) $settings['nest_id']
                );
                $this->assertReservationServerInstallationReady($server);
                $activated = $reservationService->completeProvisioning(
                    $service,
                    $reservation['provisioning_lease_id'],
                    $server
                );
                if (!$activated) {
                    $this->deleteReconciledServer($server);
                    $reservationService->completeServiceCancellation($service);

                    return [];
                }
            }

            return [
                'server' => $server['attributes']['id'],
                'link' => $this->config('host') . '/server/' . $server['attributes']['identifier'],
            ];
        } catch (\Throwable $exception) {
            if ($reservation !== null) {
                $reservationService->failProvisioning(
                    $service->id,
                    $reservation['provisioning_lease_id'],
                    $exception
                );
            }

            throw $exception;
        }
    }

    /**
     * Enabled capacity-policy nodes are dedicated to the reservation-backed
     * flow. A normal Pterodactyl product must never be able to consume stock
     * that Paymenter has promised to an unpaid invoice.
     *
     * @param  array<string, mixed>  $settings
     */
    private function assertStaticCreateAvoidsManagedNodes(array $settings): void
    {
        $managedPolicies = $this->managedCapacityPolicies();
        if ($managedPolicies === []) {
            return;
        }

        $rawNode = $settings['node'] ?? null;
        $hasPinnedNode = !in_array(
            $rawNode,
            [null, '', false, 0, '0'],
            true
        );
        if ($hasPinnedNode) {
            $nodeId = StrictInteger::parse($rawNode);
            if ($nodeId === null || $nodeId <= 0) {
                throw new PermanentProvisioningException(
                    'Static provisioning cannot prove that the configured Pterodactyl node is unmanaged.'
                );
            }
            if (collect($managedPolicies)->contains(
                fn (array $policy): bool => $policy['node_id'] === $nodeId
            )) {
                throw new PermanentProvisioningException(
                    'This Pterodactyl node is dedicated to reservation-backed dynamic products; static provisioning was stopped.'
                );
            }

            return;
        }

        $rawLocations = (
            array_key_exists('location', $settings)
            && $settings['location'] !== null
            && $settings['location'] !== ''
        )
            ? [$settings['location']]
            : ($settings['location_ids'] ?? []);
        if (!is_array($rawLocations)) {
            $rawLocations = [$rawLocations];
        }
        if ($rawLocations === []) {
            throw new PermanentProvisioningException(
                'Automatic Pterodactyl deployment spans nodes dedicated to reservation-backed dynamic products; select an unmanaged node or location.'
            );
        }

        $locationIds = [];
        foreach ($rawLocations as $rawLocation) {
            $locationId = StrictInteger::parse($rawLocation);
            if ($locationId === null || $locationId <= 0) {
                throw new PermanentProvisioningException(
                    'Static provisioning cannot prove that every configured Pterodactyl location is unmanaged.'
                );
            }
            $locationIds[] = $locationId;
        }

        if (collect($managedPolicies)->contains(
            fn (array $policy): bool => in_array(
                $policy['location_id'],
                $locationIds,
                true
            )
        )) {
            throw new PermanentProvisioningException(
                'Automatic Pterodactyl deployment includes a location with reservation-managed nodes; use a location containing only unmanaged nodes.'
            );
        }
    }

    /**
     * @return list<array{node_id: int, location_id: int}>
     */
    private function managedCapacityPolicies(): array
    {
        if (!Schema::hasTable('ptero_node_capacity_policies')) {
            return [];
        }

        $panelIdentity = $this->panelIdentity();

        return DB::table('ptero_node_capacity_policies')
            ->select(['node_id', 'location_id'])
            ->where('panel_identity', $panelIdentity)
            ->where('enabled', true)
            ->get()
            ->map(function (object $policy): array {
                $nodeId = StrictInteger::parse($policy->node_id);
                $locationId = StrictInteger::parse($policy->location_id);
                if (
                    $nodeId === null
                    || $nodeId <= 0
                    || $locationId === null
                    || $locationId <= 0
                ) {
                    throw new PermanentProvisioningException(
                        'An enabled Pterodactyl capacity policy has an invalid node or location identity.'
                    );
                }

                return [
                    'node_id' => $nodeId,
                    'location_id' => $locationId,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Reject a legacy/non-capacity resize when the existing server is hosted
     * on a node whose stock is controlled by reservations.
     *
     * @param  array<string, mixed>  $server
     */
    private function assertStaticUpgradeAvoidsManagedNode(array $server): void
    {
        $managedPolicies = $this->managedCapacityPolicies();
        if ($managedPolicies === []) {
            return;
        }

        $nodeId = StrictInteger::parse(
            data_get($server, 'attributes.node')
        );
        if ($nodeId === null || $nodeId <= 0) {
            throw new PermanentProvisioningException(
                'Static upgrades cannot prove that the Pterodactyl server is on an unmanaged node.'
            );
        }
        if (collect($managedPolicies)->contains(
            fn (array $policy): bool => $policy['node_id'] === $nodeId
        )) {
            throw new PermanentProvisioningException(
                'This Pterodactyl server is on a reservation-managed node and can only be resized through a capacity-aware upgrade.'
            );
        }
    }

    /**
     * Build deployment data exclusively from allocation IDs claimed alongside
     * the capacity reservation. No live/random allocation selection is allowed.
     */
    private function generateReservedDeploymentData(array $reservation, array $environment): array
    {
        $allocations = collect($reservation['allocations'] ?? []);
        if ($allocations->isEmpty()) {
            throw new PermanentProvisioningException(
                'Capacity reservation has no allocation claim.'
            );
        }

        $primary = $allocations->firstWhere('is_primary', true) ?? $allocations->first();
        $additional = $allocations
            ->reject(fn (array $allocation) => (int) $allocation['allocation_id'] === (int) $primary['allocation_id'])
            ->pluck('allocation_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        foreach ($allocations->groupBy('environment_key') as $environmentKey => $claims) {
            if (
                !$environmentKey
                || strtoupper((string) $environmentKey) === 'NONE'
            ) {
                continue;
            }
            if ($claims->count() !== 1) {
                throw new PermanentProvisioningException(
                    "A Pterodactyl egg environment key may receive exactly one reserved port; {$environmentKey} has multiple claims."
                );
            }
            $environment[$environmentKey] = (int) $claims->first()['port'];
        }
        $environment['SERVER_PORT'] = (int) $primary['port'];

        return [
            'auto_deploy' => false,
            'environment' => $environment,
            'allocations_needed' => $allocations->count(),
            'allocation' => [
                'default' => (int) $primary['allocation_id'],
                'additional' => $additional,
            ],
        ];
    }

    /**
     * Reconciliation is allowed only when the external server is an exact
     * realization of the immutable reservation.
     */
    private function assertServerMatchesReservation(
        array $server,
        array $reservation,
        int $serviceId,
        int $expectedUserId,
        int $expectedEggId,
        int $expectedNestId
    ): void {
        $attributes = $server['attributes'] ?? $server;
        if (
            (string) ($attributes['external_id'] ?? '') !== (string) $serviceId
            || !is_numeric($attributes['id'] ?? null)
            || (int) $attributes['id'] <= 0
            || !is_string($attributes['uuid'] ?? null)
            || !Str::isUuid($attributes['uuid'])
            || !is_string($attributes['identifier'] ?? null)
            || trim($attributes['identifier']) === ''
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid or unrelated external server identity.'
            );
        }

        foreach ([
            'user' => $expectedUserId,
            'egg' => $expectedEggId,
            'nest' => $expectedNestId,
        ] as $field => $expected) {
            if (
                $expected <= 0
                || !is_numeric($attributes[$field] ?? null)
                || (int) $attributes[$field] !== $expected
            ) {
                throw new PermanentProvisioningException(
                    "Existing Pterodactyl server does not match reserved {$field}."
                );
            }
        }

        $limits = $attributes['limits'] ?? [];
        foreach ([
            'node' => (int) $reservation['node_id'],
            'memory' => (int) $reservation['memory'],
            'cpu' => (int) $reservation['cpu'],
            'disk' => (int) $reservation['disk'],
        ] as $field => $expected) {
            $actual = $field === 'node' ? ($attributes['node'] ?? null) : ($limits[$field] ?? null);
            if ($actual === null || (int) $actual !== $expected) {
                throw new PermanentProvisioningException(
                    "Existing Pterodactyl server does not match reserved {$field}."
                );
            }
        }

        if (
            !array_key_exists('allocations', (array) ($attributes['feature_limits'] ?? []))
            || (int) $attributes['feature_limits']['allocations'] !== 0
        ) {
            throw new PermanentProvisioningException(
                'Existing Pterodactyl server permits unreserved client allocation changes.'
            );
        }

        $assignedAllocationIds = collect(
            $attributes['relationships']['allocations']['data']
                ?? $server['relationships']['allocations']['data']
                ?? []
        )->map(fn (array $allocation) => (int) (
            $allocation['attributes']['id'] ?? $allocation['id'] ?? 0
        ))->filter()->unique()->sort()->values();
        $reservedAllocations = collect($reservation['allocations'] ?? []);
        $reservedAllocationIds = $reservedAllocations
            ->map(fn (array $allocation) => (int) ($allocation['allocation_id'] ?? 0))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $reservedPrimaryIds = $reservedAllocations
            ->filter(fn (array $allocation) => (bool) ($allocation['is_primary'] ?? false))
            ->map(fn (array $allocation) => (int) ($allocation['allocation_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if (
            $reservedAllocationIds->isEmpty()
            || $reservedPrimaryIds->count() !== 1
            || !is_numeric($attributes['allocation'] ?? null)
            || (int) $attributes['allocation'] !== (int) $reservedPrimaryIds->first()
        ) {
            throw new PermanentProvisioningException(
                'Existing Pterodactyl server does not use the reserved primary allocation.'
            );
        }
        if ($assignedAllocationIds->all() !== $reservedAllocationIds->all()) {
            throw new PermanentProvisioningException(
                'Existing Pterodactyl server allocation set does not exactly match the reservation.'
            );
        }
    }

    /**
     * Pterodactyl 1.12.3 exposes the server installation state as `status`.
     * A successfully installed server has a null status. Never consume a paid
     * reservation while installation is unfinished or failed.
     *
     * @param  array<string, mixed>  $server
     */
    private function assertReservationServerInstallationReady(
        array $server
    ): void {
        $attributes = $server['attributes'] ?? null;
        if (
            !is_array($attributes)
            || !array_key_exists('status', $attributes)
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl did not return a verifiable server installation status.'
            );
        }

        $status = $attributes['status'];
        if ($status === null) {
            return;
        }
        if (!is_string($status)) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid server installation status.'
            );
        }
        if (in_array($status, [
            'installing',
            'restoring_backup',
        ], true)) {
            throw new Exception(
                "Pterodactyl server is {$status}; provisioning will retry."
            );
        }
        if (in_array($status, [
            'install_failed',
            'reinstall_failed',
        ], true)) {
            throw new PermanentProvisioningException(
                "Pterodactyl server installation is in terminal state {$status}; operator reconciliation is required."
            );
        }

        throw new PermanentProvisioningException(
            "Pterodactyl server is not ready for activation (status: {$status})."
        );
    }

    /**
     * A Paymenter external ID is a lookup key, not proof of ownership. Every
     * lifecycle action for a reservation-backed service must also match the
     * immutable panel, numeric ID, UUID, identifier, owner, node, nest, and
     * egg captured when provisioning completed.
     */
    private function assertDurableLifecycleServer(
        Service $service,
        array $server,
        ?array $identity = null
    ): void {
        $identity ??= $this->durableLifecycleIdentity($service);
        if ($identity === null) {
            return;
        }

        $this->assertDurableLifecycleIdentity($service, $identity);
        $attributes = $server['attributes'] ?? null;
        if (!is_array($attributes)) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid durable server identity; the lifecycle action was stopped.'
            );
        }

        foreach ([
            'id' => (int) $identity['external_server_id'],
            'user' => (int) $identity['external_user_id'],
            'node' => (int) $identity['node_id'],
            'nest' => (int) $identity['nest_id'],
            'egg' => (int) $identity['egg_id'],
        ] as $field => $expected) {
            if (
                !is_numeric($attributes[$field] ?? null)
                || (int) $attributes[$field] !== $expected
            ) {
                throw new PermanentProvisioningException(
                    "Pterodactyl {$field} no longer matches the durable server identity; the lifecycle action was stopped."
                );
            }
        }
        foreach ([
            'uuid' => $identity['external_server_uuid'],
            'identifier' => $identity['external_server_identifier'],
            'external_id' => $identity['external_server_external_id'],
        ] as $field => $expected) {
            if (
                !is_string($attributes[$field] ?? null)
                || !hash_equals($expected, $attributes[$field])
            ) {
                throw new PermanentProvisioningException(
                    "Pterodactyl {$field} no longer matches the durable server identity; the lifecycle action was stopped."
                );
            }
        }

        $users = $this->matchingPterodactylUsers(
            ['external_id' => $identity['user_external_id']],
            'external_id',
            $identity['user_external_id']
        );
        if (
            count($users) !== 1
            || $this->pterodactylUserId($users[0])
                !== (int) $identity['external_user_id']
        ) {
            throw new PermanentProvisioningException(
                'The Pterodactyl server owner no longer matches the durable identity; the lifecycle action was stopped.'
            );
        }
    }

    private function assertDurableLifecycleIdentity(
        Service $service,
        array $identity
    ): void {
        $expectedPanel = $this->panelIdentity();
        $expectedExternalId = "paymenter-user-{$service->user_id}";
        if (
            !is_string($identity['panel_identity'] ?? null)
            || !hash_equals(
                $expectedPanel,
                $identity['panel_identity']
            )
            || !is_numeric($identity['external_server_id'] ?? null)
            || (int) $identity['external_server_id'] <= 0
            || !is_string($identity['external_server_uuid'] ?? null)
            || !Str::isUuid($identity['external_server_uuid'])
            || !is_string(
                $identity['external_server_identifier'] ?? null
            )
            || trim($identity['external_server_identifier']) === ''
            || !is_string(
                $identity['external_server_external_id'] ?? null
            )
            || !hash_equals(
                (string) $service->id,
                $identity['external_server_external_id']
            )
            || !is_string($identity['user_external_id'] ?? null)
            || !hash_equals(
                $expectedExternalId,
                $identity['user_external_id']
            )
            || !is_numeric($identity['external_user_id'] ?? null)
            || (int) $identity['external_user_id'] <= 0
            || !is_numeric($identity['node_id'] ?? null)
            || (int) $identity['node_id'] <= 0
            || !is_numeric($identity['nest_id'] ?? null)
            || (int) $identity['nest_id'] <= 0
            || !is_numeric($identity['egg_id'] ?? null)
            || (int) $identity['egg_id'] <= 0
        ) {
            throw new PermanentProvisioningException(
                'The durable Pterodactyl server identity is incomplete; the lifecycle action was stopped.'
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function durableLifecycleIdentity(Service $service): ?array
    {
        $fulfillment = app(DurableFulfillmentService::class);
        if (!$fulfillment->isReservationBacked($service)) {
            return null;
        }

        $reservationServiceClass =
            'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
        $runtimeEnabled = Extension::query()
            ->where('extension', 'DynamicPterodactyl')
            ->where('enabled', true)
            ->exists();
        if (
            !$runtimeEnabled
            || !class_exists($reservationServiceClass)
            || !method_exists(
                $reservationServiceClass,
                'serverLifecycleIdentity'
            )
        ) {
            throw new PermanentProvisioningException(
                'The durable fulfillment runtime is unavailable; the lifecycle action was stopped.'
            );
        }

        $identity = app($reservationServiceClass)
            ->serverLifecycleIdentity($service);
        if (!is_array($identity)) {
            throw new PermanentProvisioningException(
                'The reservation-backed service has no durable server identity; the lifecycle action was stopped.'
            );
        }

        return $identity;
    }

    /**
     * Distinguish the one legitimate unpinned state from a corrupted partial
     * identity. A paid create that timed out may have no external fields at
     * all; any partial set must stop rather than broadening reconciliation.
     *
     * @param  array<string, mixed>  $identity
     */
    private function lifecycleIdentityIsUnpinned(array $identity): bool
    {
        $values = [
            $identity['external_server_id'] ?? null,
            $identity['external_user_id'] ?? null,
            $identity['external_server_uuid'] ?? null,
            $identity['external_server_identifier'] ?? null,
        ];
        $present = collect($values)
            ->filter(fn ($value): bool => $value !== null)
            ->count();
        if ($present === 0) {
            return true;
        }
        if ($present !== count($values)) {
            throw new PermanentProvisioningException(
                'The durable Pterodactyl server identity is partial; cancellation reconciliation was stopped.'
            );
        }

        return false;
    }

    /**
     * Reconcile only the immutable Paymenter service external ID, then prove
     * the complete signed checkout contract before pinning a numeric target.
     * This method performs no customer creation or adoption.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|false
     */
    private function reconcileUnpinnedCancellationServer(
        Service $service,
        array $identity
    ): array|false {
        $reservationServiceClass =
            'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
        $reservationService = app($reservationServiceClass);
        if (
            !method_exists(
                $reservationService,
                'cancellationReconciliationContext'
            )
            || !method_exists(
                $reservationService,
                'pinCancellationServerIdentity'
            )
        ) {
            throw new PermanentProvisioningException(
                'The durable cancellation reconciliation runtime is unavailable.'
            );
        }

        $context = $reservationService
            ->cancellationReconciliationContext($service);
        if (!is_array($context)) {
            throw new PermanentProvisioningException(
                'The reservation-backed cancellation has no signed checkout context.'
            );
        }
        $this->assertCancellationReconciliationContext(
            $service,
            $identity,
            $context
        );
        if ($context['provisioning_in_flight']) {
            throw new Exception(
                'Provisioning is still in flight; cancellation will retry.'
            );
        }

        $server = $this->getServer(
            $service->id,
            failIfNotFound: false,
            raw: true
        );
        if ($server === false) {
            return false;
        }

        $users = $this->matchingPterodactylUsers(
            ['external_id' => $context['user_external_id']],
            'external_id',
            $context['user_external_id']
        );
        if (count($users) !== 1) {
            throw new PermanentProvisioningException(
                'The cancellation candidate customer cannot be proven from its immutable external ID.'
            );
        }
        $externalUserId = $this->pterodactylUserId($users[0]);
        $this->assertServerMatchesReservation(
            $server,
            $context,
            (int) $service->id,
            $externalUserId,
            (int) $context['egg_id'],
            (int) $context['nest_id']
        );

        $pinnedIdentity = $reservationService
            ->pinCancellationServerIdentity(
                $service,
                $server,
                $externalUserId
            );
        if (!is_array($pinnedIdentity)) {
            throw new PermanentProvisioningException(
                'The cancellation candidate could not be pinned durably.'
            );
        }
        $this->assertDurableLifecycleServer(
            $service,
            $server,
            $pinnedIdentity
        );

        return $server;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $context
     */
    private function assertCancellationReconciliationContext(
        Service $service,
        array $identity,
        array $context
    ): void {
        $expectedUserExternalId =
            "paymenter-user-{$service->user_id}";
        $fingerprint = $context['configuration_fingerprint'] ?? null;
        if (
            !is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || ($context['status'] ?? null) !== 'paid_committed'
            || !is_bool($context['provisioning_in_flight'] ?? null)
            || !is_string($context['panel_identity'] ?? null)
            || !hash_equals(
                $this->panelIdentity(),
                $context['panel_identity']
            )
            || !is_string(
                $context['external_server_external_id'] ?? null
            )
            || !hash_equals(
                (string) $service->id,
                $context['external_server_external_id']
            )
            || !is_string($context['user_external_id'] ?? null)
            || !hash_equals(
                $expectedUserExternalId,
                $context['user_external_id']
            )
            || !is_numeric($context['reservation_id'] ?? null)
            || (int) $context['reservation_id']
                !== (int) ($identity['reservation_id'] ?? 0)
            || !is_string($identity['panel_identity'] ?? null)
            || !hash_equals(
                $context['panel_identity'],
                $identity['panel_identity']
            )
            || !is_string($identity['user_external_id'] ?? null)
            || !hash_equals(
                $context['user_external_id'],
                $identity['user_external_id']
            )
        ) {
            throw new PermanentProvisioningException(
                'The signed cancellation reconciliation context is invalid.'
            );
        }

        foreach (['node_id', 'nest_id', 'egg_id'] as $field) {
            if (
                !is_numeric($context[$field] ?? null)
                || (int) $context[$field] <= 0
                || !is_numeric($identity[$field] ?? null)
                || (int) $context[$field]
                    !== (int) $identity[$field]
            ) {
                throw new PermanentProvisioningException(
                    "The signed cancellation context has an invalid {$field}."
                );
            }
        }
        foreach (['memory', 'cpu', 'disk'] as $resource) {
            if (
                !is_numeric($context[$resource] ?? null)
                || (int) $context[$resource] <= 0
            ) {
                throw new PermanentProvisioningException(
                    "The signed cancellation context has an invalid {$resource}."
                );
            }
        }
        if (
            !is_numeric($context['client_allocation_limit'] ?? null)
            || (int) $context['client_allocation_limit'] !== 0
            || !is_array($context['allocations'] ?? null)
            || $context['allocations'] === []
        ) {
            throw new PermanentProvisioningException(
                'The signed cancellation context has an invalid allocation contract.'
            );
        }

        $allocationIds = [];
        $primaryCount = 0;
        foreach ($context['allocations'] as $allocation) {
            $allocationId = is_array($allocation)
                ? StrictInteger::parse(
                    $allocation['allocation_id'] ?? null
                )
                : null;
            if (
                $allocationId === null
                || $allocationId <= 0
                || !is_bool($allocation['is_primary'] ?? null)
            ) {
                throw new PermanentProvisioningException(
                    'The signed cancellation context contains an invalid allocation.'
                );
            }
            $allocationIds[] = $allocationId;
            $primaryCount += $allocation['is_primary'] ? 1 : 0;
        }
        if (
            count(array_unique($allocationIds)) !== count($allocationIds)
            || $primaryCount !== 1
        ) {
            throw new PermanentProvisioningException(
                'The signed cancellation context does not contain one exact primary allocation.'
            );
        }
    }

    /**
     * Resolve a lifecycle target by its immutable numeric server ID. Static
     * services retain the legacy external-ID lookup.
     *
     * @return array<string, mixed>|false
     */
    private function getLifecycleServer(
        Service $service,
        bool $failIfNotFound = true
    ): array|false {
        $identity = $this->durableLifecycleIdentity($service);
        if ($identity === null) {
            return $this->getServer(
                $service->id,
                $failIfNotFound,
                true
            );
        }

        $this->assertDurableLifecycleIdentity($service, $identity);
        $server = $this->getServerById(
            (int) $identity['external_server_id'],
            $failIfNotFound
        );
        if ($server !== false) {
            $this->assertDurableLifecycleServer(
                $service,
                $server,
                $identity
            );
        }

        return $server;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function getServerById(
        int $serverId,
        bool $failIfNotFound = true
    ): array|false {
        try {
            return $this->request(
                '/api/application/servers/' . $serverId,
                'get',
                ['include' => 'allocations']
            );
        } catch (Exception $exception) {
            $notFound = $exception->getCode() === 404
                || $exception->getMessage() === 'Server not found';
            if (!$notFound) {
                throw $exception;
            }
            if ($failIfNotFound) {
                throw new Exception(
                    'Server not found',
                    404,
                    $exception
                );
            }

            return false;
        }
    }

    /**
     * Resolve the durable Paymenter customer identity before provisioning or
     * reconciliation. New dynamic orders use the Paymenter user ID as the
     * Pterodactyl external ID; an unmapped legacy user may be adopted only
     * when its exact email address matches and it has no competing external ID.
     */
    private function resolvePterodactylUser(
        Service $service,
        ?string $expectedExternalId = null
    ): int {
        $orderUser = $service->user;
        if (!$orderUser) {
            throw new PermanentProvisioningException(
                'The service has no Paymenter customer for Pterodactyl provisioning.'
            );
        }

        $expectedExternalId = $expectedExternalId !== null
            ? trim($expectedExternalId)
            : null;
        if ($expectedExternalId === '') {
            throw new PermanentProvisioningException(
                'The reservation has no Pterodactyl customer identity.'
            );
        }

        if ($expectedExternalId !== null) {
            $byExternalId = $this->pterodactylUserMatches(
                ['external_id' => $expectedExternalId],
                'external_id',
                $expectedExternalId
            );
            if (count($byExternalId) > 1) {
                throw new PermanentProvisioningException(
                    'Pterodactyl returned multiple customers for the immutable Paymenter external ID.'
                );
            }
            if (count($byExternalId) === 1) {
                $attributes = $byExternalId[0];
                $userId = $this->pterodactylUserId($attributes);
                if (
                    $this->normalizeEmail(
                        (string) ($attributes['email'] ?? '')
                    )
                    !== $this->normalizeEmail((string) $orderUser->email)
                ) {
                    $emailMatches = $this->matchingPterodactylUsers(
                        ['email' => (string) $orderUser->email],
                        'email',
                        (string) $orderUser->email,
                        caseInsensitive: true
                    );
                    if (
                        count($emailMatches) === 1
                        && $this->pterodactylUserId($emailMatches[0])
                            !== $userId
                    ) {
                        throw new PermanentProvisioningException(
                            'The updated Paymenter email is already assigned to a different Pterodactyl customer.'
                        );
                    }

                    $updated = (array) (
                        $this->request(
                            "/api/application/users/{$userId}",
                            'patch',
                            [
                                'external_id' => $expectedExternalId,
                                'email' => (string) $orderUser->email,
                                'username' => (string) (
                                    $attributes['username']
                                        ?? $this->pterodactylUsername(
                                            (string) $orderUser->name
                                        )
                                ),
                                'first_name' => (string) (
                                    $attributes['first_name']
                                        ?? $orderUser->first_name
                                        ?? ''
                                ),
                                'last_name' => (string) (
                                    $attributes['last_name']
                                        ?? $orderUser->last_name
                                        ?? ''
                                ),
                            ]
                        )['attributes'] ?? []
                    );
                    if (
                        $this->pterodactylUserId($updated) !== $userId
                        || !hash_equals(
                            $expectedExternalId,
                            (string) ($updated['external_id'] ?? '')
                        )
                        || $this->normalizeEmail(
                            (string) ($updated['email'] ?? '')
                        ) !== $this->normalizeEmail(
                            (string) $orderUser->email
                        )
                    ) {
                        throw new PermanentProvisioningException(
                            'Pterodactyl did not persist the updated Paymenter customer identity.'
                        );
                    }
                }

                return $userId;
            }
        }

        $byEmail = $this->matchingPterodactylUsers(
            ['email' => (string) $orderUser->email],
            'email',
            (string) $orderUser->email,
            caseInsensitive: true
        );
        if (count($byEmail) === 1) {
            $attributes = $byEmail[0];
            $userId = $this->pterodactylUserId($attributes);
            if ($expectedExternalId === null) {
                return $userId;
            }

            $actualExternalId = trim((string) ($attributes['external_id'] ?? ''));
            if (
                $actualExternalId !== ''
                && !hash_equals($expectedExternalId, $actualExternalId)
            ) {
                throw new PermanentProvisioningException(
                    'The matching Pterodactyl email belongs to a different external customer identity.'
                );
            }
            if ($actualExternalId === '') {
                $updated = $this->request(
                    "/api/application/users/{$userId}",
                    'patch',
                    [
                        'external_id' => $expectedExternalId,
                        'email' => (string) ($attributes['email'] ?? $orderUser->email),
                        'username' => (string) (
                            $attributes['username']
                                ?? $this->pterodactylUsername((string) $orderUser->name)
                        ),
                        'first_name' => (string) (
                            $attributes['first_name'] ?? $orderUser->first_name ?? ''
                        ),
                        'last_name' => (string) (
                            $attributes['last_name'] ?? $orderUser->last_name ?? ''
                        ),
                    ]
                );
                $updatedAttributes = $updated['attributes'] ?? [];
                if (
                    $this->pterodactylUserId((array) $updatedAttributes) !== $userId
                    || !hash_equals(
                        $expectedExternalId,
                        (string) ($updatedAttributes['external_id'] ?? '')
                    )
                ) {
                    throw new PermanentProvisioningException(
                        'Pterodactyl did not persist the Paymenter customer identity.'
                    );
                }
            }

            return $userId;
        }

        $create = [
            'email' => (string) $orderUser->email,
            'username' => $this->pterodactylUsername((string) $orderUser->name),
            'first_name' => (string) ($orderUser->first_name ?? ''),
            'last_name' => (string) ($orderUser->last_name ?? ''),
        ];
        if ($expectedExternalId !== null) {
            $create['external_id'] = $expectedExternalId;
        }
        try {
            $createdAttributes = (array) (
                $this->request(
                    '/api/application/users',
                    'post',
                    $create
                )['attributes'] ?? []
            );
        } catch (PermanentProvisioningException $exception) {
            if (
                $exception->getCode() !== 422
                || $expectedExternalId === null
            ) {
                throw $exception;
            }

            $createdAttributes =
                $this->reconcileConcurrentPterodactylUserCreate(
                    $expectedExternalId,
                    (string) $orderUser->email,
                    $exception
                );
        }
        $userId = $this->pterodactylUserId($createdAttributes);
        if (
            (
                $expectedExternalId !== null
                && !hash_equals(
                    $expectedExternalId,
                    (string) (
                        $createdAttributes['external_id'] ?? ''
                    )
                )
            )
            || $this->normalizeEmail(
                (string) ($createdAttributes['email'] ?? '')
            ) !== $this->normalizeEmail((string) $orderUser->email)
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl did not create the expected customer identity.'
            );
        }

        return $userId;
    }

    /**
     * A 422 from user creation is recoverable only when a concurrent worker
     * created the exact same immutable customer. Re-read by external ID and
     * independently by email; never retry the POST or adopt a merely similar
     * account.
     *
     * @return array<string, mixed>
     */
    private function reconcileConcurrentPterodactylUserCreate(
        string $expectedExternalId,
        string $expectedEmail,
        PermanentProvisioningException $createFailure
    ): array {
        $byExternalId = $this->matchingPterodactylUsers(
            ['external_id' => $expectedExternalId],
            'external_id',
            $expectedExternalId
        );
        if (count($byExternalId) !== 1) {
            throw new PermanentProvisioningException(
                'Pterodactyl rejected customer creation, and the exact external identity could not be proven.',
                previous: $createFailure
            );
        }

        $attributes = $byExternalId[0];
        $userId = $this->pterodactylUserId($attributes);
        if (
            $this->normalizeEmail(
                (string) ($attributes['email'] ?? '')
            ) !== $this->normalizeEmail($expectedEmail)
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl customer creation conflicted with a different email identity.',
                previous: $createFailure
            );
        }

        $byEmail = $this->matchingPterodactylUsers(
            ['email' => $expectedEmail],
            'email',
            $expectedEmail,
            caseInsensitive: true
        );
        if (
            count($byEmail) !== 1
            || $this->pterodactylUserId($byEmail[0]) !== $userId
            || !hash_equals(
                $expectedExternalId,
                (string) ($byEmail[0]['external_id'] ?? '')
            )
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl customer creation conflict did not resolve to one exact external ID and email.',
                previous: $createFailure
            );
        }

        return $attributes;
    }

    /**
     * Resource-only upgrades never create, adopt, or relink customers. The
     * durable Paymenter external ID must already resolve to exactly one user.
     */
    private function resolvePterodactylUpgradeUser(
        string $expectedExternalId
    ): int {
        $expectedExternalId = trim($expectedExternalId);
        if ($expectedExternalId === '') {
            throw new PermanentProvisioningException(
                'The upgrade reservation has no Pterodactyl customer identity.'
            );
        }

        $matches = $this->matchingPterodactylUsers(
            ['external_id' => $expectedExternalId],
            'external_id',
            $expectedExternalId
        );
        if (count($matches) !== 1) {
            throw new PermanentProvisioningException(
                'The upgrade reservation Pterodactyl customer identity no longer exists.'
            );
        }

        return $this->pterodactylUserId($matches[0]);
    }

    /**
     * @param  array<string, string>  $filter
     * @return list<array<string, mixed>>
     */
    private function matchingPterodactylUsers(
        array $filter,
        string $field,
        string $expected,
        bool $caseInsensitive = false
    ): array {
        $matches = $this->pterodactylUserMatches(
            $filter,
            $field,
            $expected,
            $caseInsensitive
        );
        if (count($matches) > 1) {
            throw new PermanentProvisioningException(
                "Pterodactyl returned multiple customers for the same {$field}."
            );
        }

        return $matches;
    }

    /**
     * @param  array<string, string>  $filter
     * @return list<array<string, mixed>>
     */
    private function pterodactylUserMatches(
        array $filter,
        string $field,
        string $expected,
        bool $caseInsensitive = false
    ): array {
        $matches = [];
        $recordsSeen = 0;
        $expectedPagination = null;
        $userIdsSeen = [];

        // Never follow a server-supplied URL. Walk the official collection
        // pages ourselves so every request retains the original identity
        // filter, then locally prove the exact field value on every result.
        for ($page = 1; $page <= self::USER_LOOKUP_MAX_PAGES; $page++) {
            $response = $this->request(
                '/api/application/users',
                'get',
                [
                    'filter' => $filter,
                    'page' => $page,
                ]
            );
            $users = $response['data'] ?? null;
            $meta = $response['meta'] ?? null;
            $pagination = is_array($meta)
                ? ($meta['pagination'] ?? null)
                : null;
            if (
                !is_array($users)
                || !array_is_list($users)
                || !is_array($pagination)
            ) {
                throw new PermanentProvisioningException(
                    'Pterodactyl returned an invalid customer lookup response.'
                );
            }

            $validated = $this->validatePterodactylUserPagination(
                $pagination,
                count($users),
                $page
            );
            $stablePagination = [
                'total' => $validated['total'],
                'per_page' => $validated['per_page'],
                'total_pages' => $validated['total_pages'],
            ];
            if ($expectedPagination === null) {
                $expectedPagination = $stablePagination;
            } elseif ($stablePagination !== $expectedPagination) {
                throw new PermanentProvisioningException(
                    'Pterodactyl customer lookup pagination changed during traversal.'
                );
            }

            $recordsSeen += $validated['count'];
            if (
                $recordsSeen > $validated['total']
                || $recordsSeen > self::USER_LOOKUP_MAX_RECORDS
            ) {
                throw new PermanentProvisioningException(
                    'Pterodactyl returned invalid customer lookup pagination metadata.'
                );
            }

            foreach ($users as $user) {
                if (
                    !is_array($user)
                    || !is_array($user['attributes'] ?? null)
                    || !array_key_exists($field, $user['attributes'])
                    || !is_string($user['attributes'][$field])
                ) {
                    throw new PermanentProvisioningException(
                        'Pterodactyl returned an invalid customer lookup response.'
                    );
                }
                $attributes = $user['attributes'];
                $userId = $this->pterodactylUserId($attributes);
                if (isset($userIdsSeen[$userId])) {
                    throw new PermanentProvisioningException(
                        'Pterodactyl returned a duplicate customer identity across lookup pages.'
                    );
                }
                $userIdsSeen[$userId] = true;
                $actual = (string) ($attributes[$field] ?? '');

                $matchesExpected = $caseInsensitive
                    ? strcasecmp($actual, $expected) === 0
                    : hash_equals($expected, $actual);
                if ($matchesExpected) {
                    $matches[] = $attributes;
                }
            }

            if ($page === $validated['total_pages']) {
                if ($recordsSeen !== $validated['total']) {
                    throw new PermanentProvisioningException(
                        'Pterodactyl returned truncated customer lookup results.'
                    );
                }

                return $matches;
            }
        }

        throw new PermanentProvisioningException(
            'Pterodactyl customer lookup exceeded its pagination limit.'
        );
    }

    /**
     * @param  array<string, mixed>  $pagination
     * @return array{
     *     total: int,
     *     count: int,
     *     per_page: int,
     *     current_page: int,
     *     total_pages: int
     * }
     */
    private function validatePterodactylUserPagination(
        array $pagination,
        int $actualCount,
        int $requestedPage
    ): array {
        $total = $pagination['total'] ?? null;
        $count = $pagination['count'] ?? null;
        $perPage = $pagination['per_page'] ?? null;
        $currentPage = $pagination['current_page'] ?? null;
        $totalPages = $pagination['total_pages'] ?? null;
        $links = $pagination['links'] ?? null;
        if (
            !is_int($total)
            || $total < 0
            || $total > self::USER_LOOKUP_MAX_RECORDS
            || !is_int($count)
            || $count < 0
            || !is_int($perPage)
            || $perPage < 1
            || $perPage > self::USER_LOOKUP_MAX_RECORDS
            || !is_int($currentPage)
            || $currentPage !== $requestedPage
            || !is_int($totalPages)
            || $totalPages < 1
            || $totalPages > self::USER_LOOKUP_MAX_PAGES
            || $currentPage > $totalPages
            || !is_array($links)
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned invalid customer lookup pagination metadata.'
            );
        }

        $calculatedPages = $total === 0
            ? 1
            : intdiv($total - 1, $perPage) + 1;
        $offset = ($currentPage - 1) * $perPage;
        $expectedCount = min($perPage, max(0, $total - $offset));
        if (
            $totalPages !== $calculatedPages
            || $count !== $actualCount
            || $count !== $expectedCount
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned invalid customer lookup pagination metadata.'
            );
        }

        $this->validatePterodactylUserPaginationLinks(
            $links,
            $currentPage,
            $totalPages
        );

        return [
            'total' => $total,
            'count' => $count,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param  array<string, mixed>  $links
     */
    private function validatePterodactylUserPaginationLinks(
        array $links,
        int $currentPage,
        int $totalPages
    ): void {
        $previous = $links['previous'] ?? null;
        $next = $links['next'] ?? null;
        if (
            (
                $currentPage === 1
                && $previous !== null
            )
            || (
                $currentPage > 1
                && (
                    !is_string($previous)
                    || $this->pterodactylPaginationLinkPage($previous)
                        !== $currentPage - 1
                )
            )
            || (
                $currentPage === $totalPages
                && $next !== null
            )
            || (
                $currentPage < $totalPages
                && (
                    !is_string($next)
                    || $this->pterodactylPaginationLinkPage($next)
                        !== $currentPage + 1
                )
            )
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned non-monotonic customer lookup pagination metadata.'
            );
        }
    }

    private function pterodactylPaginationLinkPage(string $link): ?int
    {
        $query = parse_url($link, PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $parameters);

        return StrictInteger::parse($parameters['page'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pterodactylUserId(array $attributes): int
    {
        $id = StrictInteger::parse($attributes['id'] ?? null);
        if ($id === null || $id <= 0) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid customer identity.'
            );
        }

        return $id;
    }

    private function pterodactylUsername(string $name): string
    {
        $base = preg_replace(
            '/[^a-zA-Z0-9]/',
            '',
            strtolower(Str::transliterate($name))
        );
        $base = is_string($base) && $base !== '' ? $base : Str::random(8);

        return $base . '_' . Str::random(4);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function deleteReconciledServer(array $server): void
    {
        $serverId = $server['attributes']['id'] ?? $server['id'] ?? null;
        if (!is_numeric($serverId) || (int) $serverId <= 0) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid server identity during cancellation reconciliation.'
            );
        }

        $serverId = (int) $serverId;
        $this->request('/api/application/servers/' . $serverId, 'delete');
        if ($this->getServerById($serverId, failIfNotFound: false)) {
            throw new Exception(
                'Pterodactyl still reports the pinned server after the delete request.'
            );
        }
    }

    private function generateDeploymentData($settings, $environment)
    {
        if (!isset($settings['port_array']) || $settings['port_array'] === '') {
            if ($settings['node']) {
                // Only get one allocation from the node
                $nodes = $this->request('/api/application/nodes/deployable', 'get', [
                    'memory' => $settings['memory'],
                    'disk' => $settings['disk'],
                    'location_ids' => $settings['location_ids'] ?? [],
                    'include' => ['allocations'],
                ]);
                $nodes = collect($nodes['data']);
                $nodes_by_id = $nodes->mapWithKeys(fn ($node) => [$node['attributes']['id'] => $node['attributes']]);

                if (!$nodes_by_id->has($settings['node'])) {
                    throw new Exception('Node is not suitable for deployment.');
                }
                $node = $nodes_by_id->get($settings['node']);
                $availablePorts = collect($node['relationships']['allocations']['data']);
                $availablePorts = $availablePorts
                    ->filter(fn ($port) => !$port['attributes']['assigned'])
                    ->map(
                        fn ($port) => [
                            'port' => $port['attributes']['port'],
                            'id' => $port['attributes']['id'],
                        ]
                    );
                if ($availablePorts->isEmpty()) {
                    throw new Exception('No available allocations found on the selected node.');
                }
                $allocation = $availablePorts->first();
                $environment['SERVER_PORT'] = $allocation['port'];

                // Return the allocation id for the SERVER_PORT
                return [
                    'auto_deploy' => false,
                    'environment' => $environment,
                    'allocations_needed' => 1,
                    'allocation' => [
                        'default' => $allocation['id'],
                        'additional' => [],
                    ],
                ];
            }

            return [
                'auto_deploy' => true,
                'environment' => $environment,
                'allocations_needed' => 1,
            ];
        }

        try {
            // Example: {"SERVER_PORT": 7777, "NONE": [7778, 7779], "QUERY_PORT": 2701, "RCON_PORT": 27020}
            $port_array = json_decode($settings['port_array'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }
        } catch (Exception $e) {
            throw new Exception('Invalid JSON in port array');
        }

        if (!is_array($port_array)) {
            throw new Exception('Port array must be an array');
        }

        $nodes = $this->request('/api/application/nodes/deployable', 'get', [
            'memory' => $settings['memory'],
            'disk' => $settings['disk'],
            'location_ids' => $settings['location_ids'] ?? [],
            'include' => ['allocations'],
        ]);
        $nodes = collect($nodes['data']);
        $nodes_by_id = $nodes->mapWithKeys(fn ($node) => [$node['attributes']['id'] => $node['attributes']]);

        if ($settings['node']) {
            // If the product's node id is not in the deployable nodes array, throw error.
            if (!$nodes_by_id->has($settings['node'])) {
                throw new Exception('Node is not suitable for deployment.');
            }

            $node = $nodes_by_id->get($settings['node']);
            $availablePorts = collect($node['relationships']['allocations']['data']);
            $availablePorts = $availablePorts
                ->filter(fn ($port) => !$port['attributes']['assigned'])
                ->map(
                    fn ($port) => [
                        'port' => $port['attributes']['port'],
                        'id' => $port['attributes']['id'],
                    ]
                );

            $free_allocations_needed = 0;
            foreach ($port_array as $key => $value) {
                $free_allocations_needed += is_array($value) ? count($value) : 1;
            }

            if (count($availablePorts) < $free_allocations_needed) {
                throw new Exception("Not enough allocations found for deployment. Found: {$availablePorts->count()}, Required: {$free_allocations_needed}");
            }
        } else {
            foreach ($nodes as $index => $node) {
                $availablePorts = collect($node['attributes']['relationships']['allocations']['data']);
                $availablePorts = $availablePorts
                    ->filter(fn ($port) => !$port['attributes']['assigned'])
                    ->map(
                        fn ($port) => [
                            'port' => $port['attributes']['port'],
                            'id' => $port['attributes']['id'],
                        ]
                    );

                $free_allocations_needed = 0;
                foreach ($port_array as $key => $value) {
                    $free_allocations_needed += is_array($value) ? count($value) : 1;
                }

                if (count($availablePorts) < $free_allocations_needed) {
                    // If this was last viable node, throw error
                    if ($index == $nodes->count() - 1) {
                        throw new Exception('No nodes with suitable allocations found for deployment');
                    }

                    // Else move onto next viable node
                    continue;
                }
                break;
            }
        }

        $allocations = [];
        foreach ($port_array as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $port) {
                    $allocation = $availablePorts->where('port', $port)->first();
                    if (!$allocation) {
                        // try to assign a higher port, if that fails try a random port
                        $allocation = $availablePorts->where('port', '>', $port)->first();
                        if (!$allocation) {
                            $allocation = $availablePorts->random();
                        }
                        if (!$allocation) {
                            throw new Exception('Could not find a port to assign');
                        }
                    }
                    $allocations[$key][] = $allocation;

                    // Remove the port from the available ports
                    $availablePorts = $availablePorts->reject(function ($port) use ($allocation) {
                        return $port['id'] == $allocation['id'];
                    });
                }
            } else {
                $allocation = $availablePorts->where('port', $value)->first();
                if (!$allocation) {
                    // try to assign a higher port, if that fails try a random port
                    $allocation = $availablePorts->where('port', '>', $value)->first();
                    if (!$allocation) {
                        $allocation = $availablePorts->random();
                    }
                    if (!$allocation) {
                        throw new Exception('Could not find a port to assign');
                    }
                }
                $allocations[$key] = $allocation;

                // Remove the port from the available ports
                $availablePorts = $availablePorts->reject(function ($port) use ($allocation) {
                    return $port['id'] == $allocation['id'];
                });
            }
        }

        $allocationIds = [];

        foreach ($allocations as $key => $value) {
            // Assign the allocations to the environment
            if ($key !== 'NONE') {
                if (isset($environment[$key])) {
                    $environment[$key] = $value['port'];
                }
            }

            // Set allocations to a array with only the ids
            if ($key !== 'SERVER_PORT') {
                if (is_array($value) && isset($value[0])) {
                    foreach ($value as $v) {
                        $allocationIds[] = $v['id'];
                    }
                } else {
                    $allocationIds[] = $value['id'];
                }
            }
        }

        return [
            'auto_deploy' => false,
            'allocations_needed' => $free_allocations_needed,
            'environment' => $environment,
            'allocation' => [
                'default' => $allocations['SERVER_PORT']['id'],
                'additional' => $allocationIds,
            ],
        ];
    }

    private function getServer($id, $failIfNotFound = true, $raw = false)
    {
        try {
            $response = $this->request(
                '/api/application/servers/external/' . $id,
                'get',
                ['include' => 'allocations']
            );
        } catch (Exception $e) {
            $notFound = $e->getCode() === 404 || $e->getMessage() === 'Server not found';
            if (!$notFound) {
                throw $e;
            }
            if ($failIfNotFound) {
                throw new Exception('Server not found', 404, $e);
            }

            return false;
        }
        if ($raw) {
            return $response;
        }

        return $response['attributes']['id'] ?? false;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $server = $this->getLifecycleServer($service);
        $serverId = $server['attributes']['id'] ?? null;

        $this->request(
            '/api/application/servers/' . (int) $serverId . '/suspend',
            'post'
        );

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $server = $this->getLifecycleServer($service);
        $serverId = $server['attributes']['id'] ?? null;

        $this->request(
            '/api/application/servers/' . (int) $serverId . '/unsuspend',
            'post'
        );

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $identity = $this->durableLifecycleIdentity($service);
        if ($identity === null) {
            $server = $this->getServer(
                $service->id,
                failIfNotFound: false,
                raw: true
            );
        } elseif ($this->lifecycleIdentityIsUnpinned($identity)) {
            $server = $this->reconcileUnpinnedCancellationServer(
                $service,
                $identity
            );
        } else {
            $this->assertDurableLifecycleIdentity($service, $identity);
            $server = $this->getServerById(
                (int) $identity['external_server_id'],
                false
            );
            if ($server !== false) {
                $this->assertDurableLifecycleServer(
                    $service,
                    $server,
                    $identity
                );
            }
        }
        if (!$server) {
            return true;
        }

        $serverId = $server['attributes']['id'] ?? null;
        if (!is_numeric($serverId) || (int) $serverId <= 0) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid server identity during cancellation.'
            );
        }

        $this->request('/api/application/servers/' . (int) $serverId, 'delete');
        $stillPresent = $identity === null
            ? $this->getServer(
                $service->id,
                failIfNotFound: false,
                raw: true
            )
            : $this->getServerById((int) $serverId, false);
        if ($stillPresent) {
            throw new Exception(
                'Pterodactyl still reports the pinned server after the delete request.'
            );
        }

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $upgradeContract = $properties['_dynamic_upgrade'] ?? null;
        unset($properties['_dynamic_upgrade']);
        $reservationServiceClass = 'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\Services\\ReservationService';
        $hasDurableReservation = app(DurableFulfillmentService::class)
            ->isReservationBacked($service);
        if (
            !is_array($upgradeContract)
            && (
                $hasDurableReservation
                || (
                    class_exists($reservationServiceClass)
                    && method_exists(
                        app($reservationServiceClass),
                        'hasCheckoutReservation'
                    )
                    && app($reservationServiceClass)
                        ->hasCheckoutReservation((int) $service->id)
                )
            )
        ) {
            throw new PermanentProvisioningException(
                'Reservation-backed services can only be upgraded through the capacity-aware upgrade coordinator.'
            );
        }

        if (is_array($upgradeContract)) {
            $expectedUserExternalId = "paymenter-user-{$service->user_id}";
            if (
                !is_string($upgradeContract['user_external_id'] ?? null)
                || !hash_equals(
                    $expectedUserExternalId,
                    $upgradeContract['user_external_id']
                )
                || !is_string(
                    $upgradeContract['external_server_external_id'] ?? null
                )
                || !hash_equals(
                    (string) $service->id,
                    $upgradeContract['external_server_external_id']
                )
            ) {
                throw new PermanentProvisioningException(
                    'The upgrade reservation has an invalid customer identity.'
                );
            }
            $resolvedUserId = $this->resolvePterodactylUpgradeUser(
                $expectedUserExternalId
            );
            if (
                !is_numeric($upgradeContract['external_user_id'] ?? null)
                || (int) $upgradeContract['external_user_id']
                    !== $resolvedUserId
            ) {
                throw new PermanentProvisioningException(
                    'The Pterodactyl server owner changed after the upgrade was reserved.'
                );
            }
        }

        $server = $this->getServer($service->id, raw: true);
        if (!is_array($upgradeContract)) {
            $this->assertStaticUpgradeAvoidsManagedNode($server);
        }
        $settings = array_merge($settings, $properties);
        $target = [
            'memory' => (int) ($settings['memory'] ?? -1),
            'cpu' => (int) ($settings['cpu'] ?? -1),
            'disk' => (int) ($settings['disk'] ?? -1),
        ];

        if (is_array($upgradeContract)) {
            $this->assertDynamicUpgradeContract(
                $server,
                $upgradeContract,
                $target
            );
        }

        $preservedBuild = is_array($upgradeContract)
            ? $this->dynamicUpgradePreservedBuild(
                $server,
                $upgradeContract
            )
            : null;

        $updateServerData = [
            'allocation' => $server['attributes']['allocation'],
            'memory' => (int) $settings['memory'],
            'swap' => $preservedBuild['swap'] ?? (int) $settings['swap'],
            'disk' => (int) $settings['disk'],
            'io' => $preservedBuild['io'] ?? (int) $settings['io'],
            'cpu' => (int) $settings['cpu'],
            'threads' => is_array($upgradeContract)
                ? $preservedBuild['threads']
                : ($settings['cpu_pinning'] ?? null),
            'feature_limits' => [
                'databases' => $preservedBuild['databases']
                    ?? $settings['databases'],
                'allocations' => is_array($upgradeContract)
                    ? 0
                    : $settings['additional_allocations'],
                'backups' => $preservedBuild['backups']
                    ?? $settings['backups'],
            ],
        ];

        if (
            !is_array($upgradeContract)
            || !$this->serverMatchesDynamicUpgradeTarget(
                $server,
                $upgradeContract,
                $target,
                $preservedBuild
            )
        ) {
            $this->request('/api/application/servers/' . $server['attributes']['id'] . '/build', 'patch', $updateServerData);

            if (is_array($upgradeContract)) {
                $server = $this->getServer($service->id, raw: true);
                $this->assertDynamicUpgradeTarget(
                    $server,
                    $upgradeContract,
                    $target,
                    $preservedBuild
                );
            }
        }

        // Capacity-aware upgrades are resource-only. Product startup, egg,
        // image, and environment settings are not part of this contract and
        // must never be mutated by a RAM/CPU/disk resize.
        if (is_array($upgradeContract)) {
            return true;
        }

        $eggData = $this->request('/api/application/nests/' . $settings['nest_id'] . '/eggs/' . $settings['egg_id'], data: ['include' => 'variables']);

        if (!isset($eggData['attributes'])) {
            throw new Exception('Could not fetch egg data');
        }

        $environment = [];

        foreach ($eggData['attributes']['relationships']['variables']['data'] as $variable) {
            // Check if variable has been set on server
            if (isset($server['attributes']['container']['environment'][$variable['attributes']['env_variable']])) {
                $environment[$variable['attributes']['env_variable']] = $server['attributes']['container']['environment'][$variable['attributes']['env_variable']];
            } else {
                $environment[$variable['attributes']['env_variable']] = $settings[$variable['attributes']['env_variable']] ?? $variable['attributes']['default_value'];
            }
        }

        $updateServerData = [
            'environment' => $environment,
            'skip_scripts' => $settings['skip_scripts'] ?? false,
            'oom_disabled' => !($settings['oom_killer'] ?? false),
            'egg' => $settings['egg_id'],
            'image' => $server['attributes']['container']['image'] ?? $eggData['attributes']['docker_image'],
            'startup' => $server['attributes']['container']['startup_command'] ?? $settings['startup'] ?? $eggData['attributes']['startup'],
        ];

        $this->request('/api/application/servers/' . $server['attributes']['id'] . '/startup', 'patch', $updateServerData);

        return true;
    }

    private function assertDynamicUpgradeContract(
        array $server,
        array $contract,
        array $target
    ): void {
        $expectedPanel = $this->panelIdentity();
        if (
            !isset($contract['panel_identity'])
            || !hash_equals($expectedPanel, (string) $contract['panel_identity'])
        ) {
            throw new PermanentProvisioningException(
                'The upgrade reservation belongs to a different Pterodactyl panel.'
            );
        }

        $attributes = $server['attributes'] ?? null;
        if (!is_array($attributes)) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned an invalid server during upgrade.'
            );
        }
        if (
            !$this->serverMatchesDynamicUpgradeIdentity(
                $attributes,
                $contract
            )
            || (int) ($attributes['node'] ?? 0) !== (int) ($contract['node_id'] ?? 0)
            || (int) ($attributes['allocation'] ?? 0) !== (int) ($contract['allocation_id'] ?? 0)
        ) {
            throw new PermanentProvisioningException(
                'The Pterodactyl server placement changed after the upgrade was reserved.'
            );
        }
        if (!$this->serverHasExactUpgradeAllocation($server, $contract)) {
            throw new PermanentProvisioningException(
                'The Pterodactyl server allocation set changed after the upgrade was reserved.'
            );
        }

        $reservedTarget = (array) ($contract['target'] ?? []);
        foreach (['memory', 'cpu', 'disk'] as $resource) {
            if (
                !array_key_exists($resource, $reservedTarget)
                || (int) $reservedTarget[$resource] !== $target[$resource]
            ) {
                throw new PermanentProvisioningException(
                    'The requested upgrade does not match its reserved resource target.'
                );
            }
        }

        if (
            !$this->serverMatchesResourceVector(
                $server,
                (array) ($contract['source'] ?? [])
            )
            && !$this->serverMatchesResourceVector($server, $target)
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl resource limits changed after the upgrade was reserved.'
            );
        }
    }

    private function assertDynamicUpgradeTarget(
        array $server,
        array $contract,
        array $target,
        array $preservedBuild
    ): void {
        if (!$this->serverMatchesDynamicUpgradeTarget(
            $server,
            $contract,
            $target,
            $preservedBuild
        )) {
            throw new PermanentProvisioningException(
                'Pterodactyl did not persist the exact reserved upgrade target.'
            );
        }
    }

    private function serverMatchesDynamicUpgradeTarget(
        array $server,
        array $contract,
        array $target,
        array $preservedBuild
    ): bool {
        $attributes = $server['attributes'] ?? null;

        return is_array($attributes)
            && $this->serverMatchesDynamicUpgradeIdentity(
                $attributes,
                $contract
            )
            && (int) ($attributes['node'] ?? 0) === (int) ($contract['node_id'] ?? 0)
            && (int) ($attributes['allocation'] ?? 0) === (int) ($contract['allocation_id'] ?? 0)
            && $this->serverMatchesPreservedBuild(
                $server,
                $preservedBuild
            )
            && $this->serverHasExactUpgradeAllocation($server, $contract)
            && $this->serverMatchesResourceVector($server, $target);
    }

    /**
     * Freeze every build field outside RAM, CPU, and disk. Allocation changes
     * are not supported by the resource-upgrade lifecycle, so the preexisting
     * client allocation limit must already be zero.
     *
     * @return array{
     *     swap: int,
     *     io: int,
     *     threads: string|null,
     *     databases: int,
     *     allocations: int,
     *     backups: int
     * }
     */
    private function dynamicUpgradePreservedBuild(
        array $server,
        array $contract
    ): array {
        $limits = data_get($server, 'attributes.limits');
        $features = data_get($server, 'attributes.feature_limits');
        $threads = is_array($limits) ? ($limits['threads'] ?? null) : null;
        if (
            !is_array($limits)
            || !is_array($features)
            || ($threads !== null && !is_string($threads))
        ) {
            throw new PermanentProvisioningException(
                'Pterodactyl returned incomplete non-resource build settings.'
            );
        }

        $current = $this->normalizePreservedBuild([
            'swap' => StrictInteger::parse($limits['swap'] ?? null),
            'io' => StrictInteger::parse($limits['io'] ?? null),
            'threads' => $threads,
            'databases' => StrictInteger::parse(
                $features['databases'] ?? null
            ),
            'allocations' => StrictInteger::parse(
                $features['allocations'] ?? null
            ),
            'backups' => StrictInteger::parse(
                $features['backups'] ?? null
            ),
        ]);
        $preserved = $this->normalizePreservedBuild(
            $contract['preserved_build'] ?? null
        );
        if ($current !== $preserved) {
            throw new PermanentProvisioningException(
                'Pterodactyl non-resource build limits changed after the upgrade was reserved.'
            );
        }

        return $preserved;
    }

    /**
     * @return array{
     *     swap: int,
     *     io: int,
     *     threads: string|null,
     *     databases: int,
     *     allocations: int,
     *     backups: int
     * }
     */
    private function normalizePreservedBuild(mixed $value): array
    {
        if (
            !is_array($value)
            || !array_key_exists('threads', $value)
        ) {
            throw new PermanentProvisioningException(
                'The upgrade reservation has no complete non-resource build snapshot.'
            );
        }
        $threads = $value['threads'];
        $preserved = [
            'swap' => StrictInteger::parse($value['swap'] ?? null),
            'io' => StrictInteger::parse($value['io'] ?? null),
            'threads' => $threads,
            'databases' => StrictInteger::parse(
                $value['databases'] ?? null
            ),
            'allocations' => StrictInteger::parse(
                $value['allocations'] ?? null
            ),
            'backups' => StrictInteger::parse(
                $value['backups'] ?? null
            ),
        ];
        if (
            ($threads !== null && !is_string($threads))
            ||
            $preserved['swap'] === null
            || $preserved['swap'] < 0
            || $preserved['io'] === null
            || $preserved['io'] < 0
            || $preserved['databases'] === null
            || $preserved['databases'] < 0
            || $preserved['allocations'] !== 0
            || $preserved['backups'] === null
            || $preserved['backups'] < 0
        ) {
            throw new PermanentProvisioningException(
                'Resource-only upgrades require complete remote build limits and a zero client allocation limit.'
            );
        }

        return $preserved;
    }

    private function serverMatchesPreservedBuild(
        array $server,
        array $preserved
    ): bool {
        $limits = data_get($server, 'attributes.limits');
        $features = data_get($server, 'attributes.feature_limits');
        if (!is_array($limits) || !is_array($features)) {
            return false;
        }

        return StrictInteger::parse($limits['swap'] ?? null)
                === $preserved['swap']
            && StrictInteger::parse($limits['io'] ?? null)
                === $preserved['io']
            && ($limits['threads'] ?? null) === $preserved['threads']
            && StrictInteger::parse($features['databases'] ?? null)
                === $preserved['databases']
            && StrictInteger::parse($features['allocations'] ?? null)
                === $preserved['allocations']
            && StrictInteger::parse($features['backups'] ?? null)
                === $preserved['backups'];
    }

    private function serverMatchesDynamicUpgradeIdentity(
        array $attributes,
        array $contract
    ): bool {
        $serverId = (int) ($attributes['id'] ?? 0);
        $expectedServerId = (int) (
            $contract['external_server_id'] ?? 0
        );
        $expectedUserId = (int) ($contract['external_user_id'] ?? 0);
        $expectedNestId = (int) ($contract['nest_id'] ?? 0);
        $expectedEggId = (int) ($contract['egg_id'] ?? 0);

        return $serverId > 0
            && $expectedServerId > 0
            && $serverId === $expectedServerId
            && is_string($attributes['uuid'] ?? null)
            && is_string($contract['external_server_uuid'] ?? null)
            && Str::isUuid($attributes['uuid'])
            && Str::isUuid($contract['external_server_uuid'])
            && hash_equals(
                $contract['external_server_uuid'],
                $attributes['uuid']
            )
            && is_string($attributes['identifier'] ?? null)
            && is_string($contract['external_server_identifier'] ?? null)
            && trim($attributes['identifier']) !== ''
            && trim($contract['external_server_identifier']) !== ''
            && hash_equals(
                $contract['external_server_identifier'],
                $attributes['identifier']
            )
            && is_string($attributes['external_id'] ?? null)
            && is_string(
                $contract['external_server_external_id'] ?? null
            )
            && trim($attributes['external_id']) !== ''
            && trim($contract['external_server_external_id']) !== ''
            && hash_equals(
                $contract['external_server_external_id'],
                $attributes['external_id']
            )
            && $expectedUserId > 0
            && (int) ($attributes['user'] ?? 0) === $expectedUserId
            && $expectedNestId > 0
            && (int) ($attributes['nest'] ?? 0) === $expectedNestId
            && $expectedEggId > 0
            && (int) ($attributes['egg'] ?? 0) === $expectedEggId;
    }

    private function serverHasExactUpgradeAllocation(
        array $server,
        array $contract
    ): bool {
        $expectedAllocation = $contract['allocation_id'] ?? null;
        if (!is_numeric($expectedAllocation) || (int) $expectedAllocation <= 0) {
            return false;
        }
        $expectedAllocationIds = $contract['assigned_allocation_ids'] ?? null;
        if (
            !is_array($expectedAllocationIds)
            || !array_is_list($expectedAllocationIds)
        ) {
            return false;
        }
        $normalizedExpected = [];
        foreach ($expectedAllocationIds as $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                return false;
            }
            $normalizedExpected[] = (int) $id;
        }
        sort($normalizedExpected, SORT_NUMERIC);
        if (
            $normalizedExpected === []
            || count(array_unique($normalizedExpected))
                !== count($normalizedExpected)
            || !in_array(
                (int) $expectedAllocation,
                $normalizedExpected,
                true
            )
        ) {
            return false;
        }

        $relationships = $server['attributes']['relationships']
            ?? $server['relationships']
            ?? null;
        if (
            !is_array($relationships)
            || !isset($relationships['allocations'])
            || !is_array($relationships['allocations'])
            || !array_key_exists('data', $relationships['allocations'])
            || !is_array($relationships['allocations']['data'])
        ) {
            return false;
        }

        $allocationIds = [];
        foreach ($relationships['allocations']['data'] as $allocation) {
            $id = data_get($allocation, 'attributes.id');
            if (!is_numeric($id) || (int) $id <= 0) {
                return false;
            }
            $allocationIds[] = (int) $id;
        }
        sort($allocationIds, SORT_NUMERIC);

        return count(array_unique($allocationIds)) === count($allocationIds)
            && $allocationIds === $normalizedExpected;
    }

    private function serverMatchesResourceVector(
        array $server,
        array $resources
    ): bool {
        $limits = $server['attributes']['limits'] ?? null;
        if (!is_array($limits)) {
            return false;
        }

        foreach (['memory', 'cpu', 'disk'] as $resource) {
            if (
                !array_key_exists($resource, $resources)
                || !is_numeric($resources[$resource])
                || !array_key_exists($resource, $limits)
                || !is_numeric($limits[$resource])
                || (int) $limits[$resource] !== (int) $resources[$resource]
            ) {
                return false;
            }
        }

        return true;
    }

    private function panelIdentity(): string
    {
        try {
            return PanelEndpointIdentity::hash(
                (string) $this->config('host')
            );
        } catch (\InvalidArgumentException $exception) {
            throw new PermanentProvisioningException(
                'The configured Pterodactyl panel URL is invalid.',
                previous: $exception
            );
        }
    }

    public function getActions(Service $service)
    {
        $server = $this->getLifecycleServer($service);

        return [
            [
                'type' => 'button',
                'label' => 'Go to server',
                'url' => $this->config('host') . '/server/' . $server['attributes']['identifier'],
            ],
        ];
    }
}
