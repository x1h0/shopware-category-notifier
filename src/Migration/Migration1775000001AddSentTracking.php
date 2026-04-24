<?php declare(strict_types=1);

namespace Px86\CategoryNotifier\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1775000001AddSentTracking extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1775000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `px86_category_notifier_sent` (
                `product_id`   BINARY(16) NOT NULL,
                `category_id`  BINARY(16) NOT NULL,
                `created_at`   DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`, `category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
