<?php

namespace Fluxx\Client;

use HubSpot\Client\Crm as HubspotCRM;
use HubSpot\Client\Crm\Companies\ApiException;
use HubSpot\Client\Crm\Companies\Model\PublicObjectSearchRequest;
use HubSpot\Client\Crm\Contacts\Model\BatchResponseSimplePublicObject;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectId;
use HubSpot\Client\Crm\Deals\Model\BatchResponseSimplePublicObjectWithErrors;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

abstract class AbstractHubspotClient
{
    protected \HubSpot\Discovery\Discovery $client;

    public function __construct(
        #[Autowire('%env(HUBSPOT_TOKEN)%')]
        string $hubspotToken,
        #[Autowire(service: 'limiter.hubspot_global')]
        protected RateLimiterFactoryInterface $hubspotGlobalLimiter,
        #[Autowire(service: 'limiter.hubspot_search_window')]
        protected RateLimiterFactoryInterface $hubspotSearchLimiter,
        #[Autowire(service: 'monolog.logger.hubspot')]
        protected LoggerInterface $hubspotLogger,
    ) {
        $this->client = \HubSpot\Factory::createWithAccessToken($hubspotToken);
    }

    public function fetchData(string $type, array $properties = [], int $after = 0, int $limit = 100): array
    {
        $this->runUntilAvailable();

        return match ($type) {
            'company' => $this->client->crm()->companies()->searchApi()->doSearch(
                new PublicObjectSearchRequest([
                    'limit' => $limit,
                    'after' => $after,
                    'properties' => $properties,
                ])
            )->getResults(),
            default => throw new \RuntimeException(sprintf('fetchData: type "%s" not supported.', $type)),
        };
    }

    /**
     * @param string[] $ids
     *
     * @throws ApiException
     * @throws HubspotCRM\Contacts\ApiException
     * @throws HubspotCRM\Deals\ApiException
     * @throws HubspotCRM\LineItems\ApiException
     */
    public function deleteBatch(string $type, array $ids): void
    {
        $this->runUntilAvailable();

        $log = $this->createLog(
            source: 'HUBSPOT',
            sourceMore: 'DELETE',
            message: sprintf('Archive %d %s(s)', count($ids), $type),
        );

        switch ($type) {
            case 'contact':
                $input = new HubspotCRM\Contacts\Model\BatchInputSimplePublicObjectId();
                $input->setInputs(array_map(
                    static fn (string $id) => new SimplePublicObjectId()->setId($id),
                    $ids,
                ));
                $this->client->crm()->contacts()->batchApi()->archive($input);
                break;
            case 'deal':
                $input = new HubspotCRM\Deals\Model\BatchInputSimplePublicObjectId();
                $input->setInputs(array_map(
                    static fn (string $id) => new HubspotCRM\Deals\Model\SimplePublicObjectId()->setId($id),
                    $ids,
                ));
                $this->client->crm()->deals()->batchApi()->archive($input);
                break;
            case 'line_item':
                $input = new HubspotCRM\LineItems\Model\BatchInputSimplePublicObjectId();
                $input->setInputs(array_map(
                    static fn (string $id) => new HubspotCRM\LineItems\Model\SimplePublicObjectId()->setId($id),
                    $ids,
                ));
                $this->client->crm()->lineItems()->batchApi()->archive($input);
                break;
            default:
                throw new \RuntimeException(sprintf('deleteBatch: type "%s" not supported.', $type));
        }

        $this->outputLog($this->hubspotLogger, $log);
    }

