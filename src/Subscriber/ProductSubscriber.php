<?php declare(strict_types=1);

namespace Px86\CategoryNotifier\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\ProductIndexerEvent;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Px86\CategoryNotifier\Service\NotificationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class ProductSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categorySubscriptionRepository,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        private readonly ProductStreamBuilder $productStreamBuilder,
        private readonly Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            'product_category.written'           => 'onProductCategoryWritten',
            ProductIndexerEvent::class           => 'onProductIndexed',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        

        foreach ($event->getWriteResults() as $result) {
            $operation = $result->getOperation();
            
            // Nur bei INSERT (neue Produkte)
            if ($operation !== 'insert') {
                continue;
            }
            
            $productId = $result->getPrimaryKey();
            
            $criteria = new Criteria([$productId]);
            $criteria->addAssociation('categories');
            
            $product = $this->productRepository->search($criteria, $context)->first();
            
            if (!$product || !$product->getCategories()) {
                continue;
            }


            foreach ($product->getCategories() as $category) {
                $this->notifySubscribers($category->getId(), $product, $context);
            }
        }
    }

    public function onProductCategoryWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        
        
        // Für jede neue Produkt-Kategorie-Zuordnung
        foreach ($event->getWriteResults() as $result) {
            // Nur bei neuen Zuordnungen (insert)
            if ($result->getOperation() !== 'insert') {
                continue;
            }
            
            $payload = $result->getPayload();
            
            if (!isset($payload['productId']) || !isset($payload['categoryId'])) {
                continue;
            }
            
            $productId = $payload['productId'];
            $categoryId = $payload['categoryId'];
            
            
            // Produkt laden mit createdAt
            $criteria = new Criteria([$productId]);
            $product = $this->productRepository->search($criteria, $context)->first();
            
            if (!$product) {
                continue;
            }
            
            // Wenn Produkt gerade erstellt wurde (< 5 Sekunden), überspringen
            // (wird bereits von onProductWritten behandelt)
            $createdAt = $product->getCreatedAt();
            $now = new \DateTime();
            $diff = $now->getTimestamp() - $createdAt->getTimestamp();
            
            if ($diff < 5) {
                continue;
            }
            
            $this->notifySubscribers($categoryId, $product, $context);
        }
    }

    public function onProductIndexed(ProductIndexerEvent $event): void
    {
        $context = $event->getContext();
        $productIds = $event->getIds();

        if (empty($productIds)) {
            return;
        }

        $dynamicCategories = $this->getSubscribedDynamicCategories($context);

        if (empty($dynamicCategories)) {
            return;
        }

        foreach ($productIds as $productId) {
            $matchedCategoryIds = [];

            foreach ($dynamicCategories as $categoryId => $streamId) {
                if ($this->wasAlreadySent($productId, $categoryId)) {
                    continue;
                }

                if ($this->productMatchesStream($productId, $streamId, $context)) {
                    $matchedCategoryIds[] = $categoryId;
                }
            }

            if (empty($matchedCategoryIds)) {
                continue;
            }

            $criteria = new Criteria([$productId]);
            $product = $this->productRepository->search($criteria, $context)->first();

            if (!$product) {
                continue;
            }

            foreach ($matchedCategoryIds as $categoryId) {
                $this->notifySubscribers($categoryId, $product, $context);
                $this->markAsSent($productId, $categoryId);
            }
        }
    }

    /**
     * Returns [categoryId => streamId] for all categories with a dynamic product stream
     * that have at least one active confirmed subscription.
     *
     * @return array<string, string>
     */
    private function getSubscribedDynamicCategories(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('confirmed', true));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('category');

        $subscriptions = $this->categorySubscriptionRepository->search($criteria, $context);

        $dynamicCategories = [];
        foreach ($subscriptions->getElements() as $sub) {
            $category = $sub->getCategory();
            if ($category && $category->getProductStreamId() !== null) {
                $dynamicCategories[$category->getId()] = $category->getProductStreamId();
            }
        }

        return $dynamicCategories;
    }

    private function productMatchesStream(string $productId, string $streamId, Context $context): bool
    {
        try {
            $filters = $this->productStreamBuilder->buildFilters($streamId, $context);
            $criteria = new Criteria();
            $criteria->addFilter(...$filters);
            $criteria->addFilter(new EqualsFilter('id', $productId));

            return $this->productRepository->searchIds($criteria, $context)->getTotal() > 0;
        } catch (\Throwable $e) {
            $this->logger->error('Category Notifier: Error evaluating product stream', [
                'streamId'  => $streamId,
                'productId' => $productId,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function wasAlreadySent(string $productId, string $categoryId): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM px86_category_notifier_sent WHERE product_id = :productId AND category_id = :categoryId',
            [
                'productId'  => Uuid::fromHexToBytes($productId),
                'categoryId' => Uuid::fromHexToBytes($categoryId),
            ]
        );

        return (int) $count > 0;
    }

    private function markAsSent(string $productId, string $categoryId): void
    {
        $this->connection->executeStatement(
            'INSERT IGNORE INTO px86_category_notifier_sent (product_id, category_id, created_at) VALUES (:productId, :categoryId, NOW(3))',
            [
                'productId'  => Uuid::fromHexToBytes($productId),
                'categoryId' => Uuid::fromHexToBytes($categoryId),
            ]
        );
    }

    private function notifySubscribers(string $categoryId, $product, Context $context): void
    {
        $this->logger->info('Category Notifier: Checking subscriptions for category', ['categoryId' => $categoryId, 'productId' => $product->getId()]);
        
        // Alle aktiven und bestätigten Abonnements für diese Kategorie laden
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('categoryId', $categoryId));
        $criteria->addFilter(new EqualsFilter('confirmed', true));
        $criteria->addFilter(new EqualsFilter('active', true));

        $subscriptions = $this->categorySubscriptionRepository->search($criteria, $context);

        $this->logger->info('Category Notifier: Found subscriptions', ['count' => $subscriptions->count()]);

        if ($subscriptions->count() === 0) {
            return;
        }

        // Benachrichtigungen versenden
        foreach ($subscriptions->getElements() as $subscription) {
            try {
                $this->logger->info('Category Notifier: Sending notification to', ['email' => $subscription->getEmail()]);
                $this->notificationService->sendNewProductNotification($subscription, $product, $context);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send product notification', [
                    'productId' => $product->getId(),
                    'categoryId' => $categoryId,
                    'subscriptionEmail' => $subscription->getEmail(),
                    'exception' => $e->getMessage()
                ]);
            }
        }
    }
}
