# Shopware Category Notifier Plugin

A Shopware 6.6+ / 6.7 plugin that allows visitors to subscribe to email notifications for new products in specific categories.

## Thanks for your Support <3 
[!["Buy Me A Coffee"](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://buymeacoffee.com/busaku)

## Features

✅ **Frontend subscription form** on category pages
✅ **Double-Opt-In** confirmation via email
✅ **Automatic notifications** for new products
✅ **Notifications for category assignments** to existing products
✅ **Administration module** to manage subscriptions
✅ **Multilingual** (German/English)
✅ **GDPR compliant** with unsubscribe functionality
✅ **Customizable email templates** in admin panel
✅ **Compatible** with Shopware 6.6 and 6.7

## Installation

### Via Composer (recommended)

```bash
composer require px86/category-notifier
bin/console plugin:refresh
bin/console plugin:install --activate Px86CategoryNotifier
```

### Manual Installation

1. Download the latest release ZIP
2. Extract to `custom/plugins/Px86CategoryNotifier`
3. Install via CLI:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate Px86CategoryNotifier
   ```

### Build Assets

After installation, build the assets:

```bash
# Storefront assets
bin/console assets:install
bin/build-storefront.sh

# Administration assets
bin/build-administration.sh
```

## Configuration

Navigate to: **Settings → Extensions → My Extensions → Px86CategoryNotifier → ... → Configuration**

### Basic Settings

- **Display Mode**: Configure where the subscription form should appear
  - Show in all categories
  - Show only in selected categories
  - Show in all except selected categories

- **Selected Categories**: Choose specific categories (required for "selected" and "excluded" modes)

### Form Display

- **Form Position**: Choose whether to display the form above or below the product listing

## Customization

### Storefront Texts

Text strings in the subscription form can be customized in the snippet files:

**German:**
```
src/Resources/snippet/de_DE/messages.de-DE.json
```

**English:**
```
src/Resources/snippet/en_GB/messages.en-GB.json
```

Available snippet keys:
- `px86-category-notifier.subscription.title` - Form heading
- `px86-category-notifier.subscription.description` - Form description
- `px86-category-notifier.subscription.email` - Email field label
- `px86-category-notifier.subscription.firstName` - First name label
- `px86-category-notifier.subscription.lastName` - Last name label
- `px86-category-notifier.subscription.submit` - Submit button text
- `px86-category-notifier.subscription.success` - Success message
- `px86-category-notifier.subscription.error.*` - Error messages

**Clear cache after changes:**
```bash
bin/console cache:clear
```

### Email Templates

Email templates can be edited directly in the admin panel:

**Settings → Email Templates**

Search for "Category" or filter by plugin:
- **Category Notification: Confirmation** - Double-opt-in email
- **Category Notification: New Product** - Product notification email

### Styling

Customize the form design in:
```
src/Resources/app/storefront/src/scss/base.scss
```

### Additional Languages

Add translations by creating new snippet files under `src/Resources/snippet/`

## Technical Details

### Database Schema

Table: `px86_category_notifier_subscription`
- Stores email, category ID, name, and status
- Foreign keys to `category` and `salutation` tables
- Optimized with indexes for performance

### Event System

- Listens to `ProductEvents::PRODUCT_WRITTEN_EVENT`
- Automatically detects new products
- Sends notifications to all confirmed subscribers
- Detects category assignments to existing products

### API Endpoints

- `POST /category-notifier/subscribe` - Create new subscription
- `GET /category-notifier/confirm/{token}` - Confirm subscription
- `GET /category-notifier/unsubscribe/{email}/{categoryId}` - Unsubscribe

## Development

### Requirements

- PHP 8.1+
- Shopware 6.6.0+ or 6.7.0+
- Composer
- Node.js (for asset building)

### Code Quality

This plugin follows Shopware Store quality guidelines:
- ✅ PSR-4 Autoloading
- ✅ Shopware 6.6/6.7 API compatibility
- ✅ Multilingual support
- ✅ Admin interface
- ✅ Proper migration system
- ✅ Service container pattern
- ✅ Event subscriber pattern
- ✅ GDPR compliant

## Changelog

### Version 1.0.0
- Initial release
- Frontend subscription form
- Double-opt-in confirmation
- Automatic product notifications
- Administration module for subscription management
- Multilingual support (DE/EN)
- Customizable email templates
- GDPR-compliant unsubscribe functionality

## Support

- **Issues**: [GitHub Issues](https://github.com/px86de/shopware-category-notifier/issues)
- **Donate**: [Buy me a coffee](https://buymeacoffee.com/busaku)
