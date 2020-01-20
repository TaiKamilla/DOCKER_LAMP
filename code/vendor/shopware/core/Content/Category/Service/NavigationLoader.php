<?php declare(strict_types=1);

namespace Shopware\Core\Content\Category\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Event\NavigationLoadedEvent;
use Shopware\Core\Content\Category\Exception\CategoryNotFoundException;
use Shopware\Core\Content\Category\Tree\Tree;
use Shopware\Core\Content\Category\Tree\TreeItem;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class NavigationLoader
{
    /**
     * @var SalesChannelRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var TreeItem
     */
    private $treeItem;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection, SalesChannelRepositoryInterface $repository, EventDispatcherInterface $eventDispatcher)
    {
        $this->categoryRepository = $repository;
        $this->treeItem = new TreeItem(null, []);
        $this->eventDispatcher = $eventDispatcher;
        $this->connection = $connection;
    }

    /**
     * Returns the first two levels of the category tree, as well as all parents of the active category
     * and the active categories first level of children.
     * The provided active id will be marked as selected
     *
     * @throws CategoryNotFoundException
     * @throws InconsistentCriteriaIdsException
     */
    public function load(string $activeId, SalesChannelContext $context, string $rootId): Tree
    {
        $metaInfo = $this->getCategoryMetaInfo($activeId, $rootId);

        $active = $this->getMetaInfoById($activeId, $metaInfo);
        if (!$this->isCategoryChildOfRootCategory($activeId, $active['path'], $rootId)) {
            throw new CategoryNotFoundException($activeId);
        }

        $root = $this->getMetaInfoById($rootId, $metaInfo);

        // Load the first two levels without using the activeId in the query, so this can be cached
        $firstTwoLevels = $this->loadLevels($rootId, (int) $root['level'], $context);

        $childIds = $this->getChildIds($activeId, $rootId, $metaInfo);

        // Fetch all parents and first-level children of the active category, if they're not already fetched
        $missing = $this->getMissingIds($activeId, $active['path'], $childIds, $firstTwoLevels);
        if (!empty($missing)) {
            $missingCategories = $this->loadMissingCategories($missing, $context);

            foreach ($missingCategories as $category) {
                $firstTwoLevels->add($category);
            }
        }

        $navigation = $this->getTree($rootId, $firstTwoLevels, $firstTwoLevels->get($activeId));

        $event = new NavigationLoadedEvent($navigation, $context);

        $this->eventDispatcher->dispatch($event);

        return $event->getNavigation();
    }

    /**
     * Returns the category tree level for the provided category id.
     *
     * @throws CategoryNotFoundException
     * @throws InconsistentCriteriaIdsException
     */
    public function loadLevel(string $categoryId, SalesChannelContext $context): Tree
    {
        $active = $this->loadActive($categoryId, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('visible', true));
        $criteria->addFilter(new MultiFilter(
            MultiFilter::CONNECTION_OR,
            [
                new EqualsFilter('category.id', $categoryId),
                new EqualsFilter('category.parentId', $categoryId),
            ]
        ));

        /** @var CategoryCollection $categories */
        $categories = $this->categoryRepository->search($criteria, $context)->getEntities();
        $parentId = $categories->get($categoryId)->getParentId();

        $navigation = $this->getTree($parentId, $categories, $active);

        $event = new NavigationLoadedEvent($navigation, $context);

        $this->eventDispatcher->dispatch($event);

        return $event->getNavigation();
    }

    private function getTree(?string $parentId, CategoryCollection $categories, CategoryEntity $active): Tree
    {
        $tree = $this->buildTree($parentId, $categories->getElements());

        return new Tree($active, $tree);
    }

    /**
     * @throws CategoryNotFoundException
     * @throws InconsistentCriteriaIdsException
     */
    private function loadActive(string $activeId, SalesChannelContext $context): CategoryEntity
    {
        $criteria = new Criteria([$activeId]);
        $criteria->addAssociation('media');

        $active = $this->categoryRepository->search($criteria, $context)->first();
        if (!$active) {
            throw new CategoryNotFoundException($activeId);
        }

        return $active;
    }

    /**
     * @param CategoryEntity[] $categories
     *
     * @return TreeItem[]
     */
    private function buildTree(?string $parentId, array $categories): array
    {
        $children = new CategoryCollection();
        foreach ($categories as $key => $category) {
            if ($category->getParentId() !== $parentId) {
                continue;
            }

            unset($categories[$key]);

            $children->add($category);
        }

        $children->sortByPosition();

        $items = [];
        foreach ($children as $child) {
            if (!$child->getActive() || !$child->getVisible()) {
                continue;
            }

            $item = clone $this->treeItem;
            $item->setCategory($child);

            $item->setChildren(
                $this->buildTree($child->getId(), $categories)
            );

            $items[$child->getId()] = $item;
        }

        return $items;
    }

    private function isCategoryChildOfRootCategory(string $activeId, ?string $path, string $rootId): bool
    {
        if ($rootId === $activeId) {
            return true;
        }

        if ($path === null) {
            return false;
        }

        if (mb_strpos($path, '|' . $rootId . '|') !== false) {
            return true;
        }

        return false;
    }

    private function loadMissingCategories(array $missingIds, SalesChannelContext $context): CategoryCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsAnyFilter('id', $missingIds)
        )
        ->addAssociation('media');

        /** @var CategoryCollection $missing */
        $missing = $this->categoryRepository->search($criteria, $context)->getEntities();

        return $missing;
    }

    private function loadLevels(string $rootId, int $rootLevel, SalesChannelContext $context): CategoryCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new ContainsFilter('path', '|' . $rootId . '|'),
            new RangeFilter('level', [
                RangeFilter::GT => $rootLevel,
                RangeFilter::LTE => $rootLevel + 2,
            ])
        )
        ->addAssociation('media');

        /** @var CategoryCollection $firstTwoLevels */
        $firstTwoLevels = $this->categoryRepository->search($criteria, $context)->getEntities();

        return $firstTwoLevels;
    }

    private function getCategoryMetaInfo(string $activeId, string $rootId)
    {
        $result = $this->connection->fetchAll('
            SELECT LOWER(HEX(`id`)), `path`, `level`
            FROM `category`
            WHERE `id` = :activeId OR `parent_id` = :activeId OR `id` = :rootId
        ', ['activeId' => Uuid::fromHexToBytes($activeId), 'rootId' => Uuid::fromHexToBytes($rootId)]);

        if (!$result) {
            throw new CategoryNotFoundException($activeId);
        }

        return FetchModeHelper::groupUnique($result);
    }

    private function getMetaInfoById(string $id, $metaInfo): array
    {
        if (!array_key_exists($id, $metaInfo)) {
            throw new CategoryNotFoundException($id);
        }

        return $metaInfo[$id];
    }

    private function getMissingIds(string $activeId, ?string $path, array $childIds, CategoryCollection $alreadyLoaded): array
    {
        $parentIds = array_filter(explode('|', $path ?? ''));

        $haveToBeIncluded = array_merge($childIds, $parentIds, [$activeId]);
        $included = $alreadyLoaded->getIds();
        $included = array_flip($included);

        return array_diff($haveToBeIncluded, $included);
    }

    private function getChildIds(string $activeId, string $rootId, array $metaInfo): array
    {
        unset($metaInfo[$rootId]);
        unset($metaInfo[$activeId]);

        return array_keys($metaInfo);
    }
}
