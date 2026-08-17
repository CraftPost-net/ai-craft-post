# AI Craft Post

## English

AI Craft Post is a WordPress connector for the <a href="https://craftpost.net/" target="_blank" rel="noopener noreferrer">CraftPost</a> content automation service. It receives authenticated REST API webhooks and can create or update content, import images, write supported SEO metadata, manage FAQ content, and connect Polylang translations.

### Features

- Creates and updates WordPress posts and pages.
- Assigns authors, categories, tags, publication status, and templates.
- Imports featured and section images into the WordPress Media Library.
- Preserves native Gutenberg image, gallery, media-text, and cover blocks during article refreshes.
- Supports Yoast SEO, Rank Math, and All in One SEO metadata.
- Stores and displays FAQ content with optional FAQPage structured data.
- Supports multilingual content through Polylang.
- Authenticates and signs incoming requests and protects them against replay attacks.

### Requirements

- WordPress 6.2 or newer.
- PHP 7.4 or newer.
- A CraftPost account and site connection key.

### Installation

1. Download a release ZIP.
2. In WordPress, open **Plugins > Add New > Upload Plugin**.
3. Upload and activate AI Craft Post.
4. Open **Tools > AI Craft Post**.
5. Add the WordPress site in CraftPost and paste the generated `aic_live` site key into the plugin settings.
6. Choose the optional SEO and FAQ settings required for the website.

Use HTTPS for both the WordPress website and the CraftPost account.

### External service and privacy

The plugin connects WordPress to the external CraftPost service only after an administrator configures a site key. Authenticated CraftPost requests may retrieve the site URL, name, language, timezone, WordPress version, author account identifiers and roles, public post types, taxonomies, templates, image sizes, supported plugin information, and existing content selected for processing.

CraftPost may send generated or updated content, metadata, translations, FAQ items, and remote image URLs to WordPress. Remote images are downloaded to the Media Library, so the image host receives a request from the website server. A complete data-transfer description is available in <a href="readme.txt" target="_blank" rel="noopener noreferrer">readme.txt</a>.

- <a href="https://craftpost.net/privacy.html" target="_blank" rel="noopener noreferrer">CraftPost Privacy Policy</a>
- <a href="https://craftpost.net/terms.html" target="_blank" rel="noopener noreferrer">CraftPost Terms of Service</a>

### Instruction video

<a href="https://kinescope.io/embed/9z1h5x44N1cnxRaYmfW7rR" target="_blank" rel="noopener noreferrer">Watch the CraftPost setup and usage guide</a>.

## Українська

AI Craft Post — це конектор WordPress для сервісу автоматизації контенту <a href="https://craftpost.net/" target="_blank" rel="noopener noreferrer">CraftPost</a>. Плагін приймає автентифіковані REST API вебхуки, створює та оновлює контент, імпортує зображення, записує підтримувані SEO-метадані, керує FAQ і зв’язує переклади Polylang.

### Можливості

- Створює й оновлює записи та сторінки WordPress.
- Призначає авторів, категорії, позначки, статус публікації та шаблони.
- Імпортує головні й секційні зображення до медіабібліотеки WordPress.
- Зберігає нативні блоки Gutenberg для зображень, галерей, медіа з текстом та обкладинок під час оновлення статей.
- Підтримує метадані Yoast SEO, Rank Math та All in One SEO.
- Зберігає й показує FAQ з необов’язковими структурованими даними FAQPage.
- Підтримує багатомовний контент через Polylang.
- Автентифікує та підписує вхідні запити й захищає їх від повторного відтворення.

### Вимоги

- WordPress 6.2 або новіший.
- PHP 7.4 або новіший.
- Обліковий запис CraftPost і ключ підключення сайту.

### Встановлення

1. Завантажте ZIP-архів релізу.
2. У WordPress відкрийте **Плагіни > Додати новий > Завантажити плагін**.
3. Завантажте та активуйте AI Craft Post.
4. Відкрийте **Інструменти > AI Craft Post**.
5. Додайте WordPress-сайт у CraftPost і вставте створений ключ `aic_live` у налаштування плагіна.
6. Виберіть потрібні додаткові налаштування SEO та FAQ.

Використовуйте HTTPS і для WordPress-сайту, і для облікового запису CraftPost.

### Зовнішній сервіс і конфіденційність

Плагін підключає WordPress до зовнішнього сервісу CraftPost лише після того, як адміністратор налаштує ключ сайту. Автентифіковані запити CraftPost можуть отримувати URL, назву, мову й часовий пояс сайту, версію WordPress, ідентифікатори та ролі облікових записів авторів, публічні типи записів, таксономії, шаблони, розміри зображень, відомості про підтримувані плагіни та наявний контент, вибраний для обробки.

CraftPost може надсилати до WordPress створений або оновлений контент, метадані, переклади, елементи FAQ та URL віддалених зображень. Віддалені зображення завантажуються до медіабібліотеки, тому хост зображення отримує запит від сервера сайту. Повний опис передавання даних наведено у файлі <a href="readme.txt" target="_blank" rel="noopener noreferrer">readme.txt</a>.

- <a href="https://craftpost.net/privacy.html" target="_blank" rel="noopener noreferrer">Політика конфіденційності CraftPost</a>
- <a href="https://craftpost.net/terms.html" target="_blank" rel="noopener noreferrer">Умови використання CraftPost</a>

### Відеоінструкція

<a href="https://kinescope.io/embed/9z1h5x44N1cnxRaYmfW7rR" target="_blank" rel="noopener noreferrer">Переглянути інструкцію з налаштування та використання CraftPost</a>.

## Development and releases

The Git repository is the development source. Approved WordPress.org releases are published separately to the WordPress.org SVN repository.

Run the official Plugin Check before creating a release. Release ZIP files must contain the `ai-craft-post` directory and exclude Git metadata, local documentation, IDE settings, logs, and previous ZIP files.

## License

AI Craft Post is licensed under GPLv2 or later.
