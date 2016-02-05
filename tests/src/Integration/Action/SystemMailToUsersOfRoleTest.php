<?php

/**
 * @file
 * Contains \Drupal\Tests\rules\Integration\Action\SystemMailToUsersOfRoleTest.
 */

namespace Drupal\Tests\rules\Integration\Action;

use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Tests\rules\Integration\RulesEntityIntegrationTestBase;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Psr\Log\LoggerInterface;


/**
 * @coversDefaultClass \Drupal\rules\Plugin\RulesAction\SystemMailToUsersOfRole
 * @group rules_actions
 */
class SystemMailToUsersOfRoleTest extends RulesEntityIntegrationTestBase {

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * @var \Drupal\user\UserStorage
   */
  protected $userStorage;

  /**
   * @var \Drupal\user\Entity\Role[]
   */
  protected $roles;

  /**
   * @var \Drupal\user\Entity\User[]
   */
  protected $users;

  /**
   * The action to be tested.
   *
   * @var \Drupal\rules\Plugin\RulesAction\SystemMailToUsersOfRole
   */
  protected $action;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->enableModule('user');
    // @todo why is that line needed? Please add a comment.
    $this->namespaces[] = '';

    // Create two user roles and one user for each.
    $uid = 1;
    foreach (['administrator', 'editor'] as $role_name) {

      // Mock the role entity.
      $roleEntity = $this->prophesize(RoleInterface::class);
      $roleEntity->id()
        ->willReturn($role_name);
      $this->roles[] = $roleEntity->reveal();

      // Mock the user entity.
      $user = $this->prophesizeEntity(UserInterface::class);
      $user->id()->willReturn($uid);

      $userEntityType = $this->prophesize(EntityTypeInterface::class);
      $user->getEntityType()
        ->willReturn($userEntityType->reveal());

      $user->getPreferredLangcode()
        ->willReturn(LanguageInterface::LANGCODE_SITE_DEFAULT);

      $this->users[$uid] = $user->reveal();
      $uid++;
    }

    $this->logger = $this->prophesize(LoggerInterface::class);
    $this->mailManager = $this->prophesize(MailManagerInterface::class);
    $this->userStorage = $this->prophesize(UserStorageInterface::class);


    // Mocked entityManager.
    $this->entityManager = $this->prophesize(EntityManagerInterface::class);

    $this->entityManager->getStorage()
      ->willReturn($this->userStorage->reveal());

    $this->entityManager->createInstance()
      ->willReturn($this->userStorage->reveal());

    // Prepare a user entity type instance.
    $entityType = reset($this->users)->getEntityType();
    $this->entityManager->getDefinitions()
      ->willReturn($entityType);

    $this->container->set('logger.factory', $this->logger->reveal());
    $this->container->set('plugin.manager.mail', $this->mailManager->reveal());
    $config = [
      'system.site' => [
        'mail' => 'admin@example.com',
      ],
    ];
    $this->container->set('config.factory', $this->getConfigFactoryStub($config));
    $this->container->set('entity.manager', $this->entityManager->reveal());

    $this->action = $this->actionManager->createInstance('rules_mail_to_users_of_role');
  }

  /**
   * Tests the summary.
   *
   * @covers ::summary
   */
  public function testSummary() {
    $this->assertEquals('Mail to users of a role', $this->action->summary());
  }

  /**
   * Tests sending a mail to one or two roles.
   *
   * @param int $call_number
   *   The number of emails that should be sent.
   * @param int $role_number
   *   The number of roles (1 or 2).
   *
   * @dataProvider providerSendMailToRole
   *
   * @covers ::execute
   */
  public function testSendMailToRole($call_number, $role_number) {
    // Unfortunately providerSendMailToRole() runs before setUp() so we can't
    // set these things up there.
    // Sending mail to one role.
    if ($role_number == 1) {
      $user = reset($this->users);
      $roles = [$this->roles[0]];
      $users = [$user->id() => $user];
      $this->helperSendMailToRole($call_number, $roles, $users);
    }
    // Sending mail to two roles.
    elseif ($role_number == 2) {
      $roles = $this->roles;
      $users = $this->users;
      $this->helperSendMailToRole($call_number, $roles, $users);
    }
  }

  /**
   * Helper function for testSendMailToRole().
   *
   * @param string $call_number
   *   The number of emails that should be sent.
   * @param \Drupal\user\Entity\Role[] $roles
   *   The array of Role objects to send the email to.
   * @param \Drupal\user\Entity\User[] $users
   *   The array of users that should get this email.
   *
   */
  private function helperSendMailToRole($call_number, $roles, $users) {
    $rids = [];
    foreach ($roles as $role) {
      $rids[] = $role->id();
    }
    $this->action->setContextValue('roles', $roles)
      ->setContextValue('subject', 'subject')
      ->setContextValue('body', 'hello');

    $langcode = reset($users)->getPreferredLangcode();
    $params = [
      'subject' => $this->action->getContextValue('subject'),
      'body' => $this->action->getContextValue('body'),
    ];

    $this->userStorage
      ->loadByProperties(['roles' => $roles])
      ->willReturn($users)
      ->shouldBeCalledTimes(1);

    foreach ($users as $user) {
      $this->mailManager->mail('rules', $this->action->getPluginId(), $user->getEmail(), $langcode, $params)
        ->willReturn(['result' => ($call_number == 'once') ? TRUE : FALSE])
        ->shouldBeCalledTimes(1);

      $this->logger->notice(SafeMarkup::format('Successfully sent email to the role(s) %roles.', ['%roles' => implode(', ', $rids)]))
        ->shouldBeCalledTimes($call_number);

    }
    $this->action->execute();
  }

  /**
   * Data provider for self::testSendMailToRole().
   */
  public function providerSendMailToRole() {
    // Test sending one or zero email to one or two roles.
    return [
      ['once', 1],
      ['never', 1],
      ['once', 2],
      ['never', 2],
    ];
  }
}
