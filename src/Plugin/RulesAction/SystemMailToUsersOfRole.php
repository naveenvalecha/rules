<?php

/**
 * @file
 * Contains \Drupal\rules\Plugin\RulesAction\SystemMailToUsersOfRole.
 */

namespace Drupal\rules\Plugin\RulesAction;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\rules\Core\RulesActionBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\user\UserStorage;

/**
 * Provides a 'Mail to users of a role' action.
 *
 * @RulesAction(
 *   id = "rules_mail_to_users_of_role",
 *   label = @Translation("Mail to users of a role"),
 *   category = @Translation("System"),
 *   context = {
 *     "roles" = @ContextDefinition("entity:user_role",
 *       label = @Translation("Roles"),
 *       description = @Translation("The roles to which to send the e-mail."),
 *       multiple = TRUE
 *     ),
 *     "subject" = @ContextDefinition("string",
 *       label = @Translation("Subject"),
 *       description = @Translation("The subject of the e-mail."),
 *     ),
 *     "body" = @ContextDefinition("string",
 *       label = @Translation("Body"),
 *       description = @Translation("The body of the e-mail."),
 *     ),
 *     "from" = @ContextDefinition("email",
 *       label = @Translation("From"),
 *       description = @Translation("The from e-mail address."),
 *       required = FALSE
 *     )
 *   }
 * )
 *
 * @todo: Add access callback information from Drupal 7.
 */
class SystemMailToUsersOfRole extends RulesActionBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Drupal\user\UserStorage
   */
  protected $userStorage;

  /**
   * Constructs a SendMailToUsersOfRole object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   The storage service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Config\ImmutableConfig $site_config
   *   The site configuration.
   * @param \Drupal\user\UserStorage $userStorage
   *   The user storage service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, MailManagerInterface $mail_manager, ImmutableConfig $site_config, UserStorage $userStorage) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->mailManager = $mail_manager;
    $this->config = $site_config;
    $this->userStorage = $userStorage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('rules'),
      $container->get('plugin.manager.mail'),
      \Drupal::config('system.site'),
      $container->get('entity.manager')->getStorage('user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $roles = $this->getContextValue('roles');

    // Get the role ids and remove the empty ones (in case for example the role
    // has been removed in the meantime).
    $rids = array_filter(array_map(function ($role) {
      return $role->id();
    }, $roles));
    if (empty($rids)) {
      return;
    }

    // Get now all the users that match the roles (at least one of the role).
    $accounts = $this->userStorage
      ->loadByProperties(['roles' => $roles]);
    $params = array(
      'subject' => $this->getContextValue('subject'),
      'body' => $this->getContextValue('body'),
    );
    $from = $this->getContextValue('from');
    if (empty($from)) {
      $from = $this->config->get('mail');
    }
    $key = 'rules_action_mail_to_users_of_role_' . $this->getPluginId();

    foreach ($accounts as $account) {
      $message = $this->mailManager->mail('rules', $key, $account->getEmail(), $account->getPreferredLangcode(), $params, $from);
      // If $message['result'] is FALSE, then it's likely that email sending is
      // failing at the moment, and we should just abort sending any more. If
      // however, $message['result'] is NULL, then it's likely that a module has
      // aborted sending this particular email to this particular user, and we
      // should just keep on sending emails to the other users.
      if ($message['result'] === FALSE) {
        break;
      }

    }
    if ($message['result'] !== FALSE) {
      $this->logger->notice($this->t('Successfully sent email to the role(s) %roles.', array('%roles' => implode(', ', $rids))));
    }
  }
}