    public function createBatch(string $type, array $data, string $identifier): BatchResponseSimplePublicObjectWithErrors|BatchResponseSimplePublicObject|HubspotCRM\LineItems\Model\Error|HubspotCRM\Deals\Model\BatchResponseSimplePublicObject|HubspotCRM\Contacts\Model\Error|HubspotCRM\Companies\Model\BatchResponseSimplePublicObjectWithErrors|HubspotCRM\Deals\Model\Error|HubspotCRM\LineItems\Model\BatchResponseSimplePublicObjectWithErrors|HubspotCRM\Contacts\Model\BatchResponseSimplePublicObjectWithErrors|HubspotCRM\LineItems\Model\BatchResponseSimplePublicObject|HubspotCRM\Companies\Model\Error|HubspotCRM\Companies\Model\BatchResponseSimplePublicObject|null
    {
        $this->runUntilAvailable();
        $created = null;
        $messages = [];
        foreach ($data as $datum) {
            $messages[] = sprintf(
                'Create %s from batch : %s',
                $type,
                $datum['properties'][$identifier] ?? 'n/a',
            );
        }

        $log = $this->createLog('HUBSPOT', 'POST', $messages);

        switch ($type) {
            case 'contact':
                $p = new HubspotCRM\Contacts\Model\BatchInputSimplePublicObjectBatchInputForCreate();
                $p->setInputs($data);
                $created = $this->client->crm()->contacts()->batchApi()->create($p);
                break;
            case 'company':
                $p = new HubspotCRM\Companies\Model\BatchInputSimplePublicObjectBatchInputForCreate();
                $p->setInputs($data);
                $created = $this->client->crm()->companies()->batchApi()->create($p);
                break;
            case 'deal':
                $p = new HubspotCRM\Deals\Model\BatchInputSimplePublicObjectBatchInputForCreate();
                $p->setInputs($data);
                $created = $this->client->crm()->deals()->batchApi()->create($p);
                break;
            case 'line_item':
                $p = new HubspotCRM\LineItems\Model\BatchInputSimplePublicObjectBatchInputForCreate();
                $p->setInputs($data);
                $created = $this->client->crm()->lineItems()->batchApi()->create($p);
                break;
            case 'product':
                $p = new HubspotCRM\Products\Model\BatchInputSimplePublicObjectBatchInputForCreate();
                $p->setInputs($data);
                $created = $this->client->crm()->products()->batchApi()->create($p);
                break;
            default:
                throw new \RuntimeException(sprintf('createBatch: type "%s" not supported.', $type));
        }

        $this->outputLog($this->hubspotLogger, $log);

        return $created;
    }

    public function updateBatch(string $type, array $data)
    {
        $this->runUntilAvailable();
        $messages = [];
        foreach ($data as $datum) {
            $messages[] = sprintf(
                'Update %s from batch : %s',
                $type,
                $datum['id'] ?? 'n/a',
            );
        }

        $log = $this->createLog('HUBSPOT', 'PUT', $messages);

        switch ($type) {
            case 'contact':
                $p = new HubspotCRM\Contacts\Model\BatchInputSimplePublicObjectBatchInput();
                $p->setInputs($data);
                $this->client->crm()->contacts()->batchApi()->update($p);
                break;
            case 'company':
                $p = new HubspotCRM\Companies\Model\BatchInputSimplePublicObjectBatchInput();
                $p->setInputs($data);
                $this->client->crm()->companies()->batchApi()->update($p);
                break;
            case 'deal':
                $p = new HubspotCRM\Deals\Model\BatchInputSimplePublicObjectBatchInput();
                $p->setInputs($data);
                $this->client->crm()->deals()->batchApi()->update($p);
                break;
            case 'line_item':
                $p = new HubspotCRM\LineItems\Model\BatchInputSimplePublicObjectBatchInput();
                $p->setInputs($data);
                $this->client->crm()->lineItems()->batchApi()->update($p);
                break;
            default:
                throw new \RuntimeException(sprintf('updateBatch: type "%s" not supported.', $type));
        }

        $this->outputLog($this->hubspotLogger, $log);
    }

    public function batchLinks(array $links, string $from, string $to): void
    {
        $this->runUntilAvailable();

        $associationType = sprintf('%s_to_%s', $from, $to);
        $messages = [];
        $inputs = [];
        foreach ($links as $link) {
            $linkType = $link['type'] ?? $associationType;
            $messages[] = sprintf(
                'Create %s links : %s / %s',
                $linkType,
                $link['from'] ?? 'unknown',
                $link['to'] ?? 'unknown',
            );

            $association = new HubspotCRM\Associations\Model\PublicAssociation();
            $association->setFrom(
                (new HubspotCRM\Associations\Model\PublicObjectId())
                    ->setId((string) $link['from'])
            );
            $association->setTo(
                (new HubspotCRM\Associations\Model\PublicObjectId())
                    ->setId((string) $link['to'])
            );
            $association->setType($linkType);

            $inputs[] = $association;
        }
        $log = $this->createLog('HUBSPOT', 'POST', $messages);

        $batch = new HubspotCRM\Associations\Model\BatchInputPublicAssociation();
        $batch->setInputs($inputs);

        $this->client->crm()->associations()->batchApi()->create(
            from_object_type: $from,
            to_object_type: $to,
            batch_input_public_association: $batch,
        );

        $this->outputLog($this->hubspotLogger, $log);
    }

