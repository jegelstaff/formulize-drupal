<?php

declare(strict_types=1);

namespace Drupal\formulize\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\formulize\FormulizeUserSync;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Keeps Formulize's users and groups in step with Drupal's.
 */
class UserSyncHooks {

  public function __construct(
    private readonly FormulizeUserSync $sync,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for user entities.
   */
  #[Hook('user_insert')]
  public function userInsert(UserInterface $account): void {
    $this->sync->createUser($account);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for user entities.
   */
  #[Hook('user_update')]
  public function userUpdate(UserInterface $account): void {
    $previous = $account->original instanceof UserInterface ? $account->original->getRoles() : [];
    $this->sync->updateUser($account, $previous);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for user entities.
   */
  #[Hook('user_delete')]
  public function userDelete(UserInterface $account): void {
    $this->sync->deleteUser($account);
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for user_role entities.
   */
  #[Hook('user_role_insert')]
  public function roleInsert(RoleInterface $role): void {
    $this->sync->createGroup($role);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for user_role entities.
   */
  #[Hook('user_role_update')]
  public function roleUpdate(RoleInterface $role): void {
    $this->sync->renameGroup($role);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for user_role entities.
   */
  #[Hook('user_role_delete')]
  public function roleDelete(RoleInterface $role): void {
    $this->sync->deleteGroup($role);
  }

}
