# Copilot Instructions — Px86CategoryNotifier

A Shopware 6.6/6.7 plugin that lets storefront visitors subscribe to email notifications for new products in categories. It uses double-opt-in, supports multiple languages (DE/EN), and is GDPR-compliant.

## Build & Asset Commands

Run from the Shopware root (`bin/` lives there, not in this plugin directory):

```bash
# Install / activate the plugin
bin/console plugin:refresh
bin/console plugin:install --activate Px86CategoryNotifier

# Build storefront and admin assets (required after JS/SCSS changes)
bin/build-storefront.sh
bin/build-administration.sh

# Install compiled assets into the public directory
bin/console assets:install

# Clear cache (required after snippet / template changes)
bin/console cache:clear
```

No automated test suite exists in this plugin.

## Architecture Overview

### Data flow — subscription lifecycle
1. **Subscribe** — Storefront form POSTs to `CategorySubscriptionController::subscribe()`. A new `px86_category_notifier_subscription` row is created with `confirmed=false` and a `confirmToken` (64-char hex from `random_bytes(32)`). `NotificationService::sendConfirmationEmail()` is called.
2. **Confirm** — User clicks the link → `confirm()` sets `confirmed=true`, clears the token.
3. **Notify** — `ProductSubscriber` listens to `ProductEvents::PRODUCT_WRITTEN_EVENT` and `product_category.written`. On INSERT, it queries all confirmed+active subscriptions for the affected category and calls `NotificationService::sendNewProductNotification()`.
4. **Unsubscribe** — Link in every notification email hits `unsubscribe()`, which sets `active=false`.

### Key components
| Layer | Class | Responsibility |
|---|---|---|
| Entity | `CategorySubscriptionDefinition` / `Entity` / `Collection` | Shopware DAL entity for `px86_category_notifier_subscription` |
| Controller | `CategorySubscriptionController` | Three storefront routes: subscribe, confirm, unsubscribe |
| Service | `NotificationService` | Builds and sends all plugin emails via Shopware's MailService |
| Subscriber | `ProductSubscriber` | Fires notifications when new products are created or assigned to categories |
| Subscriber | `StorefrontSubscriber` | Injects salutations into `NavigationPageLoadedEvent` for the form |
| Subscriber | `MailTemplateSubscriber` | Adds `mailTemplate` to context before Shopware validates mail templates |
| Migration | `Migration1699800002Init` | Creates the subscription table + both mail template types + snippets |
| Plugin class | `Px86CategoryNotifier` | Handles uninstall cleanup (drops table, deletes mail templates) |

## Key Conventions

### Shopware DAL — always use the repository
All reads and writes go through `EntityRepository`, never raw SQL (the migration is the only exception).

```php
// Correct — search via criteria
$criteria = new Criteria();
$criteria->addFilter(new EqualsFilter('email', $email));
$criteria->addFilter(new EqualsFilter('categoryId', $categoryId));
$this->repository->search($criteria, $context)->first();

// Correct — update
$this->repository->update([['id' => $id, 'confirmed' => true, 'confirmToken' => null]], $context);
```

### Confirmation token generation
Always generate tokens with `bin2hex(random_bytes(32))` — produces a 64-character hex string stored in `confirmToken`.

### Double-notification guard in `ProductSubscriber`
When a product is newly created, both `PRODUCT_WRITTEN_EVENT` and `product_category.written` fire. The subscriber skips the `product_category.written` handler if the product was created less than 5 seconds ago to avoid sending duplicate emails.

### Route attributes (no annotations)
Routes use PHP 8 attributes, not Doctrine annotations:
```php
#[Route(path: '/category-notifier/subscribe', name: 'px86_category_notifier_subscribe', methods: ['POST'], defaults: ['_loginRequired' => false, 'XmlHttpRequest' => true])]
```
`routes.yaml` points at the Controller directory with `type: attribute`.

### Plugin config keys
Config values are read from the system config under the plugin's name. The three fields are:
- `Px86CategoryNotifier.config.displayMode` — `all` | `selected` | `excluded`
- `Px86CategoryNotifier.config.selectedCategories` — array of category IDs
- `Px86CategoryNotifier.config.formPosition` — `before` | `after`

### Mail template technical names
Both mail templates are registered in the migration and identified by technical name:
- `px86_category_notifier.confirmation`
- `px86_category_notifier.new_product`

`MailTemplateSubscriber` checks for these names in `MailBeforeValidateEvent` to inject `mailTemplate` into the template context.

### Snippet keys
All storefront snippet keys are prefixed `px86-category-notifier.` (dash-separated). Source files live in `src/Resources/snippet/`. Admin snippets use the same prefix but are in `src/Resources/app/administration/src/snippet/`.

### Admin UI
The admin module is registered under the `sw-marketing` navigation parent with `icon: 'regular-bell'`. The single view is `px86-category-notifier-list`, a read-only subscription listing using `sw-entity-listing`.

### Storefront JS plugin
The plugin class `CategoryNotifierPlugin extends Plugin` is registered on `#category-notifier-form`. It uses `fetch` with `FormData` and expects `{ success: boolean, message: string }` JSON from the controller.

### Uninstall cleanup
The plugin class deletes mail templates with a `technical_name LIKE 'px86_category_notifier%'` DBAL query, then conditionally drops the subscription table if `$context->keepUserData()` is false.
