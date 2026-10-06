<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\CreateArticleMessage;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Bundle\CategoryBundle\Entity\Category;
use Sulu\Bundle\ContactBundle\Entity\Account;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Application\Message\CreateSnippetMessage;
use Sulu\Snippet\Application\Message\ModifySnippetAreaMessage;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Creates the snippets, the News and Blog overview pages, their articles and the homepage in every webspace locale.
 *
 * Every resource is created through Sulu's message bus and published, as in sulu/sulu-demo.
 */
class ContentFixtures extends Fixture implements OrderedFixtureInterface
{
    private const WEBSPACE_KEY = 'website';

    private const LOCALE = AppFixtures::LOCALE;

    private const TRANSLATION_LOCALES = ['fr', 'it'];

    private const LOCALES = [self::LOCALE, ...self::TRANSLATION_LOCALES];

    /**
     * @var array<string, mixed>
     */
    private array $texts = [];

    public function __construct(
        #[Autowire(service: 'sulu_message_bus')]
        private readonly MessageBusInterface $messageBus,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly ArticleRepositoryInterface $articleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
    ) {
    }

    public function getOrder(): int
    {
        return \PHP_INT_MAX;
    }

    public function load(ObjectManager $manager): void
    {
        if (0 < $this->articleRepository->countBy()) {
            return;
        }

        $quotes = $this->loadQuoteSnippets();
        $this->loadSettingsSnippet();

        $newsPage = $this->createOverviewPage('news', 'city.jpg');
        $blogPage = $this->createOverviewPage('blog', 'meeting.jpg');

        $news = $this->loadArticles($this->newsArticles(), 'news', $newsPage, $quotes);
        $blog = $this->loadArticles($this->blogArticles(), 'blog', $blogPage, $quotes);

        $this->loadHomepage($blogPage, $news, $blog);
    }

    /**
     * @return list<string> uuids of the quote snippets
     */
    private function loadQuoteSnippets(): array
    {
        $uuids = [];

        foreach ($this->quotes() as $index => [, , $contactIndex]) {
            $contact = AppFixtures::CONTACTS[$contactIndex];
            $authorId = $this->contactId($contact['firstName'], $contact['lastName']);

            $snippet = null;
            foreach (self::LOCALES as $locale) {
                [$title, $quote] = $this->texts($locale)['quotes'][$index];
                $data = [
                    'locale' => $locale,
                    'template' => 'quote',
                    'title' => $title,
                    'quote' => $quote,
                    'author' => $authorId,
                ];

                if (!$snippet instanceof SnippetInterface) {
                    /** @var SnippetInterface $snippet */
                    $snippet = $this->dispatch(new CreateSnippetMessage($data));
                } else {
                    $this->dispatch(new ModifySnippetMessage(['uuid' => $snippet->getUuid()], $data));
                }

                $this->publishSnippet($snippet, $locale);
            }

            $uuids[] = $snippet->getUuid();
        }

        return $uuids;
    }

    private function loadSettingsSnippet(): void
    {
        $snippet = null;
        foreach (self::LOCALES as $locale) {
            $data = [
                'locale' => $locale,
                'template' => 'settings',
                'title' => $this->texts($locale)['settings'],
                'account' => $this->accountId(),
            ];

            if (!$snippet instanceof SnippetInterface) {
                /** @var SnippetInterface $snippet */
                $snippet = $this->dispatch(new CreateSnippetMessage($data));
            } else {
                $this->dispatch(new ModifySnippetMessage(['uuid' => $snippet->getUuid()], $data));
            }

            $this->publishSnippet($snippet, $locale);

            $this->dispatch(new ModifySnippetAreaMessage([
                'webspaceKey' => self::WEBSPACE_KEY,
                'areaKey' => 'settings',
                'snippetIdentifier' => ['uuid' => $snippet->getUuid()],
                'locale' => $locale,
            ]));
        }
    }

