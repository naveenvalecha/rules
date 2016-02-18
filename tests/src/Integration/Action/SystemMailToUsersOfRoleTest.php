<?php

/**
 * @file
 * Contains \Drupal\Tests\rules\Integration\Action\SystemMailToUsersOfRoleTest.
 */

namespace Drupal\Tests\rules\Integration\Action;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\rules\Integration\RulesEntityIntegrationTestBase;
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
   * A mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\Prophecy\Prophecy\ProphecyInterface
   */
  protected $logger;

  /**
   * A mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\Prophecy\Prophecy\ProphecyInterface
   */
  protected $mailManager;

  /**
   * A mocked user storage.
   *
   * @var \Drupal\user\UserStorageInterface|\Prophecy\Prophecy\ProphecyInterface
   */
  protected $userStorage;

  /**
   * Roles to send emails.
   *
   * @var \Drupal\user\Entity\Role[]
   */
  protected $roles;

  /**
   * User to send emails.
   *
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
    $test_email = 'admin@example.com';

    // Create two user roles and one user for each.
    $uid = 1;
    foreach (['administrator', 'editor'] as $role_name) {
      // Mock the role entity.
      $role_mock = $this->prophesize(RoleInterface::class);
      $role_mock->id()
        ->willReturn($role_name);
      $this->roles[] = $role_mock->reveal();

      // Mock the user entity.
      $user_mock = $this->prophesizeEntity(UserInterface::class);
      $user_mock->id()->willReturn($uid);

      $user_entity_type = $this->prophesize(EntityTypeInterface::class);
      $user_mock->getEntityType()
        ->willReturn($user_entity_type->reveal());

      $user_mock->getPreferredLangcode()
        ->willReturn(LanguageInterface::LANGCODE_SITE_DEFAULT);

      $user_mock->getEmail()
        ->willReturn($test_email);

      $this->users[$uid] = $user_mock->reveal();
      $uid++;
    }

    $this->logger = $this->prophesize(LoggerInterface::class);
    $logger_factory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $logger_factory->get('rules')->willReturn($this->logger->reveal());

    $this->mailManager = $this->prophesize(MailManagerInterface::class);
    $this->userStorage = $this->prophesize(UserStorageInterface::class);

    $this->entityTypeManager->getStorage('user')
      ->willReturn($this->userStorage->reveal());

    $this->entityTypeManager->createInstance()
      ->willReturn($this->userStorage->reveal());

    // Prepare a user entity type instance.
    $entity_type = reset($this->users)->getEntityType();
    $this->entityTypeManager->getDefinitions()
      ->willReturn($entity_type);

    // @todo this is wrong, the logger is no factory.
    $this->container->set('logger.factory', $logger_factory->reveal());
    $this->container->set('plugin.manager.mail', $this->mailManager->reveal());
    $config = [
      'system.site' => [
        'mail' => $test_email,
      ],
    ];
    $this->container->set('config.factory', $this->getConfigFactoryStub($config));

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
   * @param int $email_send_count
   *   The number of emails that should be sent.
   * @param \Drupal\user\Entity\Role[] $roles
   *   The roles to use.
   *
   * @dataProvider providerSendMailToRole
   *
   * @covers ::execute
   */
  public function testSendMailToRole($email_send_count, $roles = NULL) {
    // Unfortunately providerSendMailToRole() runs before setUp() so we can't
    // set these things up there.
    // If $roles isn't set, then get all roles and all users.
    if (empty($roles)) {
      $roles = $this->roles;
      $users = $this->users;
    }
    // Otherwise, get the number of roles specified and get the same number of
    // users.
    else {
      $users = array_slice($this->users, 0, $roles);
      $roles = array_slice($this->roles, 0, $roles);
    }

    $this->userStorage
      ->loadByProperties(['roles' => $roles])
      ->willReturn($users)
      ->shouldBeCalledTimes(1);

    $params = [
      'subject' => 'subject',
      'body' => 'hello',
    ];

    $langcode = reset($users)->getPreferredLangcode();

    $rids = [];
    foreach ($roles as $role) {
      $rids[] = $role->id();
    }

    $sitemail = $this->container
      ->get('config.factory')
      ->get('system.site')
      ->get('mail');

    foreach ($users as $user) {
      $this->mailManager->mail('rules', "rules_action_mail_to_users_of_role_{$this->action->getPluginId()}", $user->getEmail(), $langcode, $params, $sitemail)
        ->willReturn(['result' => ($email_send_count == 'once') ? TRUE : FALSE])
        ->shouldBeCalledTimes(($email_send_count == 'once') ? count($users) : 1);

      $this->logger->notice('Successfully sent email to the role(s) %roles.', ['%roles' => implode(', ', $rids)])
        ->shouldBeCalledTimes(($email_send_count == 'once') ? 1 : 0);
    }

    $this->action
      ->setContextValue('roles', $roles)
      ->setContextValue('subject', $params['subject'])
      ->setContextValue('body', $params['body'])
      ->execute();
  }

  /**
   * Data provider for self::testSendMailToRole().
   */
  public function providerSendMailToRole() {
    // Create mock roles here instead of in setUp because this runs before
    // setUp.
    foreach (['administrator', 'editor'] as $role_name) {
      // Mock the role entity.
      $role_mock = $this->prophesize(RoleInterface::class);
      $role_mock->id()
        ->willReturn($role_name);
    }
    // Test sending one or zero email to one or two roles.
    return [
      ['once', 1],
      ['never', 1],
      ['once'],
      ['never'],
    ];
  }

}
