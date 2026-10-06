<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Sulu\Bundle\CategoryBundle\Entity\Category;
use Sulu\Bundle\CategoryBundle\Entity\CategoryTranslation;
use Sulu\Bundle\ContactBundle\Entity\Account;
use Sulu\Bundle\ContactBundle\Entity\AccountInterface;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\ContactBundle\Entity\ContactInterface;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionInterface;
use Sulu\Bundle\MediaBundle\Entity\CollectionMeta;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\File;
use Sulu\Bundle\MediaBundle\Entity\FileVersion;
use Sulu\Bundle\MediaBundle\Entity\FileVersionMeta;
use Sulu\Bundle\MediaBundle\Entity\Media;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Storage\StorageInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Creates the media, contacts, account and categories that ContentFixtures references by name.
 *
 * Load with `bin/adminconsole doctrine:fixtures:load --append`: a purge would also delete the
 * admin user and the collection and address types that Sulu's own fixtures create.
 */
class AppFixtures extends Fixture implements OrderedFixtureInterface
{
    final public const LOCALE = 'en';

    final public const ACCOUNT_NAME = 'Sulu Touch GmbH';

    /**
     * Without template groups a smart content cannot filter by template, so news and blog
     * articles are told apart by these category keys.
     *
     * @var array<string, array<string, string>> titles by category key and locale
     */
    final public const CATEGORIES = [
        'news' => ['en' => 'News', 'fr' => 'Actualités', 'it' => 'Notizie'],
        'blog' => ['en' => 'Blog', 'fr' => 'Blog', 'it' => 'Blog'],
    ];

    /**
     * @var list<array{firstName: string, lastName: string}>
     */
    final public const CONTACTS = [
        ['firstName' => 'Mara', 'lastName' => 'Lindqvist'],
        ['firstName' => 'Jonas', 'lastName' => 'Feldmann'],
        ['firstName' => 'Aiko', 'lastName' => 'Tanaka'],
        ['firstName' => 'Daniel', 'lastName' => 'Okafor'],
    ];

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function getOrder(): int
    {
        // ContentFixtures references this media, these contacts and this account
        return \PHP_INT_MAX - 1;
    }

    public function load(ObjectManager $manager): void
    {
        if ($manager->getRepository(Account::class)->findOneBy(['name' => self::ACCOUNT_NAME]) instanceof Account) {
            return;
        }

        $collection = $this->createCollection($manager, 'Content Images');

        foreach ((new Finder())->files()->in(__DIR__ . '/images')->sortByName() as $file) {
            $this->createMedia($manager, $collection, $file, MediaInterface::TYPE_IMAGE, 'image/jpeg');
        }

        foreach ((new Finder())->files()->in(__DIR__ . '/documents')->sortByName() as $file) {
            $this->createMedia($manager, $collection, $file, MediaInterface::TYPE_DOCUMENT, 'application/pdf');
        }

        foreach (self::CONTACTS as $contact) {
            $this->createContact($manager, $contact['firstName'], $contact['lastName']);
        }

        $this->createAccount($manager);

        foreach (self::CATEGORIES as $key => $titles) {
            $this->createCategory($manager, $key, $titles);
        }

        $manager->flush();
    }

    private function createCollection(ObjectManager $manager, string $title): CollectionInterface
    {
        $collectionType = $manager->getRepository(CollectionType::class)->find(1);
        if (!$collectionType instanceof CollectionType) {
            throw new \RuntimeException('CollectionType "1" not found. Have you loaded the Sulu fixtures?');
        }

        $collection = new Collection();
        $collection->setType($collectionType);

        $meta = new CollectionMeta();
        $meta->setLocale(self::LOCALE);
        $meta->setTitle($title);
        $meta->setCollection($collection);

        $collection->addMeta($meta);
        $collection->setDefaultMeta($meta);

        $manager->persist($collection);
        $manager->persist($meta);

        return $collection;
    }

    private function createMedia(
        ObjectManager $manager,
        CollectionInterface $collection,
        SplFileInfo $fileInfo,
        string $type,
        string $mimeType,
    ): MediaInterface {
        $fileName = $fileInfo->getBasename();
        $storageOptions = $this->storage->save($fileInfo->getPathname(), $fileName);

        $media = new Media();
        $file = new File();
        $file->setVersion(1)
            ->setMedia($media);

        $media->addFile($file)
            ->setType($type)
            ->setCollection($collection);

        $fileVersion = new FileVersion();
        $fileVersion->setVersion($file->getVersion())
            ->setSize((int) $fileInfo->getSize())
            ->setName($fileName)
            ->setStorageOptions($storageOptions)
            ->setMimeType($mimeType)
            ->setFile($file);

        $file->addFileVersion($fileVersion);

        $fileVersionMeta = new FileVersionMeta();
        $fileVersionMeta->setTitle(\ucfirst(\str_replace('-', ' ', $fileInfo->getBasename('.' . $fileInfo->getExtension()))))
            ->setDescription('')
            ->setLocale(self::LOCALE)
            ->setFileVersion($fileVersion);

        $fileVersion->addMeta($fileVersionMeta)
            ->setDefaultMeta($fileVersionMeta);

        $manager->persist($fileVersionMeta);
        $manager->persist($fileVersion);
        $manager->persist($media);

        return $media;
    }

    private function createContact(ObjectManager $manager, string $firstName, string $lastName): ContactInterface
    {
        $contact = new Contact();
        $contact->setFirstName($firstName);
        $contact->setLastName($lastName);

        $manager->persist($contact);

        return $contact;
    }

    private function createAccount(ObjectManager $manager): AccountInterface
    {
        $account = new Account();
        $account->setName(self::ACCOUNT_NAME);
        $account->setMainEmail('hello@sulu-touch.example');
        $account->setMainPhone('+43 5572 000000');
        $account->setMainUrl('https://sulu.io');

        $manager->persist($account);

        return $account;
    }

    /**
     * @param array<string, string> $titles by locale
     */
    private function createCategory(ObjectManager $manager, string $key, array $titles): Category
    {
        $category = new Category();
        $category->setKey($key);
        $category->setDefaultLocale(self::LOCALE);

        foreach ($titles as $locale => $title) {
            $translation = new CategoryTranslation();
            $translation->setLocale($locale);
            $translation->setTranslation($title);
            $translation->setCategory($category);
            $category->addTranslation($translation);

            $manager->persist($translation);
        }

        $manager->persist($category);

        return $category;
    }
}
