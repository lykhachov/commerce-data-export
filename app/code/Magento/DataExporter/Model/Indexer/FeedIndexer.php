<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\DataExporter\Model\Indexer;

use Magento\DataExporter\Export\Processor;
use Magento\DataExporter\Model\FeedPool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * Product export feed indexer class
 */
class FeedIndexer implements IndexerActionInterface, MviewActionInterface
{
    /**
     * @var Processor
     */
    private $processor;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var FeedIndexMetadata
     */
    private $feedIndexMetadata;

    /**
     * @var DataSerializerInterface
     */
    private $dataSerializer;

    /**
     * @var FeedIndexerCallbackInterface
     */
    private $feedIndexerCallback;

    /**
     * @var array
     */
    private $callbackSkipAttributes;

    /**
     * @var FeedPool
     */
    private $feedPool;

    /**
     * @param Processor $processor
     * @param ResourceConnection $resourceConnection
     * @param DataSerializerInterface $serializer
     * @param FeedIndexMetadata $feedIndexMetadata
     * @param FeedIndexerCallbackInterface $feedIndexerCallback
     * @param FeedPool $feedPool
     * @param array $callbackSkipAttributes
     */
    public function __construct(
        Processor $processor,
        ResourceConnection $resourceConnection,
        DataSerializerInterface $serializer,
        FeedIndexMetadata $feedIndexMetadata,
        FeedIndexerCallbackInterface $feedIndexerCallback,
        FeedPool $feedPool,
        array $callbackSkipAttributes = []
    ) {
        $this->processor = $processor;
        $this->resourceConnection = $resourceConnection;
        $this->feedIndexMetadata = $feedIndexMetadata;
        $this->dataSerializer = $serializer;
        $this->feedIndexerCallback = $feedIndexerCallback;
        $this->feedPool = $feedPool;
        $this->callbackSkipAttributes = $callbackSkipAttributes;
    }

    /**
     * Get Ids select
     *
     * @param int $lastKnownId
     * @return Select
     */
    private function getIdsSelect(int $lastKnownId) : Select
    {
        $columnExpression = sprintf('s.%s', $this->feedIndexMetadata->getSourceTableField());
        $whereClause = sprintf('s.%s > ?', $this->feedIndexMetadata->getSourceTableField());
        $connection = $this->resourceConnection->getConnection();
        return $connection->select()
            ->from(
                ['s' => $this->resourceConnection->getTableName($this->feedIndexMetadata->getSourceTableName())],
                [
                    $this->feedIndexMetadata->getFeedIdentity() =>
                        's.' . $this->feedIndexMetadata->getSourceTableField()
                ]
            )
            ->where($whereClause, $lastKnownId)
            ->order($columnExpression)
            ->limit($this->feedIndexMetadata->getBatchSize());
    }

    /**
     * Get all product IDs
     *
     * @return \Generator
     * @throws \Zend_Db_Statement_Exception
     */
    private function getAllIds() : ?\Generator
    {
        $connection = $this->resourceConnection->getConnection();
        $lastKnownId = 0;
        $continueReindex = true;
        while ($continueReindex) {
            $ids = $connection->fetchAll($this->getIdsSelect((int)$lastKnownId));
            if (empty($ids)) {
                $continueReindex = false;
            } else {
                yield $ids;
                $lastKnownId = end($ids)[$this->feedIndexMetadata->getFeedIdentity()];
            }
        }
    }

    /**
     * Execute full indexation
     *
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function executeFull()
    {
        $this->truncateFeedTable();
        foreach ($this->getAllIds() as $ids) {
            $this->markRemoved($ids);
            $this->process($ids);
        }
    }

    /**
     * Execute partial indexation by ID list
     *
     * @param int[] $ids
     * @return void
     */
    public function executeList(array $ids)
    {
        $arguments = [];
        foreach ($ids as $id) {
            $arguments[] = [$this->feedIndexMetadata->getFeedIdentity() => $id];
        }
        $this->markRemoved($ids);
        $this->process($arguments);
    }

    /**
     * Execute partial indexation by ID
     *
     * @param int $id
     * @return void
     */
    public function executeRow($id)
    {
        $this->markRemoved([$id]);
        $this->process([[$this->feedIndexMetadata->getFeedIdentity() => $id]]);
    }

    /**
     * Execute materialization on ids entities
     *
     * @param int[] $ids
     * @return void
     * @api
     */
    public function execute($ids)
    {
        $arguments = [];
        $feedIdentity = $this->feedIndexMetadata->getFeedIdentity();

        foreach ($ids as $id) {
            $arguments[] = \is_array($id) ? $this->prepareIndexData($id) : [
                $feedIdentity => $id,
            ];
        }

        $this->markRemoved(\array_column($arguments, $feedIdentity));
        $this->process($arguments);
    }