    private function createOverviewPage(string $categoryKey, string $image): PageInterface
    {
        /** @var PageInterface $page */
        $page = $this->dispatch(new CreatePageMessage(
            self::WEBSPACE_KEY,
            $this->homepage()->getUuid(),
            $this->overviewPageData(self::LOCALE, $categoryKey, $image),
        ));
        $this->publishPage($page, self::LOCALE);

        foreach (self::TRANSLATION_LOCALES as $locale) {
            $this->dispatch(new ModifyPageMessage(['uuid' => $page->getUuid()], $this->overviewPageData($locale, $categoryKey, $image)));
            $this->publishPage($page, $locale);
        }

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewPageData(string $locale, string $categoryKey, string $image): array
    {
        ['title' => $title, 'url' => $url, 'intro' => $intro] = $this->texts($locale)['pages'][$categoryKey];

        return [
            'locale' => $locale,
            'template' => 'articles_overview',
            'title' => $title,
            'url' => $url,
            'navigationContexts' => ['main'],
            'intro' => '<p>' . $intro . '</p>',
            'articles' => [
                'categories' => [$this->categoryId($categoryKey)],
                'categoryOperator' => 'OR',
                'sortBy' => 'authored',
                'sortMethod' => 'desc',
            ],
            'excerpt' => [
                'title' => $title,
                'description' => '<p>' . $intro . '</p>',
                'image' => ['id' => $this->mediaId($image)],
            ],
            'excerptCategories' => [$this->categoryId($categoryKey)],
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $articles title, lead, image
     * @param list<string> $quotes
     *
     * @return list<ArticleInterface>
     */
    private function loadArticles(array $articles, string $template, PageInterface $parentPage, array $quotes): array
    {
        $result = [];

        foreach ($articles as $index => [, , $image]) {
            /** @var ArticleInterface $article */
            $article = $this->dispatch(new CreateArticleMessage(
                $this->articleData(self::LOCALE, $template, $index, $image, $parentPage, $quotes),
            ));
            $this->publishArticle($article, self::LOCALE);

            foreach (self::TRANSLATION_LOCALES as $locale) {
                $this->dispatch(new ModifyArticleMessage(
                    ['uuid' => $article->getUuid()],
                    $this->articleData($locale, $template, $index, $image, $parentPage, $quotes),
                ));
                $this->publishArticle($article, $locale);
            }

            $result[] = $article;
        }

        return $result;
    }

    /**
     * @param list<string> $quotes
     *
     * @return array<string, mixed>
     */
    private function articleData(string $locale, string $template, int $index, string $image, PageInterface $parentPage, array $quotes): array
    {
        $texts = $this->texts($locale);
        [$title, $lead] = ('news' === $template ? $texts['news'] : $texts['blog'])[$index];
        $paragraphs = $texts['paragraphs'][$template];
        $imageId = $this->mediaId($image);
        $contact = AppFixtures::CONTACTS[$index % \count(AppFixtures::CONTACTS)];

        return [
            'locale' => $locale,
            'template' => $template,
            'title' => $title,
            'url' => [
                'page' => ['uuid' => $parentPage->getUuid(), 'path' => $texts['pages'][$template]['url']],
                'suffix' => '/' . $this->slugify($title),
            ],
            'lead' => $lead,
            'headerImage' => ['id' => $imageId],
            'intro' => $paragraphs[($index + 2) % \count($paragraphs)],
            'sections' => $this->withBlockIds([
                [
                    'type' => 'section',
                    'headline' => $texts['sections']['story'],
                    'blocks' => [
                        [
                            'type' => 'text_image',
                            'text' => '<p>' . $lead . '</p><p>' . $paragraphs[$index % \count($paragraphs)] . '</p>',
                            'image' => ['id' => $imageId, 'displayOption' => 0 === $index % 2 ? 'right' : 'left'],
                        ],
                        [
                            'type' => 'quote',
                            'quote' => $quotes[$index % \count($quotes)],
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'headline' => $texts['sections']['next'],
                    'blocks' => [
                        [
                            'type' => 'text_image',
                            'text' => '<p>' . $paragraphs[($index + 1) % \count($paragraphs)] . '</p>',
                        ],
                        ...('news' === $template ? [[
                            'type' => 'downloads',
                            'files' => ['ids' => [$this->mediaId('release-notes.pdf')]],
                        ]] : []),
                    ],
                ],
            ]),
            'excerpt' => [
                'title' => $title,
                'description' => '<p>' . $lead . '</p>',
                'image' => ['id' => $imageId],
            ],
            'excerptCategories' => [$this->categoryId($template)],
            'author' => $this->contactId($contact['firstName'], $contact['lastName']),
            'authored' => (new \DateTimeImmutable('2026-09-30 09:00'))->modify(\sprintf('-%d days', $index * 3))->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<ArticleInterface> $news
     * @param list<ArticleInterface> $blog
     */
    private function loadHomepage(PageInterface $blogPage, array $news, array $blog): void
    {
        $homepageUuid = $this->homepage()->getUuid();

        foreach (self::LOCALES as $locale) {
            $texts = $this->texts($locale)['homepage'];

            // sulu:page:initialize leaves a second draft dimension content behind that has no route,
            // without clearing the identity map the modify picks that one up and inserts a second "/" route
            $this->entityManager->clear();

            $this->dispatch(new ModifyPageMessage(['uuid' => $homepageUuid], [
                'locale' => $locale,
                'template' => 'homepage',
                'title' => $texts['title'],
                'url' => '/',
                'sections' => $this->withBlockIds([
                    [
                        'type' => 'section',
                        'headline' => $texts['featured'],
                        'blocks' => [
                            [
                                'type' => 'teasers_manual',
                                'headline' => $texts['editorsPicks'],
                                'teasers' => [
                                    'items' => [
                                        ['id' => $news[0]->getUuid(), 'type' => 'articles'],
                                        ['id' => $blog[0]->getUuid(), 'type' => 'articles'],
                                        ['id' => $blogPage->getUuid(), 'type' => 'pages'],
                                    ],
                                    'presentAs' => null,
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'headline' => $texts['latest'],
                        'blocks' => [
                            [
                                'type' => 'teasers_automatic',
                                'headline' => $texts['news'],
                                'articles' => [
                                    'categories' => [$this->categoryId('news')],
                                    'sortBy' => 'authored',
                                    'sortMethod' => 'desc',
                                    'limitResult' => 3,
                                ],
                            ],
                            [
                                'type' => 'teasers_automatic',
                                'headline' => $texts['blog'],
                                'articles' => [
                                    'categories' => [$this->categoryId('blog')],
                                    'sortBy' => 'authored',
                                    'sortMethod' => 'desc',
                                    'limitResult' => 3,
                                ],
                            ],
                        ],
                    ],
                ]),
            ]));

            $this->publishPage($homepageUuid, $locale);
        }
    }

    /**
     * Gives every block and nested block the stable "_id" the admin would generate,
     * so the preview can link to fixture content without opening and saving it first.
     *
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private function withBlockIds(array $blocks): array
    {
        return \array_map(function (array $block): array {
            if (\is_array($block['blocks'] ?? null)) {
                /** @var list<array<string, mixed>> $nestedBlocks */
                $nestedBlocks = $block['blocks'];
                $block['blocks'] = $this->withBlockIds($nestedBlocks);
            }

            return ['_id' => $this->blockIdGenerator->generateId(), ...$block];
        }, $blocks);
    }

    private function homepage(): PageInterface
    {
        return $this->pageRepository->getOneBy([
            'webspaceKey' => self::WEBSPACE_KEY,
            'parentId' => null,
        ]);
    }

    private function publishSnippet(SnippetInterface $snippet, string $locale): void
    {
        $this->dispatch(new ApplyWorkflowTransitionSnippetMessage(
            ['uuid' => $snippet->getUuid()],
            $locale,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        ));
    }

    private function publishPage(PageInterface|string $page, string $locale): void
    {
        $this->dispatch(new ApplyWorkflowTransitionPageMessage(
            ['uuid' => $page instanceof PageInterface ? $page->getUuid() : $page],
            $locale,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        ));
    }

    private function publishArticle(ArticleInterface $article, string $locale): void
    {
        $this->dispatch(new ApplyWorkflowTransitionArticleMessage(
            ['uuid' => $article->getUuid()],
            $locale,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        ));
    }

    private function dispatch(object $message): mixed
    {
        $envelope = $this->messageBus->dispatch(new Envelope($message, [new EnableFlushStamp()]));

        return $envelope->last(HandledStamp::class)?->getResult();
    }

    private function slugify(string $value): string
    {
        return (new AsciiSlugger())->slug($value)->lower()->toString();
    }

    private function mediaId(string $fileName): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->from(Media::class, 'media')
            ->select('media.id')
            ->innerJoin('media.files', 'file')
            ->innerJoin('file.fileVersions', 'fileVersion')
            ->where('fileVersion.name = :name')
            ->setParameter('name', $fileName)
            ->setMaxResults(1)
            ->getQuery()->getSingleScalarResult();
    }

    private function contactId(string $firstName, string $lastName): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->from(Contact::class, 'contact')
            ->select('contact.id')
            ->where('contact.firstName = :firstName AND contact.lastName = :lastName')
            ->setParameter('firstName', $firstName)
            ->setParameter('lastName', $lastName)
            ->setMaxResults(1)
            ->getQuery()->getSingleScalarResult();
    }

    private function accountId(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->from(Account::class, 'account')
            ->select('account.id')
            ->where('account.name = :name')
            ->setParameter('name', AppFixtures::ACCOUNT_NAME)
            ->setMaxResults(1)
            ->getQuery()->getSingleScalarResult();
    }

    private function categoryId(string $key): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->from(Category::class, 'category')
            ->select('category.id')
            ->where('category.key = :key')
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * English texts live in this class, the other locales in translations/<locale>.json with the same shape.
     *
     * @return array{
     *     settings: string,
     *     homepage: array{title: string, featured: string, editorsPicks: string, latest: string, news: string, blog: string},
     *     pages: array<string, array{title: string, url: string, intro: string}>,
     *     sections: array{story: string, next: string},
     *     quotes: list<array{0: string, 1: string}>,
     *     paragraphs: array<string, list<string>>,
     *     news: list<array{0: string, 1: string}>,
     *     blog: list<array{0: string, 1: string}>,
     * }
     */
    private function texts(string $locale): array
    {
        if (!isset($this->texts[$locale])) {
            $this->texts[$locale] = self::LOCALE === $locale ? [
                'settings' => 'Website settings',
                'homepage' => [
                    'title' => 'Homepage',
                    'featured' => 'Featured',
                    'editorsPicks' => 'Editor\'s picks',
                    'latest' => 'Latest updates',
                    'news' => 'News',
                    'blog' => 'From the blog',
                ],
                'pages' => [
                    'news' => ['title' => 'News', 'url' => '/news', 'intro' => 'The latest announcements, releases and events.'],
                    'blog' => ['title' => 'Blog', 'url' => '/blog', 'intro' => 'Stories, guides and opinions from our team.'],
                ],
                'sections' => ['story' => 'The story', 'next' => 'What comes next'],
                'quotes' => \array_map(static fn (array $quote): array => [$quote[0], $quote[1]], $this->quotes()),
                'paragraphs' => $this->paragraphs(),
                'news' => \array_map(static fn (array $article): array => [$article[0], $article[1]], $this->newsArticles()),
                'blog' => \array_map(static fn (array $article): array => [$article[0], $article[1]], $this->blogArticles()),
            ] : \json_decode((string) \file_get_contents(__DIR__ . '/translations/' . $locale . '.json'), true, flags: \JSON_THROW_ON_ERROR);
        }

        /** @var array{settings: string, homepage: array{title: string, featured: string, editorsPicks: string, latest: string, news: string, blog: string}, pages: array<string, array{title: string, url: string, intro: string}>, sections: array{story: string, next: string}, quotes: list<array{0: string, 1: string}>, paragraphs: array<string, list<string>>, news: list<array{0: string, 1: string}>, blog: list<array{0: string, 1: string}>} */
        return $this->texts[$locale];
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}> title, quote, index into AppFixtures::CONTACTS
     */
    private function quotes(): array
    {
        return [
            ['Ship small, ship often', 'The best releases are the boring ones: small, frequent and fully reversible.', 0],
            ['Content first', 'Editors do not want more fields. They want the right fields in the right order.', 1],
            ['Performance is a feature', 'Every millisecond we save on a page is a millisecond our readers spend on content.', 2],
            ['Open by default', 'Open source is not a licence, it is a habit of working in public and listening.', 3],
            ['Design systems', 'A good block library lets editors build pages we never had to design.', 1],
            ['Community', 'The questions people ask in our community shape our roadmap more than any meeting.', 0],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function paragraphs(): array
    {
        return [
            'news' => [
                'The change is rolling out to all customers over the coming weeks. Existing projects keep working without any migration, and the release notes list every step for teams that want to adopt the new features right away.',
                'We worked closely with partner agencies during the beta phase. Their feedback reshaped two of the features and removed one entirely, which made the final release smaller and easier to explain.',
                'Teams who want to try it can follow the upgrade guide or join one of the open office hours, where the core team answers questions live and collects ideas for the next iteration.',
                'The full schedule, recordings and slides will be published on this page. Subscribe to the newsletter to get notified as soon as new material is available.',
            ],
            'blog' => [
                'We started with a single question: what does an editor need to see on the screen to finish a page without asking a developer? Answering it honestly removed half of the fields we had planned.',
                'The approach is simple to describe and hard to do: keep templates small, name every field after what it means to the reader, and let blocks carry the variation instead of new templates.',
                'In practice this meant pairing developers and editors for a week. The editors brought real content, the developers brought the templates, and every mismatch became a ticket the same afternoon.',
                'None of this needs a large team. A clear structure, a few reusable blocks and a habit of reviewing real pages together carry most projects a long way.',
            ],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> title, lead, image file name
     */
    private function newsArticles(): array
    {
        return [
            ['Sulu 3.1 is released', 'The new minor release brings faster previews, nested global blocks and a reworked snippet area editor.', 'city.jpg'],
            ['Sulu Touch 2026 schedule announced', 'Two days of talks and workshops around content modelling, performance and the Sulu ecosystem.', 'meeting.jpg'],
            ['New partner programme launches', 'Agencies building with Sulu now get early access to releases, training and a direct line to the core team.', 'band.jpg'],
            ['Security release for 3.0 and 3.1', 'A coordinated release fixes an issue in the media upload validation. Updating is recommended for all projects.', 'path.jpg'],
            ['Documentation gets a new home', 'The documentation moves to a new platform with versioned guides, full text search and copyable examples.', 'river.jpg'],
            ['Article bundle joins the core', 'Articles are now part of sulu/sulu and share the content storage with pages and snippets.', 'train.jpg'],
            ['Community call recap: September', 'This month we discussed the block settings API, the upgrade tooling and the plans for the next minor.', 'sound.jpg'],
            ['Sulu wins open source award', 'The jury highlighted the project\'s focus on editors and the long term support of its major versions.', 'isle.jpg'],
            ['Hacktoberfest with Sulu', 'Issues labelled for first time contributors are ready, and the core team offers reviews within 48 hours.', 'forest.jpg'],
            ['MCP server for Sulu in beta', 'AI assistants can now read and update pages, articles and snippets through a documented MCP interface.', 'mic.jpg'],
            ['New office in Vienna', 'The team grows and opens a second office to be closer to customers and partners in eastern Austria.', 'way.jpg'],
            ['Long term support for 2.6 extended', 'Projects that cannot upgrade yet get security fixes for another twelve months.', 'roadtrip.jpg'],
            ['Search integration with SEAL', 'The search layer now supports Loupe, Meilisearch and Elasticsearch through a single adapter interface.', 'city.jpg'],
            ['Workshop dates for spring', 'Hands-on workshops on templates, blocks and upgrades are now open for registration.', 'meeting.jpg'],
            ['Admin UI accessibility audit completed', 'An external audit reviewed the admin interface, and the first set of improvements is already merged.', 'river.jpg'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> title, lead, image file name
     */
    private function blogArticles(): array
    {
        return [
            ['Why we model content with sections', 'Sections give editors a page structure they understand and give developers a single place to style it.', 'forest.jpg'],
            ['Global blocks in practice', 'Defining a block once and reusing it across templates keeps forms consistent and templates short.', 'path.jpg'],
            ['Snippet areas explained', 'A snippet area turns a snippet into website wide settings that editors can change without a deployment.', 'isle.jpg'],
            ['Five rules for clean templates', 'Short templates, meaningful names and blocks for variation make a project easy to maintain for years.', 'way.jpg'],
            ['From 2.6 to 3.1: an upgrade diary', 'We upgraded a mid-sized customer project and wrote down every step, surprise and shortcut.', 'train.jpg'],
            ['Teasers: manual or automatic?', 'When an editor should pick content by hand and when a smart content list does the job better.', 'band.jpg'],
            ['Designing for editors first', 'The admin form is the product for editors, so we design it with the same care as the website.', 'meeting.jpg'],
            ['Image formats that scale', 'A handful of well named formats covers most websites and keeps the media cache under control.', 'river.jpg'],
            ['Writing fixtures that tell a story', 'Good fixtures show how the website is meant to look and make every review screenshot useful.', 'sound.jpg'],
            ['The quiet power of categories', 'Categories are the simplest way to structure articles across templates and to filter lists on the website.', 'roadtrip.jpg'],
            ['What we learned from 100 launches', 'Patterns that kept projects on time, and the few decisions that always came back to bite us.', 'city.jpg'],
            ['Performance budgets for CMS sites', 'Setting a budget early keeps pages fast long after the first launch, even with growing content.', 'mic.jpg'],
            ['Contacts and accounts as content', 'Using contacts and accounts in templates avoids duplicating names, roles and logos across pages.', 'forest.jpg'],
            ['Pair reviewing with editors', 'Reviewing real pages together with editors finds more issues than any automated test suite.', 'path.jpg'],
            ['A styleguide in a single CSS file', 'For small and medium sites a plain stylesheet with custom properties is often all you need.', 'way.jpg'],
        ];
    }
}
