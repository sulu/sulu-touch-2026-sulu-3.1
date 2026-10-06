<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Sulu\Bundle\AdminBundle\Admin\AdminPool;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\SecurityBundle\Entity\Permission;
use Sulu\Bundle\SecurityBundle\Entity\Role;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Creates the "user" login (password "user") next to the "admin" login that sulu:build creates.
 *
 * Its "Editor" role gets every permission except publishing pages and articles, so the user has
 * to request a review before anything goes live.
 */
class UserFixtures extends Fixture implements OrderedFixtureInterface
{
    final public const USERNAME = 'user';

    private const ROLE_NAME = 'Editor';

    private const ALL_PERMISSIONS = 255;

    private const LIVE_PERMISSION = 2;

    private const PAGES_CONTEXT = 'sulu.webspaces.website';

    /**
     * Prefix, so the contexts of the article template groups are covered as well.
     */
    private const ARTICLES_CONTEXT_PREFIX = 'sulu.article.articles';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        // the admin pool only exists in the admin context, fixtures are loaded with bin/adminconsole
        #[Autowire(service: 'sulu_admin.admin_pool')]
        private readonly ?AdminPool $adminPool = null,
    ) {
    }

    public function getOrder(): int
    {
        return \PHP_INT_MAX - 2;
    }

    public function load(ObjectManager $manager): void
    {
        if ($manager->getRepository(User::class)->findOneBy(['username' => self::USERNAME]) instanceof User) {
            return;
        }

        if (!$this->adminPool instanceof AdminPool) {
            throw new \LogicException('Load the fixtures with "bin/adminconsole doctrine:fixtures:load".');
        }

        $role = new Role();
        $role->setName(self::ROLE_NAME);
        $role->setSystem('Sulu');

        foreach ($this->securityContexts() as $context) {
            $permission = new Permission();
            $permission->setRole($role);
            $permission->setContext($context);
            $permission->setPermissions($this->canPublish($context)
                ? self::ALL_PERMISSIONS
                : self::ALL_PERMISSIONS & ~self::LIVE_PERMISSION);
            $role->addPermission($permission);
        }

        $contact = new Contact();
        $contact->setFirstName('Eddie');
        $contact->setLastName('Editor');

        $user = new User();
        $user->setUsername(self::USERNAME);
        $user->setEmail('user@sulu-touch.example');
        $user->setLocale(AppFixtures::LOCALE);
        $user->setSalt(\base64_encode(\random_bytes(32)));
        $user->setContact($contact);
        $user->setPassword($this->passwordHasherFactory->getPasswordHasher($user)->hash('user'));

        $userRole = new UserRole();
        $userRole->setRole($role);
        $userRole->setUser($user);
        $userRole->setLocale((string) \json_encode(['en', 'fr', 'it']));

        $manager->persist($role);
        $manager->persist($contact);
        $manager->persist($user);
        $manager->persist($userRole);
        $manager->flush();
    }

    private function canPublish(string $context): bool
    {
        return self::PAGES_CONTEXT !== $context && !\str_starts_with($context, self::ARTICLES_CONTEXT_PREFIX);
    }

    /**
     * @return list<string>
     */
    private function securityContexts(): array
    {
        \assert($this->adminPool instanceof AdminPool);

        $contexts = [];
        foreach ($this->adminPool->getSecurityContexts()['Sulu'] ?? [] as $sectionContexts) {
            foreach (\array_keys($sectionContexts) as $context) {
                $contexts[] = (string) $context;
            }
        }

        return $contexts;
    }
}
