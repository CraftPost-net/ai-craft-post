# AI Craft Post

AI Craft Post is a WordPress connector for the [CraftPost](https://craftpost.net/) content automation service. It receives authenticated REST API webhooks and can create or update content, import images, write supported SEO metadata, manage FAQ content, and connect Polylang translations.

## Features

- Creates and updates WordPress posts and pages.
- Imports featured and section images into the Media Library.
- Supports Yoast SEO, Rank Math, and All in One SEO metadata.
- Supports FAQ content and optional FAQPage structured data.
- Supports multilingual content through Polylang.
- Authenticates and signs incoming requests and protects them against replay attacks.

## Requirements

- WordPress 6.2 or newer.
- PHP 7.4 or newer.
- A CraftPost account and site connection key.

## Installation

1. Download a release ZIP.
2. In WordPress, open **Plugins > Add New > Upload Plugin**.
3. Upload and activate AI Craft Post.
4. Open **Tools > AI Craft Post**.
5. Add the WordPress site in CraftPost and paste the generated `aic_live` site key into the plugin settings.

## External service and privacy

The plugin connects WordPress to the external CraftPost service after an administrator configures a site key. Details about the transferred data are documented in [`readme.txt`](readme.txt).

- [CraftPost Privacy Policy](https://craftpost.net/privacy-policy/)
- [CraftPost Terms of Service](https://craftpost.net/terms-of-service/)

## Ukrainian description

AI Craft Post підключає WordPress-сайт до сервісу автоматизації контенту CraftPost. Плагін може створювати й оновлювати матеріали, завантажувати зображення, записувати SEO-дані, додавати FAQ та працювати з перекладами Polylang. Вхідні запити проходять автентифікацію, перевірку підпису та захист від повторного відтворення.

## Development and releases

The Git repository is the development source. Approved WordPress.org releases are published separately to the WordPress.org SVN repository.

Run the official Plugin Check before creating a release. Release ZIP files must contain the `ai-craft-post` directory and exclude Git metadata, local documentation, IDE settings, logs, and previous ZIP files.

## License

AI Craft Post is licensed under GPLv2 or later.