    /**
     * Prepare index data
     *
     * @param array $indexData
     *
     * @return array
     */
    private function prepareIndexData(array $indexData): array
    {
        $attributeIds = !empty($indexData['attribute_ids'])
            ? \array_unique(\explode(',', $indexData['attribute_ids']))
            : [];

        return [
            $this->feedIndexMetadata->getFeedIdentity() => $indexData['entity_id'],
            'attribute_ids' => $attributeIds,
            'scopeId' => $indexData['store_id'] ?? null,
        ];
    }

    /**
     * Indexer feed data processor
     *
     * @param array $indexData
     *
     * @return void
     */
    private function process($indexData = []) : void
    {
        $feedIdentity = $this->feedIndexMetadata->getFeedIdentity();
        $data = $this->processor->process($this->feedIndexMetadata->getFeedName(), $indexData);
        $chunks = array_chunk($data, $this->feedIndexMetadata->getBatchSize());
        $connection = $this->resourceConnection->getConnection();
        $callbackData = [];
        $existingFeedData = [];

        $updateEntities = \array_filter($indexData, function ($data) {
            return !empty($data['attribute_ids']);
        });

        if (!empty($updateEntities)) {
            $existingFeedData = $this->fetchFeedData(\array_column($updateEntities, $feedIdentity));
        }

        foreach ($chunks as $chunk) {
            $connection->insertOnDuplicate(
                $this->resourceConnection->getTableName($this->feedIndexMetadata->getFeedTableName()),
                $this->dataSerializer->serialize($this->prepareChunkData($chunk, $existingFeedData, $callbackData)),
                $this->feedIndexMetadata->getFeedTableMutableColumns()
            );
        }

        $deleteIds = [];
        $callbackIds = \array_column($callbackData, $feedIdentity);
        foreach ($indexData as $data) {
            if (!\in_array($data[$feedIdentity], $callbackIds)) {
                $deleteIds[] = $data[$feedIdentity];
            }
        }

        $this->feedIndexerCallback->execute($callbackData, $deleteIds);
    }

    /**
     * Prepare chunk data
     *
     * @param array $chunk
     * @param array $existingFeedData
     * @param array $callbackData
     *
     * @return array
     */
    private function prepareChunkData(array $chunk, array $existingFeedData, array &$callbackData): array
    {
        $feedIdentity = $this->feedIndexMetadata->getFeedIdentity();

        foreach ($chunk as &$feedData) {
            $existingData = $existingFeedData[$feedData['storeViewCode']][$feedData[$feedIdentity]] ?? null;
            $attributes = [];

            if (null !== $existingData) {
                $attributes = \array_filter(\array_keys($feedData), function ($code) {
                    return !\in_array($code, $this->callbackSkipAttributes);
                });
                $feedData = \array_replace_recursive($existingData, $feedData);
            }

            $callbackData[] = \array_filter(
                [
                    $feedIdentity => $feedData[$feedIdentity],
                    'storeViewCode' => $feedData['storeViewCode'],
                    'attributes' => $attributes,
                ]
            );
        }

        return $chunk;
    }

    /**
     * Fetch feed data
     *
     * @param int[] $ids
     *
     * @return array
     */
    private function fetchFeedData(array $ids): array
    {
        $feedData = $this->feedPool->getFeed($this->feedIndexMetadata->getFeedName())->getFeedByIds($ids);
        $feedIdentity = $this->feedIndexMetadata->getFeedIdentity();
        $output = [];

        foreach ($feedData['feed'] as $feedItem) {
            $output[$feedItem['storeViewCode']][$feedItem[$feedIdentity]] = $feedItem;
        }

        return $output;
    }

    /**
     * Mark field removed
     *
     * @param array $ids
     * @return void
     */
    private function markRemoved(array $ids) : void
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->joinLeft(
                ['s' => $this->resourceConnection->getTableName($this->feedIndexMetadata->getSourceTableName())],
                sprintf(
                    'f.%s = s.%s',
                    $this->feedIndexMetadata->getFeedTableField(),
                    $this->feedIndexMetadata->getSourceTableField()
                ),
                ['is_deleted' => new \Zend_Db_Expr('1')]
            )
            ->where(sprintf('s.%s IS NULL', $this->feedIndexMetadata->getSourceTableField()))
            ->where(
                sprintf('f.%s IN (?)', $this->feedIndexMetadata->getFeedTableField()),
                $ids
            );
        $update = $connection->updateFromSelect(
            $select,
            ['f' => $this->resourceConnection->getTableName($this->feedIndexMetadata->getFeedTableName())]
        );
        $connection->query($update);
    }

    /**
     * Truncate feed table
     *
     * @return void
     */
    private function truncateFeedTable(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $feedTable = $this->resourceConnection->getTableName($this->feedIndexMetadata->getFeedTableName());
        $connection->truncateTable($feedTable);
    }
}
