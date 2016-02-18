<?php

/**
 * @file
 * Contains Drupal\rules\Entity\ReactionRuleConfig.
 */

namespace Drupal\rules\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\rules\Engine\ExpressionInterface;
use Drupal\rules\Engine\RulesComponent;

/**
 * Reaction rule configuration entity to persistently store configuration.
 *
 * @ConfigEntityType(
 *   id = "rules_reaction_rule",
 *   label = @Translation("Reaction Rule"),
 *   handlers = {
 *     "storage" = "Drupal\rules\Entity\ReactionRuleStorage",
 *     "list_builder" = "Drupal\rules\Controller\RulesReactionListBuilder",
 *     "form" = {
 *        "add" = "\Drupal\rules\Form\ReactionRuleAddForm",
 *        "edit" = "\Drupal\rules\Form\ReactionRuleEditForm",
 *        "delete" = "\Drupal\Core\Entity\EntityDeleteForm"
 *      }
 *   },
 *   admin_permission = "administer rules",
 *   config_prefix = "reaction",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "event",
 *     "module",
 *     "description",
 *     "tag",
 *     "core",
 *     "expression_id",
 *     "configuration",
 *   },
 *   links = {
 *     "collection" = "/admin/config/workflow/rules",
 *     "edit-form" = "/admin/config/workflow/rules/reactions/edit/{rules_reaction_rule}",
 *     "delete-form" = "/admin/config/workflow/rules/reactions/delete/{rules_reaction_rule}",
 *     "break-lock-form" = "/admin/config/workflow/rules/reactions/edit/break-lock/{rules_reaction_rule}"
 *   }
 * )
 */
class ReactionRuleConfig extends ConfigEntityBase {

  /**
   * The unique ID of the Reaction Rule.
   *
   * @var string
   */
  public $id = NULL;

  /**
   * The label of the Reaction rule.
   *
   * @var string
   */
  protected $label;

  /**
   * The description of the rule, which is used only in the interface.
   *
   * @var string
   */
  protected $description = '';

  /**
   * The "tags" of a Reaction rule.
   *
   * The tags are stored as a single string, though it is used as multiple tags
   * for example in the rules overview.
   *
   * @var string
   */
  protected $tag = '';

  /**
   * The core version the Reaction rule was created for.
   *
   * @var int
   */
  protected $core = \Drupal::CORE_COMPATIBILITY;

  /**
   * The Rules expression plugin ID that the configuration is for.
   *
   * @var string
   */
  protected $expression_id = 'rules_rule';

  /**
   * The expression plugin specific configuration as nested array.
   *
   * @var array
   */
  protected $configuration = [];

  /**
   * Stores a reference to the executable expression version of this component.
   *
   * @var \Drupal\rules\Engine\ExpressionInterface
   */
  protected $expression;

  /**
   * The module implementing this Reaction rule.
   *
   * @var string
   */
  protected $module = 'rules';

  /**
   * The event name this reaction rule is reacting on.
   *
   * @var string
   */
  protected $event;

  /**
   * Sets a Rules expression instance for this Reaction rule.
   *
   * @param \Drupal\rules\Engine\ExpressionInterface $expression
   *   The expression to set.
   *
   * @return $this
   */
  public function setExpression(ExpressionInterface $expression) {
    $this->expression = $expression;
    $this->expression_id = $expression->getPluginId();
    $this->configuration = $expression->getConfiguration();
    return $this;
  }

  /**
   * Gets a Rules expression instance for this Reaction rule.
   *
   * @return \Drupal\rules\Engine\ExpressionInterface
   *   A Rules expression instance.
   */
  public function getExpression() {
    // Ensure that an executable Rules expression is available.
    if (!isset($this->expression)) {
      $this->expression = $this->getExpressionManager()->createInstance($this->expression_id, $this->configuration);
      $this->expression->setConfigEntityId($this->id());
    }

    return $this->expression;
  }

  /**
   * Gets the Rules component that is invoked when the events are dispatched.
   *
   * The returned component has the definitions of the available event context
   * set.
   *
   * @return \Drupal\rules\Engine\RulesComponent
   *   The Rules component.
   */
  public function getComponent() {
    $component = RulesComponent::create($this->getExpression());
    $component->addContextDefinitionsForEvents([$this->getEvent()]);
    return $component;
  }

  /**
   * Updates the configuration based upon the given component.
   *
   * @param \Drupal\rules\Engine\RulesComponent $component
   *   The component containing the configuration to set.
   *
   * @return $this
   */
  public function updateFromComponent(RulesComponent $component) {
    // Note that the available context definitions stem from the configured
    // events, which are handled separately.
    $this->setExpression($component->getExpression());
    return $this;
  }

  /**
   * Returns the Rules expression manager.
   *
   * @todo Actually we should use dependency injection here, but is that even
   *   possible with config entities? How?
   *
   * @return \Drupal\rules\Engine\ExpressionManager
   *   The Rules expression manager.
   */
  protected function getExpressionManager() {
    return \Drupal::service('plugin.manager.rules_expression');
  }

  /**
   * {@inheritdoc}
   */
  public function createDuplicate() {
    $duplicate = parent::createDuplicate();
    unset($duplicate->expression);
    return $duplicate;
  }

  /**
   * Overrides \Drupal\Core\Entity\Entity::label().
   *
   * When a certain component does not have a label return the ID.
   */
  public function label() {
    if (!$label = $this->get('label')) {
      $label = $this->id();
    }
    return $label;
  }

  /**
   * Returns the description.
   */
  public function getDescription() {
    return $this->description;
  }

  /**
   * Returns the tag.
   */
  public function getTag() {
    return $this->tag;
  }

  /**
   * Returns the event on which this rule will trigger.
   */
  public function getEvent() {
    return $this->event;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    // Ensure that the Reaction rule is dependent on the module that
    // implements the component.
    $this->addDependency('module', $this->module);

    // @todo Handle dependencies of plugins that are provided by various modules
    //   here.
    return $this->dependencies;
  }

  /**
   * Magic clone method.
   */
  public function __clone() {
    // Remove the reference to the expression object in the clone so that the
    // expression object tree is created from scratch.
    unset($this->expression);
  }

}