    /**
     * @return string[]
     */
    public function fetchAllIds(string $type): array
    {
        $ids = [];
        $after = null;

        do {
            $this->runUntilAvailable();

            $response = match ($type) {
                'contact' => $this->client->crm()->contacts()->basicApi()->getPage(
                    limit: 100,
                    after: $after,
                    archived: false,
                ),
                'deal' => $this->client->crm()->deals()->basicApi()->getPage(
                    limit: 100,
                    after: $after,
                    archived: false,
                ),
                'company' => $this->client->crm()->companies()->basicApi()->getPage(
                    limit: 100,
                    after: $after,
                    archived: false,
                ),
                'line_item' => $this->client->crm()->lineItems()->basicApi()->getPage(
                    limit: 100,
                    after: $after,
                    archived: false,
                ),
                default => throw new \RuntimeException(sprintf('fetchAllIds: type "%s" not supported.', $type)),
            };

            foreach ($response->getResults() as $object) {
                $ids[] = $object->getId();
            }

            $after = $response->getPaging()?->getNext()?->getAfter();
        } while ($after !== null);

        return $ids;
    }

    public function search(
        string $type,
        array $objects,
        string $property,
        int $after = 0
    ) {
        $this->runUntilAvailable(true);

        $log = $this->createLog(
            'HUBSPOT',
            'POST',
            sprintf('Fetch %s batch by page with property %s : %s', $type, $property, $after)
        );

        $array = [
            'limit' => 100,
            'after' => $after,
            'properties' => [
                $property,
                'origin_id',
            ],
            'filter_groups' => [
                [
                    'filters' => [
                        [
                            'propertyName' => $property,
                            'values' => $objects,
                            'operator' => 'IN',
                        ],
                    ],
                ],
            ],
        ];

        switch ($type) {
            case 'contact':
                $target = new HubspotCRM\Contacts\Model\PublicObjectSearchRequest($array);
                $objects = $this->client->crm()->contacts()->searchApi()->doSearch($target);
                break;
            case 'company':
                $target = new HubspotCRM\Companies\Model\PublicObjectSearchRequest($array);
                $objects = $this->client->crm()->companies()->searchApi()->doSearch($target);
                break;
            case 'deal':
                $target = new HubspotCRM\Deals\Model\PublicObjectSearchRequest($array);
                $objects = $this->client->crm()->deals()->searchApi()->doSearch($target);
                break;
            case 'line_item':
                $target = new HubspotCRM\LineItems\Model\PublicObjectSearchRequest($array);
                $objects = $this->client->crm()->lineItems()->searchApi()->doSearch($target);
                break;
            case 'product':
                $target = new HubspotCRM\Products\Model\PublicObjectSearchRequest($array);
                $objects = $this->client->crm()->products()->searchApi()->doSearch($target);
                break;
            default:
                throw new \RuntimeException(sprintf('search: type "%s" not supported.', $type));
        }

        $log->setMessage(
            sprintf('Fetch %s batch by page with property %s : %s', $type, $property, $objects->getTotal())
        );
        $this->outputLog($this->hubspotLogger, $log);

        return $objects;
    }

    protected function createLog(string $source, string $sourceMore, string|array $message): HubspotLogEntry
    {
        return new HubspotLogEntry($source, $sourceMore, $message);
    }

    protected function outputLog(LoggerInterface $logger, HubspotLogEntry $logEntity): void
    {
        $logger->info($logEntity->messageAsString(), [
            'source' => $logEntity->getSource(),
            'action' => $logEntity->getSourceMore(),
        ]);
    }

    protected function runUntilAvailable(bool $search = false): void
    {
        $this->hubspotGlobalLimiter->create('hubspot')->reserve(1)->wait();

        if ($search) {
            $this->hubspotSearchLimiter->create('hubspot-search')->reserve(1)->wait();
        }
    }
}
