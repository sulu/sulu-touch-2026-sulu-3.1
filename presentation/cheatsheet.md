## 1. Symfony 8 (`step-00-symfony-8`)

- `composer.json`: `php` to `^8.4`, all `symfony/*` from `^7.4` to `^8.1`, `extra.symfony.require: "8.1.*"`
- `doctrine/doctrine-bundle: ^3.3`, `scheb/2fa-*: ^8.1`
- `composer update -W`
- `config/packages/doctrine.yaml`: remove `use_savepoints`, `auto_generate_proxy_classes`, `enable_lazy_ghost_objects`, `report_fields_where_declared`, `controller_resolver.auto_mapping`, prod `proxy_dir`
- Commit the generated `config/reference.php`
- Show: profiler toolbar says Symfony 8.1, website still works

## 2. Snippets: template groups (`step-01-snippets`)

```xml
<!-- config/templates/snippets/quote.xml -->
<group>content</group>
<!-- config/templates/snippets/settings.xml -->
<group>configuration</group>
```

```json
// translations/admin.en.json (de: "Inhalt", "Konfiguration")
"sulu_admin.template_group.content": "Content",
"sulu_admin.template_group.configuration": "Configuration"
```

- `bin/adminconsole cache:clear`
- **Grant the new permissions**: Settings > User roles > `User` (admin) and `Editor` (user) > `sulu.snippet.snippets_content` and `sulu.snippet.snippets_configuration`, otherwise the Snippets menu disappears
- Show: Snippets has two tabs, each add form only offers its own template
- Snippet shadows: mention (needs a second content locale on a snippet)

## 3. Text editor configs (`step-02-text-editor`)

```yaml
# config/packages/sulu_admin.yaml
sulu_admin:
    text_editor:
        configs:
            intro:
                enter_mode: br
                tags:
                    strong: true
                    i: true
                    a: true
```

```xml
<!-- intro property in articles/blog.xml, articles/news.xml, pages/articles_overview.xml -->
<params>
    <param name="config" value="intro"/>
</params>
```

- `bin/adminconsole cache:clear`
- Fixtures: write the overview intro without `<p>` (`'intro' => $intro`), it is a `br` field now
- Show: intro toolbar only bold, italic, link vs. full toolbar of the text block
- Lang attributes: mention `attributes: { lang: true }` adds the language button

## 4. Media (`step-03-media`)

```yaml
# config/packages/sulu_media.yaml
sulu_media:
    content_locales: [en, fr, it]
    format_manager:
        parallel_image_generation:
            limit: 2
```

```bash
composer require symfony/semaphore:^8.1
```

```yaml
# config/packages/semaphore.yaml (literal, not via env var)
framework:
    semaphore: 'lock://'
```

```twig
{# templates/organisms/blocks/downloads.html.twig, inside the file loop #}
{% if file.contentLocales|default([]) is not empty %}
    <span class="downloads__languages">{{ file.contentLocales|map(language => language|upper)|join(', ') }}</span>
{% endif %}
```

- Fixtures: `createMedia()` gets `array $contentLocales = []` and `->setContentLocales($contentLocales)`, the PDF is created with `['en', 'fr']`
- If table `me_file_version_content_languages` is missing: `bin/console doctrine:migrations:execute --up 'Sulu\Bundle\MediaBundle\Migrations\Version20261005000000'`
- Show admin: Media > Release notes PDF > set content locales EN, FR; media list filter by language
- Show website: `/news/sulu-3-1-is-released` downloads block shows `EN, FR`
- AI disclosure (2.6): Media detail > Origin / AI disclosure fields

## 5. Content workflow (`step-04-content-workflow`)

```yaml
# config/packages/sulu_content.yaml
sulu_content:
    request_workflows:
        default:
            resources: ['articles']
            required_user_approvals: 1
            pre_validators:
                seo_required:
                    fields: ['title', 'description']
```

```yaml
# config/routes/sulu_admin.yaml
sulu_content_api:
    resource: "@SuluContentBundle/config/routing_admin_api.yaml"
    prefix: /admin/api
```

```xml
<!-- config/templates/articles/blog.xml: blog articles skip the review -->
<tag name="sulu_content.request_workflow" workflow="none"/>
```

```bash
bin/console doctrine:migrations:execute --up 'Sulu\Content\Migrations\Version20260903120000'
```

- Logins: `user` / `user` cannot publish pages and articles, `admin` / `admin` can; both roles have the new `review` permission, nobody approves their own request
- Demo flow on a **news** article:
  1. As `user`: save without SEO title/description and request publish: blocked by the pre-validator
  2. Fill SEO, request publish: status dot turns yellow, form is locked
  3. As `admin`: Review, comment, approve
  4. As `user`: publish
- Blog article: publishes directly

## 6. Notifications (`step-05-notifications`)

```bash
composer require symfony/notifier symfony/fake-chat-notifier
```

```dotenv
# .env
FAKE_CHAT_DSN=fakechat+email://default?to=team@sulu-touch.example&from=sulu@sulu-touch.example
```

```php
// config/bundles.php
Sulu\Notifier\Infrastructure\Symfony\HttpKernel\SuluNotifierBundle::class => ['all' => true],
```

```yaml
# config/packages/sulu_notifier.yaml
sulu_notifier:
    channels:
        'chat/fakechat+email':
            - Sulu\Page\Domain\Event\PageWorkflowTransitionAppliedEvent
            - Sulu\Article\Domain\Event\ArticleWorkflowTransitionAppliedEvent
```

- Show: publish a page in the admin, open Mailpit at http://localhost:8025, click the deep link back into the admin
- CLI/fixture publishes send the mail without the link; a fixture reload sends about 100 mails (every publish in 3 locales)

## 7. Preview to block (`step-06-preview-to-block`)

```twig
{# templates/organisms/sections.html.twig #}
<section {{ sulu_preview_deep_link(section._id|default(null)) }} class="section">

{# root element of every templates/organisms/blocks/*.html.twig #}
<div {{ sulu_preview_deep_link(content._id|default(null)) }} class="block ...">
```

- Fixtures already store block `_id`s, otherwise ids appear after the first save in the admin
- Show: open a news article, hover a block in the preview, click the target: form scrolls to the block and opens it
- Live site has no attributes (only rendered in the admin preview)

## If something breaks

- `bin/adminconsole cache:clear && bin/console cache:clear`
- Snippets menu missing: grant the snippet group permissions (step 2)
- Review buttons missing: grant the `review` permission (step 5)
- Wrong data: `bin/adminconsole doctrine:fixtures:load --append` only fills an empty DB, use `sulu:build dev --destroy` for a reset
- Admin JS looks old: `cd assets/admin && npm install && npm run build`
